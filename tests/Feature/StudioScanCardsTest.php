<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\FreshSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Scan\ScanProgress;
use ArtisanStudio\StudioCli\Scan\ScanRules;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\Spinner;

/*
|--------------------------------------------------------------------------
| The scan's own cards on the Dashboard
|--------------------------------------------------------------------------
|
| Under the progress bar: the tests the scan ran in the background, and a
| card for each tool switched on for the scan that the project does not have
| yet. Enter offers to install it, and nothing installs until the developer
| says yes.
|
*/

beforeEach(function (): void {
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    $this->plain = fn (string $screen): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
    $this->root = sys_get_temp_dir().'/studio-scan-cards-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/vendor/bin', 0755, true);
    touch($this->root.'/vendor/bin/pint');
    app()->instance(ToolStatus::class, new ToolStatus($this->root));
    $this->status = app(ToolStatus::class);
    $this->studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    @unlink($this->status->path());
});

afterEach(function (): void {
    @unlink($this->status->path());
});

it('shows how the tests went and offers to install each tool the scan could not run', function (): void {
    $this->status->asked(['phpstan', 'tests']);
    $this->status->testsFinished(['ran' => true, 'took' => 70_000, 'summary' => ['tests' => 3499, 'failed' => 0, 'skipped' => 3]]);

    $dashboard = ($this->plain)($this->studio->render(170, 40, 'dashboard'));

    $lines = explode("\n", $dashboard);
    $at = collect($lines)->search(fn (string $line): bool => str_contains($line, 'Tests · 3,496'));

    expect(strpos($lines[$at], '✓ Tests'))->toBeGreaterThan(20)
        ->and(strpos($dashboard, 'Tests · 3,496'))->toBeLessThan(strpos($dashboard, '[dashboard.'));

    expect($dashboard)
        ->toContain('✓ Tests · 3,496 passed · 0 failed · 3 skipped · in 70s')
        ->and(strpos($dashboard, 'Tests · 3,496'))->toBeLessThan(strpos($dashboard, 'PHPStan'))
        ->and($dashboard)
        ->toContain('PHPStan')
        ->toContain('Not installed')
        ->toContain('⏎ or click to install')
        ->and(collect($this->studio->footerKeys('dashboard'))->pluck('label'))->toContain('Install');
});

it('spins while the tests wait and run, says off when the developer left them off, and opens the install panel when a card is clicked', function (): void {
    $this->status->asked(['tests', 'phpstan']);

    expect(($this->plain)($this->studio->render(170, 40, 'dashboard')))->toContain('Tests · waiting for the scan tools')
        ->and($this->studio->render(170, 40, 'dashboard'))->toContain(Canvas::ACTION.'row:enter');

    $this->status->asked(['tests']);
    $this->status->testsRunning();
    $running = ($this->plain)($this->studio->render(170, 40, 'dashboard'));

    expect($running)->toContain('Tests · running in the background…')->not->toContain('to install')
        ->and(collect(Spinner::FRAMES)->contains(fn (string $frame): bool => str_contains($running, $frame.' Tests')))->toBeTrue();

    $this->status->testsOff();

    expect(($this->plain)($this->studio->render(170, 40, 'dashboard')))->toContain('on at your next scan')
        ->and(collect($this->studio->footerKeys('dashboard'))->pluck('label'))->not->toContain('Install');
});

it('installs everything picked with one Enter and no panel to read, since the developer already confirmed in the app', function (): void {
    $root = sys_get_temp_dir().'/studio-install-'.bin2hex(random_bytes(4));
    @mkdir($root, 0755, true);
    file_put_contents($root.'/artisan', '<?php echo "Installed.", PHP_EOL;');
    app()->instance(BackgroundTasks::class, new BackgroundTasks($root, app(ActivityLog::class)));
    $this->status->asked(['phpstan', 'rector']);

    expect($this->studio->tab('dashboard')->canEnter())->toBeTrue()
        ->and($this->studio->tab('dashboard')->enter())->toBe([])
        ->and(app(ActivityLog::class)->ofKinds(['install-tools'])[0])->toMatchArray(['label' => 'Install tools', 'detail' => 'Installing PHPStan and Rector with composer…'])
        ->and(app(BackgroundTasks::class)->isRunning('install-tools'))->toBeTrue()
        ->and($this->studio->tab('dashboard')->canEnter())->toBeFalse();
});

