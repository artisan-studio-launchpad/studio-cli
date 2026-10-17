<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\EnvFile;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Action;
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

    private const string ASK_FOR_A_TOKEN = "Paste this project's token. ".NotConnected::WHERE_THE_TOKEN_IS.' It is shown once.';

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
                Feed::make(fn (array $state): array => $state['rows'])
                    ->columns([
                        Column::make('label')->width(10)->colour('dim'),
                        Column::make('value')->colour('soft'),
                        Column::make('status')
                            ->width(14)
                            ->colour(fn (array $row): string => $row['colour'])
                            ->formatStateUsing(fn (string $status): string => $status === '' ? '' : "● {$status}"),
                    ]),
                Text::make(fn (array $state): string => (string) $state['problem'])->colour('rose')->wrap(),
            ])
            ->actions([
                Action::make('link')
                    ->label('Link with a token')
                    ->visible(fn (array $state): bool => ! $state['linked'])
                    ->asks(self::ASK_FOR_A_TOKEN, secret: true)
                    ->action(fn (string $token): ?string => $this->linkWith($token)),
                Action::make('relink')
                    ->label('Link with a new token')
                    ->visible(fn (array $state): bool => $state['linked'])
                    ->asks(self::ASK_FOR_A_TOKEN, secret: true)
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
            ]);
    }

    /**
     * @return array{linked: bool, rows: list<array{label: string, value: string, status: string, colour: string}>, problem: ?string, editors: array<string, string>}
     */
    private function whereThisCheckoutStands(): array
    {
        $linked = app(Studio::class)->isLinked();
        $this->projects = $linked && ($this->projects ?? []) === [] ? $this->projectsFor(null) : $this->projects;
        $current = (string) config('studio-cli.project', '');
        $editor = app(Editor::class);

        return [
            'linked' => $linked,
            'rows' => [
                ...($linked ? $this->linkedRows($current) : $this->unlinkedRows()),
                ['label' => 'Editor', 'value' => $editor->describe(), 'status' => '', 'colour' => 'dim'],
            ],
            'problem' => $this->problem,
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
