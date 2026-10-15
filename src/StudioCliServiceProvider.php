<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Console\AvatarSyncCommand;
use ArtisanStudio\StudioCli\Console\BlueprintCommand;
use ArtisanStudio\StudioCli\Console\BuildPresenceCommand;
use ArtisanStudio\StudioCli\Console\CheckCommand;
use ArtisanStudio\StudioCli\Console\ConventionsCommand;
use ArtisanStudio\StudioCli\Console\FixCommand;
use ArtisanStudio\StudioCli\Console\InstallToolsCommand;
use ArtisanStudio\StudioCli\Console\PhpStanCommand;
use ArtisanStudio\StudioCli\Console\RenderCommand;
use ArtisanStudio\StudioCli\Console\TestsCommand;
use ArtisanStudio\StudioCli\Console\ToolsCommand;
use ArtisanStudio\StudioCli\Console\WorkCommand;
use ArtisanStudio\StudioCli\Dashboard\LiveSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Fix\FixLauncher;
use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Scan\ScanProgress;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Scan\Walk;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Work\WorkLauncher;
use ArtisanStudio\StudioCli\Work\WorkProgress;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class StudioCliServiceProvider extends ServiceProvider
{
    /**
     * What a scan runs in the background, by task.
     *
     * @var list<string>
     */
    private const array SCAN_TASKS = ['blueprint', 'conventions', 'tools', 'tests', 'coverage', 'phpstan'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/studio-cli.php', 'studio-cli');

        $this->app->singleton(Studio::class);

        $this->app->singleton(Says::class, fn (): Says => new Says($this->app->make(Studio::class), $this->app->basePath()));

        $this->app->singleton(LocalChanges::class, fn (): LocalChanges => new LocalChanges($this->app->basePath()));

        $this->app->singleton(Editor::class, fn (): Editor => new Editor($this->app->basePath()));

        $this->app->singleton(EnvFile::class, fn (): EnvFile => new EnvFile($this->app->basePath('.env')));

        $this->app->singleton(TestSuite::class, fn (): TestSuite => new TestSuite($this->app->basePath()));

        $this->app->singleton(Blueprint::class, fn (): Blueprint => new Blueprint(
            $this->app->path(),
            $this->app->getNamespace(),
            $this->app->make(ModelInspector::class),
        ));

        $this->app->singleton(Walk::class, fn (): Walk => new Walk($this->app->basePath(), $this->app->make(LocalChanges::class)));

        $this->app->singleton(ActivityLog::class);

        $this->app->singleton(BackgroundTasks::class, fn (): BackgroundTasks => new BackgroundTasks(
            $this->app->basePath(),
            $this->app->make(ActivityLog::class),
            fn (): null => $this->app->make(SnapshotSource::class)->forget(),
        ));

        $this->app->singleton(ScanProgress::class, fn (): ScanProgress => new ScanProgress($this->app->basePath()));

        $this->app->singleton(FixLauncher::class, fn (): FixLauncher => new FixLauncher($this->app->make(BackgroundTasks::class), $this->app->basePath()));

        $this->app->singleton(FixProgress::class, fn (): FixProgress => new FixProgress($this->app->basePath()));

        $this->app->singleton(WorkProgress::class, fn (): WorkProgress => new WorkProgress($this->app->basePath()));

        $this->app->singleton(WorkLauncher::class, fn (): WorkLauncher => new WorkLauncher($this->app->make(BackgroundTasks::class), $this->app->make(WorkProgress::class)));

        $this->app->singleton(ToolStatus::class, fn (): ToolStatus => new ToolStatus($this->app->basePath()));

        $this->app->singleton(TaskJournal::class, fn (): TaskJournal => TaskJournal::fromEnvironment());

        $this->app->singleton(ScreenRequests::class);

        $this->app->singleton(SnapshotSource::class, LiveSnapshots::class);

        $this->app->singleton(Presence::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            AvatarSyncCommand::class,
            BlueprintCommand::class,
            BuildPresenceCommand::class,
            ConventionsCommand::class,
            FixCommand::class,
            InstallToolsCommand::class,
            PhpStanCommand::class,
            CheckCommand::class,
            RenderCommand::class,
            TestsCommand::class,
            ToolsCommand::class,
            WorkCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/studio-cli.php' => config_path('studio-cli.php'),
        ], ['studio-cli', 'studio-cli-config']);

        Event::listen(StudioReported::class, $this->refetchWhenTheStudioChanges(...));

        Event::listen(StudioReported::class, $this->mapTheBlueprintWhenAsked(...));

        Event::listen(StudioReported::class, $this->runTheToolsWhenAsked(...));

        Event::listen(StudioReported::class, $this->stopTheScanWhenReset(...));
    }

    private function refetchWhenTheStudioChanges(StudioReported $reported): void
    {
        if (in_array($reported->event['type'] ?? null, ['changed', 'checkpoint'], true)) {
            $this->app->make(SnapshotSource::class)->forget();
        }
    }

    private function mapTheBlueprintWhenAsked(StudioReported $reported): void
    {
        if (($reported->event['type'] ?? null) !== 'blueprint') {
            return;
        }

        $this->app->make(ToolStatus::class)->forget();

        $tasks = $this->app->make(BackgroundTasks::class);
        $tasks->stop(self::SCAN_TASKS);
        $tasks->start('blueprint', 'Blueprint', 'Mapping your models…', [BlueprintCommand::SIGNATURE]);
        $tasks->start('conventions', 'Conventions', 'Counting how your project is written…', [ConventionsCommand::SIGNATURE]);
        $tasks->start('tests', 'Your tests', 'Checking whether you switched your tests on…', [TestsCommand::SIGNATURE], timeout: TestsCommand::TIMEOUT);
        $tasks->start('tools', 'Scan tools', 'Running your project’s own checking tools, read-only…', [ToolsCommand::SIGNATURE]);
    }

    /**
     * When the developer resets the scan in the app, whatever this machine is
     * still running for it stops, and what it kept of it is dropped.
     */
    private function stopTheScanWhenReset(StudioReported $reported): void
    {
        if (($reported->event['type'] ?? null) !== 'reset') {
            return;
        }

        $resetAt = is_int($reported->event['reset_at'] ?? null) ? $reported->event['reset_at'] : null;
        $isNew = $this->isNewReset($resetAt);

        if ($isNew) {
            $this->stopTheFixRunBefore($resetAt);
            $this->stopTheWorkBefore($resetAt);
            $this->app->make(BackgroundTasks::class)->stop([...self::SCAN_TASKS, WorkLauncher::RECHECKS]);
            $this->app->make(ToolStatus::class)->forget();
            $this->app->make(ScanProgress::class)->finished();
            $this->app->make(SnapshotSource::class)->forget();
        }

        $this->forgetFixesBefore($resetAt, stopped: $isNew);
        $this->forgetWorkBefore($resetAt, stopped: $isNew);
    }

    private function stopTheFixRunBefore(?int $resetAt): void
    {
        $fixes = $this->app->make(FixProgress::class);
        $run = $fixes->read();

        if ($run === null || ! $fixes->isAlive() || ($resetAt !== null && $resetAt <= $run['at'] * 1000)) {
            return;
        }

        $this->app->make(BackgroundTasks::class)->stop([FixLauncher::TASK]);

        if ($run['pid'] !== null && $fixes->isAlive()) {
            ProcessTree::stop($run['pid']);
        }
    }

    private function forgetFixesBefore(?int $resetAt, bool $stopped = false): void
    {
        $fixes = $this->app->make(FixProgress::class);
        $run = $fixes->read();

        if ($run === null || ($fixes->isAlive() && ! $stopped) || ($resetAt !== null && $resetAt <= $run['at'] * 1000)) {
            return;
        }

        $fixes->forget();
        $this->app->make(SnapshotSource::class)->forget();
    }

    private function stopTheWorkBefore(?int $resetAt): void
    {
        $work = $this->app->make(WorkProgress::class);
        $run = $work->read();

        if ($run === null || ! $work->isAlive() || ($resetAt !== null && $resetAt <= $run['at'] * 1000)) {
            return;
        }

        $this->app->make(BackgroundTasks::class)->stop([WorkLauncher::TASK]);

        if ($run['pid'] !== null && $work->isAlive()) {
            ProcessTree::stop($run['pid']);
        }
    }

    private function forgetWorkBefore(?int $resetAt, bool $stopped = false): void
    {
        $work = $this->app->make(WorkProgress::class);
        $run = $work->read();

        if ($run === null || ($work->isAlive() && ! $stopped) || ($resetAt !== null && $resetAt <= $run['at'] * 1000)) {
            return;
        }

        $work->forget();
    }

    private function isNewReset(?int $resetAt): bool
    {
        if ($resetAt === null) {
            return true;
        }

        $handled = StateFile::for('reset', $this->app->basePath(), '.txt');

        if ($resetAt <= (int) @file_get_contents($handled)) {
            return false;
        }

        file_put_contents($handled, (string) $resetAt);

        return true;
    }

    private function runTheToolsWhenAsked(StudioReported $reported): void
    {
        if (($reported->event['type'] ?? null) !== 'tools') {
            return;
        }

        $status = $this->app->make(ToolStatus::class);
        $tools = array_values(array_filter((array) ($reported->event['tools'] ?? []), is_string(...)));

        if ($tools !== []) {
            $status->asked($tools);
            $status->pick($tools);
        }

        $tasks = $this->app->make(BackgroundTasks::class);

        if (in_array(TestsCommand::COVERAGE, $tools, true)) {
            $tasks->start(TestsCommand::COVERAGE_TASK, 'Code coverage', 'Measuring code coverage in the background…', [TestsCommand::SIGNATURE], timeout: TestsCommand::TIMEOUT);
        }

        if ($status->missing() !== []) {
            return;
        }

        $tasks->start('tools', 'Scan tools', 'Running the extra checks you picked…', [ToolsCommand::SIGNATURE]);
    }
}