it('says it is almost ready while a picked rule waits to be installed, then that the extra rules are running', function (): void {
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 62, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048]]);
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $this->status->asked(['phpstan']);
    $this->status->pick(['phpstan']);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('[dashboard.almost]')->toContain('[dashboard.almost line]');

    $this->status->pick([]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->not->toContain('[dashboard.almost]')->not->toContain('[dashboard.extras]');

    $this->status->asked([]);
    $this->status->pick(['house-check', 'pest-type-coverage']);
    $this->status->running(['house-check', 'pest-type-coverage']);
    $this->status->ran('house-check', ['ran' => true, 'findings' => []]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->not->toContain('[dashboard.rules-found]')->not->toContain('[dashboard.rules-clean');

    $this->status->ran('pest-type-coverage', ['ran' => true, 'findings' => [['where' => 'app/A.php:1', 'rule' => 'pr', 'message' => 'x']]]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('[dashboard.rules-found]')->toContain('[dashboard.rules-found line]');

    $this->status->running(['pest-type-coverage']);
    $this->status->ran('pest-type-coverage', ['ran' => true, 'findings' => []]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('[dashboard.rules-clean-plain]')->toContain('[dashboard.rules-clean-plain line]');

    $this->status->pick(['house-check', 'pest-type-coverage', 'phpstan']);
    $this->status->running(['phpstan']);
    $this->status->ran('phpstan', ['ran' => true, 'findings' => []]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('[dashboard.rules-clean line]');
});

it('says PHPStan is still reading while it is, and shows it as checking on the Scan tab until it lands with the rest', function (): void {
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 62, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048]]);
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $this->status->pick(['house-check', 'phpstan']);
    $this->status->running(['house-check']);
    $this->status->ran('house-check', ['ran' => true, 'findings' => []]);
    $this->status->behind('phpstan', ToolStatus::QUEUED);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))
        ->toContain('[dashboard.phpstan-behind]')
        ->not->toContain('after the other tools');

    $this->status->behind('phpstan', ToolStatus::RUNNING);

    expect(collect(app(ScanRules::class)->rows())->firstWhere('key', 'phpstan'))->toMatchArray(['state' => 'running', 'note' => 'checking']);

    $this->status->caughtUp('phpstan', ['ran' => true, 'findings' => [['where' => 'app/A.php:1', 'rule' => 'property.notFound', 'message' => 'x']]]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))
        ->not->toContain("PHPStan's still reading")
        ->toContain('[dashboard.rules-found]');

    $this->status->excluded([
        ['package' => 'laravel/ai', 'installed' => '0.10.3', 'latest' => '1.1.0', 'findings' => 12],
        ['package' => 'laravel/mcp', 'installed' => 'v0.7.2', 'latest' => '1.0.1', 'findings' => 3],
        ['package' => 'saloonphp/laravel-plugin', 'installed' => '4.3.0', 'latest' => '5.0.1', 'findings' => 2],
    ]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))
        ->toContain('[dashboard.excluded]')
        ->toContain('[dashboard.excluded line]')
        ->toContain('Excluded: laravel/ai 0.10 → 1.1, laravel/mcp 0.7 → 1.0 and 1 more')
        ->not->toContain('[dashboard.rules-found]');

    $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))
        ->not->toContain('Excluded: laravel/ai')
        ->not->toContain("I've set aside findings");
});

it('keeps the dashboard to the four first-stage cards however many rules are picked, with the tests as one line under the stats', function (): void {
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 62, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048]]);
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $this->status->asked(['pint', 'tests']);
    $this->status->pick(['rector', 'composer-audit', 'node-audit']);
    $this->status->running(['pint']);
    $this->status->ran('pint', ['ran' => true, 'findings' => []]);
    $this->status->running(['rector', 'composer-audit', 'node-audit']);

    $screen = ($this->plain)($studio->render(170, 40, 'dashboard'));

    expect($screen)->toContain('Blueprint')->toContain('Conventions')->toContain('Best practices')->toContain('Pint')->toContain('Tests · waiting for the scan tools')
        ->not->toContain('Rector')->not->toContain('composer audit')->not->toContain('pnpm or npm audit')
        ->and(strpos($screen, 'Tests · waiting'))->toBeLessThan(strpos($screen, 'Pint'));
});

