<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\FreshSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SampleSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\Fix\FixLauncher;
use ArtisanStudio\StudioCli\Fix\FixPanel;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\LocalTime;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Concerns\InteractsWithScreen;
use ArtisanStudio\StudioCli\Terminal\Contracts\HasScreen;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Terminal\StudioTabs;
use ArtisanStudio\StudioCli\Terminal\Tab;
use ArtisanStudio\StudioCli\Work\BuildRun;
use ArtisanStudio\StudioCli\Work\WorkLauncher;
use ArtisanStudio\StudioCli\Work\WorkPanel;
use ArtisanStudio\StudioCli\Work\WorkProgress;
use ArtisanStudio\StudioCli\Work\WorkRun;
use Illuminate\Console\Command;

class StudioCommand extends Command implements HasScreen
{
    use InteractsWithScreen;

    public const string SIGNATURE = 'studio';

    public const int PING_EVERY_SECONDS = 15;

    /**
     * How long after applying agreed changes the same ask counts as new ones,
     * once the studio has heard what landed.
     */
    private const int AGREED_AGAIN_AFTER_SECONDS = 20;

    private const int FILES_AGAIN_AFTER_SECONDS = 10;

    private const int LARGEST_FILE = 300_000;

    private const int MOST_MATCHES = 60;

    private const string SECRET = '~(^|/)(\.env(\..*)?|auth\.json|id_[a-z0-9]+|[^/]+\.(pem|key|p12|pfx))$~i';

    private ?string $repository = null;

    private int $pingedAt = 0;

    private string $fixesAnnounced = '';

    private ?int $workOpened = null;

    private ?string $askedForTheToken = null;

    /**
     * @var list<int>
     */
    private array $startedByItself = [];

    private ?string $fixesStarted = null;

    private string $filesSent = '';

    private int $filesSentAt = 0;

    private ?string $openedForThem = null;

    /**
     * @var array<string, int>
     */
    private array $searchesRun = [];

    /**
     * @var array<int, list<string>>
     */
    private array $theirChanges = [];

    protected $signature = self::SIGNATURE.'
        {tab? : Open on this tab: dashboard, scan, insights, build, activity or settings}
        {--fresh : Show what a developer sees on a brand new project that is not linked yet}
        {--demo : Show made-up data, to see what a busy project looks like}';

