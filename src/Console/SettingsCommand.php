<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Bridge\BridgeSetup;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\EnvFile;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Components\Alert;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Feed;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesSettings;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\Settings;
use Illuminate\Console\Command;
use Throwable;

class SettingsCommand extends Command implements ProvidesSettings
{
    public const string SIGNATURE = 'studio:settings';

    private const string URL = 'ARTISAN_STUDIO_URL';

    private const string TOKEN = 'ARTISAN_STUDIO_TOKEN';

    private const string PROJECT = 'ARTISAN_STUDIO_PROJECT';

    private const string EDITOR = 'ARTISAN_STUDIO_EDITOR';

    protected $aliases = ['studio:link'];

    protected $signature = self::SIGNATURE.'
        {--token= : Link with this token, without opening the studio}
        {--url= : Another studio entirely — ours, working on Artisan Studio itself}';

    protected $description = 'Link this project to Artisan Studio with its own token, and the rest of your settings';

    /**
     * @var list<array{slug: string, name: string, repo: string|null}>|null
     */
    private ?array $projects = null;

    private ?string $problem = null;

    private string $connection = Studio::UNKNOWN;

    public function handle(): int
    {
        $url = $this->stringOption('url');

        if ($url !== '') {
            config()->set('studio-cli.url', rtrim($url, '/'));
        }

        return match (true) {
            $this->stringOption('token') !== '' => $this->linkFromTheFlags(),
            default => $this->call(StudioCommand::SIGNATURE, ['tab' => ScreenContainer::SETTINGS]),
        };
    }

    public function settings(Settings $settings): Settings
    {
        return $settings->label('Studio')
            ->state(fn (): array => $this->whereThisCheckoutStands())
            ->components([
                Alert::make(fn (array $state): string => $state['linked'] ? '' : __('studio-cli::welcome.token_title'))
                    ->description(fn (array $state): string => $state['linked'] ? '' : __('studio-cli::welcome.token'))
                    ->colour('cyan')
                    ->descriptionColour('soft'),
                Text::make('')
                    ->wrap()
                    ->url(fn (array $state): ?string => $state['linked'] ? null : app(Studio::class)->signInUrl()),
                Alert::make(fn (array $state): string => $state['asking'] ? app(BridgeSetup::class)->questionLines()['question'] : '')
                    ->description(fn (array $state): string => $state['asking'] ? (string) app(BridgeSetup::class)->questionLines()['account'] : '')
                    ->colour('amber'),
                ...$this->standing(below: false),
            ])
            ->belowActions($this->standing(below: true))
            ->actions([
                Action::make('allow')
                    ->label(__('studio-cli::bridge.consent.yes'))
                    ->visible(fn (array $state): bool => $state['asking'])
                    ->action(fn (): string => app(BridgeSetup::class)->choose(BridgeSetup::YES)),
                Action::make('keep-off')
                    ->label(__('studio-cli::bridge.consent.no'))
                    ->visible(fn (array $state): bool => $state['asking'])
                    ->action(fn (): string => app(BridgeSetup::class)->choose(BridgeSetup::NO)),
                Action::make('link')
                    ->label('Link with a token')
                    ->visible(fn (array $state): bool => ! $state['linked'])
                    ->asks(__('studio-cli::welcome.paste'), secret: true)
                    ->action(fn (string $token): ?string => $this->linkWith($token)),
                Action::make('relink')
                    ->label('Link with a new token')
                    ->visible(fn (array $state): bool => $state['linked'])
                    ->asks(__('studio-cli::welcome.paste'), secret: true)
                    ->action(fn (string $token): ?string => $this->linkWith($token)),
                Action::make('editor')
                    ->label('Choose your editor')
                    ->options(fn (array $state): array => $state['editors'])
                    ->action(fn (string $editor): string => $this->useEditor($editor)),
                Action::make('unlink')
                    ->label('Unlink this project')
                    ->visible(fn (array $state): bool => $state['linked'])
                    ->requiresConfirmation('This takes the token and project out of your .env.')
                    ->action(fn (): string => $this->unlink()),
                Action::make('bridge')
                    ->label(__('studio-cli::bridge.consent.label'))
                    ->visible(fn (array $state): bool => $state['linked'] && ! $state['asking'])
                    ->question(fn (): string => app(BridgeSetup::class)->question())
                    ->options(fn (): array => app(BridgeSetup::class)->choices())
                    ->action(fn (string $choice): string => app(BridgeSetup::class)->choose($choice)),
            ]);
    }