it('shows what counting the conventions sent back as a card of its own, first among the tools', function (): void {
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 62, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048]]);
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $this->status->asked(['pint', 'tests']);
    $this->status->running(['pint']);
    $this->status->ran('pint', ['ran' => true, 'findings' => []]);

    $waiting = ($this->plain)($studio->render(170, 40, 'dashboard'));

    expect($waiting)->toContain('Blueprint')->toContain('Conventions')->toContain('Best practices')->toContain('Waiting…')->not->toContain('followed')
        ->toContain('Read 3,048 files on your machine: conventions, best practices, security patterns and your tests')
        ->not->toContain('conventions learned');

    app(ToolStatus::class)->blueprintMapped(47);
    $this->status->conventionsCounted(1_212, 11, 3_048, 5);

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('47 models mapped')->toContain('on your machine')->not->toContain('Across 0');

    app(ToolStatus::class)->blueprintMapped(47, 58);
    $screen = ($this->plain)($studio->render(170, 40, 'dashboard'));

    expect($screen)->toContain('47 models mapped')->toContain('Across 58 relations')->toContain('1,212 followed')->toContain('11 new to decide')->not->toContain('to decide ·')
        ->toContain('5 files to look at')->toContain('Advisory only')->toContain('Detection stage')
        ->and(strpos($screen, 'Blueprint'))->toBeLessThan(strpos($screen, 'Conventions'))
        ->and(strpos($screen, 'Conventions'))->toBeLessThan(strpos($screen, 'Best practices'))
        ->and(strpos($screen, 'Best practices'))->toBeLessThan(strpos($screen, 'Pint'))
        ->and($this->status->conventions())->toMatchArray(['followed' => 1_212, 'undecided' => 11, 'files' => 3_048, 'flagged' => 5])
        ->and($this->status->blueprint())->toBe(['models' => 47, 'relationships' => 58]);
});

it('drops the cards once the scan is reset in the studio, even though the last results are still on this machine', function (): void {
    $this->status->asked(['phpstan', 'tests']);
    $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);
    app()->instance(SnapshotSource::class, new FreshSnapshots);
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());

    expect(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('Not scanned yet')->not->toContain('Not installed')->not->toContain('10 passed')
        ->and($studio->tab('dashboard')->canEnter())->toBeFalse();

    app(ToolStatus::class)->forget();

    expect(app(ToolStatus::class)->tests())->toBeNull();
});

it('shows no health number until the studio has scored the conventions, only what it is doing', function (): void {
    $scanning = DashboardSnapshot::fromApi(['health' => ['percent' => 0, 'label' => 'Scanning…', 'scored' => false]]);
    $scored = DashboardSnapshot::fromApi(['health' => ['percent' => 72, 'label' => 'Fair', 'scored' => true]]);

    expect([$scanning->healthValue(), $scanning->healthLabel, $scanning->healthColour()])->toBe(['—', 'Scanning…', 'dim'])
        ->and([$scored->healthValue(), $scored->healthColour()])->toBe(['72%', 'amber'])
        ->and(DashboardSnapshot::fromApi(['health' => ['percent' => 60, 'label' => 'Fair']])->healthValue())->toBe('60%');
});

it('says what will happen on this machine before the scan is authorized, and what is happening once it runs', function (): void {
    app()->instance(SnapshotSource::class, new FreshSnapshots);
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $before = ($this->plain)($studio->render(170, 40, 'dashboard'));

    expect($before)->toContain('[dashboard.before]')
        ->toContain('[dashboard.before line]');

    app(ScanProgress::class)->progressed(1250, 3048);
    $during = ($this->plain)($studio->render(170, 40, 'dashboard'));
    app(ScanProgress::class)->finished();

    expect($during)->toContain('Reading 1,250 of 3,048 files on your machine')
        ->not->toContain('[dashboard.before]');
});