    protected $description = 'Your Artisan Studio dashboard, in the terminal';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            config(['studio-cli.token' => null]);
            $this->laravel->instance(SnapshotSource::class, new FreshSnapshots);
        }

        if ($this->option('demo')) {
            $this->laravel->instance(SnapshotSource::class, new SampleSnapshots);
        }

        if (! $this->option('fresh') && ! $this->option('demo')) {
            $this->repository = $this->laravel->make(LocalChanges::class)->originRepository();
            $this->screenTicked();
        }

        $tab = $this->argument('tab');

        try {
            return $this->showScreen(is_string($tab) ? $tab : null);
        } finally {
            $this->screenEnding();
            $this->laravel->make(Studio::class)->ping($this->repository, leaving: true);
        }
    }

    /**
     * The scan this studio started stops with it, whole: a test run left
     * behind keeps a developer's machine busy long after anyone is looking.
     */
    protected function screenEnding(): void
    {
        $this->laravel->make(BackgroundTasks::class)->stopAttached();
    }

    /**
     * When the developer asks the studio to fix their rules, the terminal turns
     * to Insights, where the avatar explains what happens next. Once per ask.
     */
    private function openInsightsWhenFixesAreAsked(): void
    {
        if ($this->repository === null) {
            return;
        }

        $snapshot = $this->laravel->make(SnapshotSource::class)->snapshot();
        $fixesAsked = $snapshot->fixesAsked;
        $asked = implode(',', $fixesAsked).'|'.implode(',', array_map(fn (array $ask): string => $ask['id'].':'.$ask['state'], $snapshot->workAsked));

        if ($asked !== '|' && $asked !== $this->fixesAnnounced && (FixPanel::insights()->fixable($fixesAsked) !== [] || $snapshot->workAsked !== [])) {
            $this->laravel->make(ScreenRequests::class)->show(FixPanel::insights()->fixable($fixesAsked) === [] && WorkPanel::insights()->asks($snapshot) === [] ? 'build' : 'insights');
        }

        $this->fixesAnnounced = $asked;

        if ($snapshot->workRechecks > 0 && ! $this->laravel->make(WorkLauncher::class)->isRunning() && ! $this->laravel->make(BackgroundTasks::class)->isRunning(WorkLauncher::RECHECKS)) {
            $this->laravel->make(WorkLauncher::class)->checkPlaces();
        }

        if ($snapshot->workSends > 0 && ! $this->laravel->make(WorkLauncher::class)->isRunning() && ! $this->laravel->make(BackgroundTasks::class)->isRunning(WorkLauncher::SENDS)) {
            $this->laravel->make(WorkLauncher::class)->send();
        }
    }

    /**
     * A project that is not linked, or whose token the studio no longer knows,
     * opens straight on Settings with the token asked for. Once each, so a
     * developer who steps away from it reads the dashboard's explanation instead.
     */
    private function askForTheTokenWhenNotLinked(): void
    {
        $connection = $this->laravel->make(Studio::class)->connection();

        if ($this->option('demo') || $this->option('once') || $this->option('tab') || $connection === $this->askedForTheToken) {
            return;
        }

        $action = match ($connection) {
            Studio::UNLINKED => 'link',
            Studio::REFUSED, Studio::FORBIDDEN, Studio::MISSING => 'relink',
            default => null,
        };

        if ($action === null) {
            return;
        }

        $this->askedForTheToken = $connection;
        $this->getScreen()->startSetting($action);
    }

    /**
     * What the developer asked for in the app starts here by itself as soon
     * as nothing else runs: the click there is the go-ahead. Changes they
     * agreed go first, then the tests for the fixes, then the next job. Each
     * ask starts once; one that could not start waits for ⏎. A build still
     * starts on ⏎.
     */
    private function startWhatTheDeveloperAgreed(): void
    {
        $snapshot = $this->repository === null ? null : $this->laravel->make(SnapshotSource::class)->snapshot();
        $asked = $snapshot?->workAsked ?? [];
        $this->startedByItself = array_values(array_intersect($this->startedByItself, array_column($asked, 'id')));
        $agreed = collect($asked)
            ->filter(fn (array $ask): bool => $ask['job'] !== BuildRun::JOB && ($ask['mode'] === 'app' || $ask['state'] === 'asked') && $this->isNewAsk($ask))
            ->sortBy(fn (array $ask): array => [match (true) {
                $ask['mode'] === 'app' => 0,
                $ask['job'] === WorkRun::TESTS => 1,
                default => 2,
            }, $ask['id']])
            ->values()
            ->all();
        $launcher = $this->laravel->make(WorkLauncher::class);

        if ($this->laravel->make(FixLauncher::class)->isRunning()) {
            return;
        }

        if ($agreed !== [] && $launcher->canStart($agreed)) {
            $this->startedByItself[] = $agreed[0]['id'];
            $launcher->start($agreed);

            return;
        }

        $this->startTheFixesAsked($snapshot);
    }

    /**
     * The files the app wants to show or read, sent from this
     * machine as they are now: the real codebase, never an older copy on
     * GitHub. A file the repository does not track, a big one or a secret is
     * sent as missing.
     */
    private function sendTheFilesAsked(): void
    {
        $wanted = $this->repository === null ? [] : $this->laravel->make(SnapshotSource::class)->snapshot()->filesWanted;
        $asked = implode("\n", $wanted);

        if ($wanted === [] || ($asked === $this->filesSent && time() - $this->filesSentAt < self::FILES_AGAIN_AFTER_SECONDS)) {
            return;
        }

        $this->filesSent = $asked;
        $this->filesSentAt = time();
        $root = $this->laravel->basePath();
        $tracked = (new LocalBranch($root))->tracked($wanted);

        $this->laravel->make(Studio::class)->submitScanWork(['local' => array_map(fn (string $path): array => [
            'path' => $path,
            'content' => in_array($path, $tracked, true) && preg_match(self::SECRET, $path) !== 1 && is_file($root.'/'.$path) && filesize($root.'/'.$path) <= self::LARGEST_FILE ? (string) file_get_contents($root.'/'.$path) : null,
        ], $wanted)]);
    }

    private function runTheSearchesAsked(): void
    {
        $wanted = $this->repository === null ? [] : $this->laravel->make(SnapshotSource::class)->snapshot()->searchesWanted;
        $new = array_values(array_filter($wanted, fn (array $search): bool => time() - ($this->searchesRun[$search['id']] ?? 0) >= self::FILES_AGAIN_AFTER_SECONDS));

        if ($new === []) {
            return;
        }

        $branch = new LocalBranch($this->laravel->basePath());
        $this->searchesRun = [...array_filter($this->searchesRun, fn (int $at): bool => time() - $at < self::FILES_AGAIN_AFTER_SECONDS), ...array_fill_keys(array_column($new, 'id'), time())];

        $this->laravel->make(Studio::class)->submitScanWork(['searched' => array_map(fn (array $search): array => [
            'id' => $search['id'],
            ...$branch->grep($search['pattern'], $search['in'] !== null && ! str_contains($search['in'], '..') ? $search['in'] : null, self::MOST_MATCHES, self::SECRET),
        ], $new)]);
    }

    private function workTogetherWhenAsked(): void
    {
        $together = $this->repository === null ? null : $this->laravel->make(SnapshotSource::class)->snapshot()->workTogether;

        if ($together === null) {
            return;
        }

        $root = $this->laravel->basePath();
        $on = $together['session'].':'.$together['open'];

        if ($together['open'] !== null && $on !== $this->openedForThem && is_file($root.'/'.$together['open'])) {
            $this->openedForThem = $on;
            $this->laravel->make(Editor::class)->open([$together['open']]);
        }

        if ($together['watch'] === [] || $this->laravel->make(WorkLauncher::class)->isRunning() || $this->laravel->make(FixLauncher::class)->isRunning()) {
            return;
        }

        $now = (new LocalBranch($root))->changed($together['watch']);
        $told = array_values(array_intersect($this->theirChanges[$together['session']] ?? [], $now));
        $changed = array_values(array_diff($now, $told));
        $this->theirChanges[$together['session']] = [...$told, ...$changed];

        if ($changed !== []) {
            $this->laravel->make(Studio::class)->submitScanWork(['session' => $together['session'], 'changed' => $changed]);
        }
    }

    /**
     * Changes agreed in the app after the last ones were applied keep the
     * same ask, so it starts again once that run has finished.
     *
     * @param  array{id: int, job: string, mode: string, state: string}  $ask
     */
    private function isNewAsk(array $ask): bool
    {
        if (! in_array($ask['id'], $this->startedByItself, true)) {
            return true;
        }

        $run = $this->laravel->make(WorkProgress::class)->read();

        return $ask['mode'] === 'app' && $run !== null && $run['session'] === $ask['id'] && $run['phase'] === WorkProgress::FINISHED && $run['reason'] === null
            && microtime(true) - (float) $run['finished'] > self::AGREED_AGAIN_AFTER_SECONDS;
    }

    private function startTheFixesAsked(?DashboardSnapshot $snapshot): void
    {
        $fixes = $this->laravel->make(FixLauncher::class);
        $asked = $snapshot?->fixesAsked ?? [];
        $ask = implode(',', $asked);

        if ($asked === [] || $ask === $this->fixesStarted || $this->laravel->make(WorkLauncher::class)->isRunning() || ! $fixes->canStart($asked)) {
            $this->fixesStarted = $asked === [] ? null : $this->fixesStarted;

            return;
        }

        $this->fixesStarted = $ask;
        $fixes->start($asked);
    }

    private function openTheWorkOnceItStarts(): void
    {
        $run = $this->repository === null ? null : $this->laravel->make(WorkProgress::class)->read();
        $tools = $run === null ? [] : WorkPanel::insights()->tools($this->laravel->make(SnapshotSource::class)->snapshot());

        if ($run === null || $tools === [] || $run['session'] === $this->workOpened || in_array($run['phase'], [WorkProgress::FINISHED, WorkProgress::REFUSED], true)) {
            return;
        }

        $this->workOpened = $run['session'];
        $this->laravel->make(ScreenRequests::class)->show('insights', fn (Tab $tab): Tab => $tab->closeAll()
            ->openWhere(fn (array $record): bool => in_array($record['key'] ?? null, $tools, true)));
    }

    protected function screenRefreshed(): void
    {
        $this->laravel->make(SnapshotSource::class)->forget();
    }

    protected function screenTicked(): void
    {
        $this->askForTheTokenWhenNotLinked();
        $this->openInsightsWhenFixesAreAsked();
        $this->sendTheFilesAsked();
        $this->runTheSearchesAsked();
        $this->workTogetherWhenAsked();
        $this->startWhatTheDeveloperAgreed();
        $this->openTheWorkOnceItStarts();

        if ($this->repository === null || time() - $this->pingedAt < self::PING_EVERY_SECONDS) {
            return;
        }

        $studio = $this->laravel->make(Studio::class);

        if ($this->pingedAt > 0 && $studio->isLinked()) {
            return;
        }

        $this->pingedAt = time();
        $studio->ping($this->repository);
    }

    public function screen(ScreenContainer $screen): ScreenContainer
    {
        $studio = app(StudioTabs::class);

        return $screen
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->heading(fn (DashboardSnapshot $data): string => $data->project)
            ->description(fn (DashboardSnapshot $data): ?string => $data->repository)
            ->status(
                fn (DashboardSnapshot $data): string => self::isConnected() ? '♥ '.$data->healthValue().' '.$data->healthLabel : '',
                fn (DashboardSnapshot $data): string => $data->healthColour(),
            )
            ->aside(fn (DashboardSnapshot $data): string => 'updated '.LocalTime::of($data->takenAt->toIso8601String()))
            ->openUrl(fn (): string => (string) config('studio-cli.url'))
            ->tabs($studio->tabs())
            ->showTabsAlone()
            ->hideTabsWhen(fn (): bool => ! self::isConnected())
            ->rail($studio->rail())
            ->settings($studio->settings());
    }

    /**
     * Whether the studio answers for this project, so the tabs and its health mean something.
     */
    private static function isConnected(): bool
    {
        return ! in_array(app(Studio::class)->connection(), [Studio::UNLINKED, Studio::REFUSED, Studio::FORBIDDEN], true);
    }
}