    /**
     * ★ WHILE THE BRIDGE QUESTION IS OPEN IT IS THE FIRST THING ON THE SCREEN. It used to sit under the
     * menu, out of view in a short terminal, so a CLI waiting for an answer looked like one that was
     * not. While it is open the question is a marked block at the top with the two answers right under
     * it, and what the checkout stands on (the project, the token, the bridge's state) moves under the
     * menu. Once it is answered the block and the answers go and the screen is as it was. Unlinked it is
     * the same: the sign-in link comes first and "Link with a token" right under it, so both are on the
     * screen at once, and the rows move under the menu.
     *
     * @return list<Feed|Text>
     */
    private function standing(bool $below): array
    {
        $shown = fn (mixed $state): bool => is_array($state) && (($state['asking'] ?? false) || ! ($state['linked'] ?? true)) === $below;

        return [
            Feed::make(fn (array $state): array => $shown($state) ? $state['rows'] : [])
                ->columns([
                    Column::make('label')->width(10)->colour('dim'),
                    Column::make('value')->colour('soft'),
                    Column::make('status')
                        ->width(14)
                        ->colour(fn (array $row): string => $row['colour'])
                        ->formatStateUsing(fn (string $status): string => $status === '' ? '' : "● {$status}"),
                ]),
            Text::make(fn (array $state): string => $shown($state) ? (string) $state['problem'] : '')->colour('rose')->wrap(),
            Text::make(fn (array $state): string => $shown($state) ? (string) $state['bridge'] : '')->colour('soft')->wrap(),
            ...app(BridgeSetup::class)->blockTexts(fn (mixed $state): bool => $shown($state) && ($state['linked'] ?? false) === true),
        ];
    }

    /**
     * @return array{linked: bool, asking: bool, rows: list<array{label: string, value: string, status: string, colour: string}>, problem: ?string, bridge: string, editors: array<string, string>}
     */
    private function whereThisCheckoutStands(): array
    {
        $linked = app(Studio::class)->isLinked();
        $this->projects = $linked && ($this->projects ?? []) === [] ? $this->projectsFor(null) : $this->projects;
        $current = (string) config('studio-cli.project', '');
        $editor = app(Editor::class);

        return [
            'linked' => $linked,
            'asking' => $linked && app(BridgeSetup::class)->isAsking(),
            'rows' => [
                ...($linked ? $this->linkedRows($current) : $this->unlinkedRows()),
                ...($linked ? [app(BridgeSetup::class)->row()] : []),
                ['label' => 'Editor', 'value' => $editor->describe(), 'status' => '', 'colour' => 'dim'],
            ],
            'problem' => $this->problem,
            'bridge' => $linked ? app(BridgeSetup::class)->explain() : '',
            'editors' => collect(Editor::CHOICES)
                ->mapWithKeys(fn (string $label, string $key): array => [$key => ($key === $editor->chosen() ? '● ' : '  ').$label])
                ->all(),
        ];
    }

    private function useEditor(string $editor): ?string
    {
        if (! isset(Editor::CHOICES[$editor])) {
            return null;
        }

        app(EnvFile::class)->write([self::EDITOR => $editor]);
        config()->set('studio-cli.review.editor', $editor);

        return $editor === Editor::NONE
            ? 'Reviews list the files for you to open.'
            : 'Reviews open their files in '.Editor::CHOICES[$editor].'.';
    }