it('lets the avatar say what it learned once the conventions are in, then how the tests went', function (): void {
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 72, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally · conventions learned', 'files_done' => 3048, 'files_total' => 3048]]);
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $says = fn (): string => ($this->plain)($studio->render(170, 40, 'dashboard'));
    $finished = fn (int $failed): mixed => $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 100, 'failed' => $failed, 'skipped' => 0]]);

    $this->status->asked(['tests']);
    $this->status->testsRunning();

    expect($says())->toContain('[dashboard.learned]')->toContain('[dashboard.learned line]')->not->toContain('[dashboard.before]');

    $finished(0);

    expect($says())->toContain('[dashboard.passing]')->toContain('[dashboard.passing line]')->not->toContain('[dashboard.learned]');

    $finished(2);

    expect($says())->toContain('[dashboard.so-close]')->toContain('[dashboard.so-close line]');

    $finished(12);

    expect($says())->toContain('[dashboard.failing]')->toContain('[dashboard.failing line]');
});

it('offers to fix what the developer asked for in the app, and starts only when they press Enter here', function (): void {
    $root = sys_get_temp_dir().'/studio-fix-'.bin2hex(random_bytes(4));
    @mkdir($root, 0755, true);
    file_put_contents($root.'/artisan', '<?php echo "Fixed.", PHP_EOL;');
    app()->instance(BackgroundTasks::class, new BackgroundTasks($root, app(ActivityLog::class)));
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 62, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048], 'fixes' => ['asked' => ['phpstan', 'composer-audit']]]);
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $dashboard = ($this->plain)($studio->render(170, 40, 'dashboard'));

    expect($dashboard)->toContain('Fix with SAMI')->toContain('PHPStan')->toContain('⏎ or click to fix')
        ->and(collect($studio->footerKeys('dashboard'))->pluck('label'))->toContain('Fix with SAMI')
        ->and(app(BackgroundTasks::class)->isRunning('fixes'))->toBeFalse()
        ->and($studio->tab('dashboard')->enter())->toBe([])
        ->and(app(ActivityLog::class)->ofKinds(['fixes'])[0])->toMatchArray(['label' => 'Fix with SAMI', 'detail' => 'Fixing PHPStan on a new branch…'])
        ->and(app(BackgroundTasks::class)->isRunning('fixes'))->toBeTrue()
        ->and($studio->tab('dashboard')->canEnter())->toBeFalse()
        ->and(($this->plain)($studio->render(170, 40, 'dashboard')))->toContain('Fixing…');
});

it('shows the four first-stage cards under the bar through the first round of checks, before the studio has counted any files', function (): void {
    $root = sys_get_temp_dir().'/studio-round-one-'.bin2hex(random_bytes(4));
    @mkdir($root, 0755, true);
    file_put_contents($root.'/artisan', '<?php sleep(2);');
    app()->setBasePath($root);
    app()->instance(BackgroundTasks::class, new BackgroundTasks($root, app(ActivityLog::class)));
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 0, 'label' => 'Scanning…', 'scored' => false]]);
        }

        public function forget(): void {}
    });
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $this->status->asked(['pint', 'rector', 'spelling', 'studio-security']);
    $this->status->running(['pint', 'rector', 'spelling', 'studio-security']);
    $this->status->ran('pint', ['ran' => true, 'findings' => []]);
    app(BackgroundTasks::class)->start('tools', 'Scan tools', 'Running the checks…', ['studio:tools']);

    $first = ($this->plain)($studio->render(170, 40, 'dashboard'));

    expect($first)->toContain('Running your checking tools, read-only: 1 of 4 done…')
        ->toContain('Blueprint')->toContain('Conventions')->toContain('Best practices')->toContain('Pint');

    $this->status->asked(['phpstan', 'second-check', 'pest-type-coverage']);
    $this->status->pick(['phpstan', 'second-check', 'pest-type-coverage']);
    $this->status->running(['phpstan', 'second-check', 'pest-type-coverage']);
    $this->status->ran('phpstan', ['ran' => true, 'findings' => []]);

    $second = ($this->plain)($studio->render(170, 40, 'dashboard'));

    expect($second)->toContain('1 of 3 done…')->toContain('Blueprint')->toContain('Conventions')->toContain('Best practices')->toContain('Pint');
});

it('shows no scan cards before a scan has run', function (): void {
    expect(($this->plain)($this->studio->render(170, 40, 'dashboard')))->not->toContain('Not installed')->not->toContain('passed')
        ->and($this->studio->tab('dashboard')->canEnter())->toBeFalse();
});
