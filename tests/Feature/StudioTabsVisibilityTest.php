<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;

/*
|--------------------------------------------------------------------------
| Scan and Insights come when the app asks for them
|--------------------------------------------------------------------------
|
| Until a scan has begun there is no Scan tab, and until fixes are asked for
| or a run is on show there is no Insights tab: the row starts with the
| Dashboard. A reset takes the scan away again.
|
*/

beforeEach(function (): void {
    config(['studio-cli.tabs.only_when_asked' => true]);
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    $this->root = sys_get_temp_dir().'/studio-tabs-visibility-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
    app()->setBasePath($this->root);
    app()->instance(BackgroundTasks::class, new BackgroundTasks($this->root, app(ActivityLog::class)));
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        /**
         * @var list<string>
         */
        public array $asked = [];

        public string $scan = DashboardSnapshot::NOT_SCANNED;

        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 76, 'label' => 'Fair', 'scored' => true], 'fixes' => ['asked' => $this->asked], 'scan' => ['status' => $this->scan]]);
        }

        public function forget(): void {}
    });
    app(ToolStatus::class)->forget();
    app(FixProgress::class)->forget();
    $this->screen = app(StudioCommand::class)->screen(ScreenContainer::make());
    $this->keys = fn (): array => $this->screen->tabKeys();
});

afterEach(function (): void {
    app(ToolStatus::class)->forget();
    app(FixProgress::class)->forget();
});

it('shows neither Scan nor Insights before the app has asked for anything', function (): void {
    expect(($this->keys)())->not->toContain('scan')->not->toContain('insights')->toContain('dashboard')
        ->and($this->screen->tab('scan')->getKey())->not->toBe('scan');
});

it('brings Scan once the studio says a scan has begun, and takes it away again on a reset', function (): void {
    app(ToolStatus::class)->asked(['phpstan']);

    expect(($this->keys)())->not->toContain('scan');

    app(SnapshotSource::class)->scan = 'Scanning';

    expect(($this->keys)())->toContain('scan')->not->toContain('insights');

    app(SnapshotSource::class)->scan = DashboardSnapshot::NOT_SCANNED;

    expect(($this->keys)())->not->toContain('scan');
});

it('brings Insights once fixes are asked for, and while a run is on show', function (): void {
    expect(($this->keys)())->not->toContain('insights');

    app(SnapshotSource::class)->asked = ['phpstan'];

    expect(($this->keys)())->toContain('insights');

    app(SnapshotSource::class)->asked = [];

    expect(($this->keys)())->not->toContain('insights');

    app(FixProgress::class)->begin('studio/fixes-2026-10-08-1300', ['pint'], ['pint' => 3]);

    expect(($this->keys)())->toContain('insights');
});

it('shows every tab when switched off in the config', function (): void {
    config(['studio-cli.tabs.only_when_asked' => false]);

    expect(($this->keys)())->toContain('scan')->toContain('insights');
});