    /**
     * @return list<array{label: string, value: string, status: string, colour: string}>
     */
    private function linkedRows(string $current): array
    {
        $project = collect($this->projects ?? [])->firstWhere('slug', $current);
        $token = (string) config('studio-cli.token');

        [$projectStatus, $projectColour] = match (true) {
            $this->connection !== Studio::CONNECTED => ['not checked', 'dim'],
            $project === null => ['not found', 'rose'],
            default => ['linked', 'green'],
        };

        [$tokenStatus, $tokenColour] = match ($this->connection) {
            Studio::CONNECTED => ['accepted', 'green'],
            Studio::REFUSED => ['refused', 'rose'],
            default => ['not checked', 'dim'],
        };

        return [
            ['label' => 'Project', 'value' => $project === null ? $current : $this->describe($project), 'status' => $projectStatus, 'colour' => $projectColour],
            ['label' => 'Studio', 'value' => (string) config('studio-cli.url'), 'status' => $this->connection === Studio::UNREACHABLE ? 'unreachable' : '', 'colour' => 'rose'],
            ['label' => 'Token', 'value' => str_repeat('•', 8).mb_substr($token, -4), 'status' => $tokenStatus, 'colour' => $tokenColour],
        ];
    }

    /**
     * @return list<array{label: string, value: string, status: string, colour: string}>
     */
    private function unlinkedRows(): array
    {
        return [
            ['label' => 'Project', 'value' => 'This project is not linked yet', 'status' => 'not linked', 'colour' => 'amber'],
            ['label' => 'Studio', 'value' => (string) config('studio-cli.url'), 'status' => '', 'colour' => 'dim'],
        ];
    }

    private function linkFromTheFlags(): int
    {
        $token = $this->stringOption('token');
        $projects = $this->projectsFor($token);

        if ($projects === []) {
            $this->components->error((string) $this->problem);

            return self::FAILURE;
        }

        $this->components->info($this->link($token, $projects[0]['slug'], $projects));
        $this->components->bulletList(['Run `php artisan studio` to follow your scan, insights and activity.']);

        return self::SUCCESS;
    }

    /**
     * A token is made for one project, so the project it lists is the one this
     * checkout is.
     */
    private function linkWith(string $token): ?string
    {
        $projects = $this->projectsFor($token);
        $project = $projects[0]['slug'] ?? null;

        return $project === null ? null : $this->link($token, $project, $projects);
    }

    /**
     * @param  list<array{slug: string, name: string, repo: string|null}>  $projects
     */
    private function link(string $token, string $project, array $projects): string
    {
        app(EnvFile::class)->write([self::URL => (string) config('studio-cli.url'), self::TOKEN => $token, self::PROJECT => $project]);
        config()->set(['studio-cli.token' => $token, 'studio-cli.project' => $project]);
        $this->projects = $projects;
        $this->problem = null;
        $this->connection = Studio::CONNECTED;
        app(SnapshotSource::class)->forget();

        return 'Linked to '.$this->named($project).'.';
    }

    private function unlink(): string
    {
        app(EnvFile::class)->forget([self::TOKEN, self::PROJECT]);
        config()->set(['studio-cli.token' => null, 'studio-cli.project' => null]);
        $this->projects = null;
        $this->problem = null;

        return 'Unlinked this project.';
    }

    /**
     * @return list<array{slug: string, name: string, repo: string|null}>
     */
    private function projectsFor(?string $token): array
    {
        $studio = $token === null ? app(Studio::class) : app(Studio::class)->withToken($token);

        try {
            $projects = $studio->projects();
        } catch (Throwable $refused) {
            $this->problem = $refused->getMessage();

            return [];
        } finally {
            $this->connection = $token === null ? $studio->connection() : $this->connection;
        }

        $this->problem = $projects === [] ? 'That token works, but there are no projects on it.' : null;

        return $projects;
    }

    /**
     * @param  array{slug: string, name: string, repo: string|null}  $project
     */
    private function describe(array $project): string
    {
        return filled($project['repo'] ?? null) ? $project['name'].'  ('.$project['repo'].')' : $project['name'];
    }

    private function named(string $project): string
    {
        $found = collect($this->projects ?? [])->firstWhere('slug', $project);

        return $found === null ? $project : $found['name'];
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }
}
