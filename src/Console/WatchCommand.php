<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\Concerns\InteractsWithAvatar;
use ArtisanStudio\StudioCli\Concerns\ListensToStudio;
use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Fix\FixLauncher;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\StatusColumn;
use ArtisanStudio\StudioCli\Terminal\Components\Feed;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesRail;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;
use ArtisanStudio\StudioCli\Terminal\Rail;
use ArtisanStudio\StudioCli\Terminal\Tab;
use ArtisanStudio\StudioCli\Work\WorkLauncher;
use Illuminate\Console\Command;
use Throwable;

class WatchCommand extends Command implements ProvidesRail, ProvidesTab, RunsInBackground
{
    use InteractsWithAvatar;
    use ListensToStudio;
    use OpensInStudio;

    public const string SIGNATURE = 'studio:watch';

    private const array MILESTONES = ['blueprint', 'conventions', 'tools', 'phpstan', 'tests', FixLauncher::TASK];

    private const array INSIGHTS = ['scan', 'insight', 'convention', 'blueprint', 'conventions', 'tools', 'phpstan', 'tests', FixLauncher::TASK];

    private const string NO_MILESTONES = 'Your scans and {name}\'s fixes show up here as they happen.';

    private const string NO_INSIGHTS = 'Scans and new insights show up here as they happen.';

    private const string NO_BUILD = 'Each file {name} writes, checks and commits shows up here during the build.';

    protected $signature = self::SIGNATURE;

    protected $description = 'Open Artisan Studio on the Activity tab, following each scan and fix as it happens';

    private ?string $problem = null;

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Activity')
            ->state(fn (): array => $this->activity('milestones'))
            ->components([
                Section::make(fn (array $state): string => $state['heading'])
                    ->aside(fn (array $state): string => $state['problem'] === null ? $state['connection'] : '')
                    ->components([
                        Table::make(fn (array $state): array => $state['events'])
                            ->emptyState(fn (array $state): string => $state['problem'] ?? $state['empty'])
                            ->columns([
                                Column::make('when')->width(10)->colour('dim'),
                                Column::make('agent')->width(10)->colour('sky'),
                                StatusColumn::make('label')
                                    ->label('What happened')
                                    ->width(fn (int $width): int => min(24, intdiv($width, 4)))
                                    ->colour(fn (array $event): string => $event['colour']),
                                Column::make('detail')->colour('soft'),
                            ]),
                    ]),
            ]);
    }

    public function rail(Rail $rail): Rail
    {
        return $rail
            ->state(fn (mixed $tab = null): array => [...$this->activity($this->sectionFor($tab instanceof Tab ? $tab : null)), 'reachable' => $this->studioIsReachable()])
            ->components([
                Section::make(fn (array $state): string => $state['heading'])
                    ->aside(fn (array $state): string => $state['reachable'] ? $state['connection'] : '')
                    ->components([
                        Text::make(fn (array $state): string => $state['reachable'] ? '' : ucfirst($state['connection']))
                            ->colour('amber')
                            ->wrap(),
                        Feed::make(fn (array $state): array => $state['events'])
                            ->emptyState(fn (array $state): string => $state['problem'] ?? $state['empty'])
                            ->columns([
                                Column::make('when')->width(9)->colour('dim'),
                                Column::make('agent')->width(9)->colour('sky'),
                                StatusColumn::make('label')->colour(fn (array $event): string => $event['colour']),
                            ])
                            ->detail('detail'),
                    ]),
            ]);
    }

    private function sectionFor(?Tab $tab): string
    {
        return match (true) {
            $tab?->getKey() === 'insights' => 'insights',
            $tab?->getKey() === 'build' => 'build',
            default => 'milestones',
        };
    }

    /**
     * @return array{events: list<array{when: string, agent: string, label: string, detail: string, colour: string, kind: string, key: ?string}>, problem: ?string, connection: string, heading: string, empty: string}
     */
    private function activity(string $section): array
    {
        $log = app(ActivityLog::class);

        [$heading, $events, $empty] = match ($section) {
            'insights' => ['Insights', $log->ofKinds(self::INSIGHTS), self::NO_INSIGHTS],
            'build' => ['Build', $log->ofKinds([WorkLauncher::TASK]), Says::named(self::NO_BUILD)],
            default => ['Activity', $log->ofKinds(self::MILESTONES), Says::named(self::NO_MILESTONES)],
        };

        return ['events' => $events, 'problem' => $this->problem, 'connection' => $this->studioConnection(), 'heading' => $heading, 'empty' => $empty];
    }

    public function start(): void
    {
        $studio = app(Studio::class);
        $this->problem = $this->whyItCannotWatch($studio);

        if ($this->problem !== null) {
            return;
        }

        $this->avatarArrives();
        $this->startListening($studio, fn (array $event) => $this->heard($event));
    }

    public function tick(): void
    {
        $this->keepListening();
    }

    public function stop(): void
    {
        $this->stopListening();
        $this->avatarLeaves();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function heard(array $event): void
    {
        if (($event['kind'] ?? null) === 'tick') {
            $this->avatarSettles();

            return;
        }

        event(new StudioReported($event));

        if (($event['type'] ?? null) !== 'changed' && ($event['history'] ?? false) !== true) {
            $this->avatarReacts($event);
        }
    }

    private function whyItCannotWatch(Studio $studio): ?string
    {
        if (! $studio->isLinked()) {
            return "Waiting for this project's token.\nOnce it is in, everything done here shows up in this feed.";
        }

        try {
            $studio->projects();
        } catch (Throwable $refusal) {
            return $studio->connection() === Studio::REFUSED
                ? "Waiting for a fresh token.\nOnce it is in, everything done here shows up in this feed."
                : $refusal->getMessage();
        }

        return (new LocalBranch(base_path()))->isRepository()
            ? null
            : 'This is not a git repository, so there is no branch for a build to follow.';
    }
}
