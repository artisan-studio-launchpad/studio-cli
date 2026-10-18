<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Fix\FixLauncher;
use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Scan\Tools\DefinedTool;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Terminal\Spinner;

/*
|--------------------------------------------------------------------------
| Fixing the rules, on two paths
|--------------------------------------------------------------------------
|
| The developer asks in the app; the terminal turns to the tab for what was
| asked. The everyday rules are fixed from Insights; PHPStan and type
| coverage, for advanced users included. Each rule is a row of a table that counts up how
| many are fixed of how many, how far along that is and how long it took,
| and the avatar says how the fixes went.
|
*/

beforeEach(function (): void {
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    $this->plain = fn (string $screen): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
    $this->root = sys_get_temp_dir().'/studio-fix-flow-'.bin2hex(random_bytes(4));
    @mkdir($this->root.'/vendor/bin', 0755, true);
    file_put_contents($this->root.'/artisan', '<?php sleep(2); echo "Fixed.", PHP_EOL;');
    file_put_contents($this->root.'/vendor/bin/pint', '');
    DefinedTool::keep($this->root, [['key' => 'house-check', 'name' => 'House check', 'bin' => 'house-check']]);
    app()->setBasePath($this->root);
    app()->instance(BackgroundTasks::class, new BackgroundTasks($this->root, app(ActivityLog::class)));
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        /**
         * @var list<string>
         */
        public array $asked = ['phpstan', 'pest-type-coverage', 'pint'];

        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 76, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048], 'fixes' => ['asked' => $this->asked]]);
        }

        public function forget(): void {}
    });
    $this->progress = app(FixProgress::class);
    $this->progress->forget();
    $this->status = app(ToolStatus::class);
    $this->status->forget();
    $this->status->running(['pint', 'phpstan', 'pest-type-coverage']);
    $this->status->ran('pint', ['ran' => true, 'took' => 3900, 'findings' => array_fill(0, 47, ['x'])]);
    $this->status->ran('phpstan', ['ran' => true, 'took' => 59000, 'findings' => array_fill(0, 12, ['x'])]);
    $this->status->ran('pest-type-coverage', ['ran' => true, 'took' => 103000, 'findings' => array_fill(0, 661, ['x'])]);
    $this->command = app(StudioCommand::class);
    $this->studio = $this->command->screen(ScreenContainer::make());
    $this->insights = fn (): string => ($this->plain)($this->studio->render(170, 40, 'insights'));
    $this->ask = function (array $asked): void {
        app(SnapshotSource::class)->asked = $asked;
        $this->studio->tab('insights')->refreshState();
    };
});

afterEach(function (): void {
    $this->progress->forget();
    $this->status->forget();
});

it('turns to Insights for what was asked, once per ask', function (): void {
    $announce = (new ReflectionClass(StudioCommand::class))->getMethod('openInsightsWhenFixesAreAsked');
    (new ReflectionProperty(StudioCommand::class, 'repository'))->setValue($this->command, 'artisan-studio-projects/artisan-studio');
    $this->command->setLaravel(app());
    $requests = app(ScreenRequests::class);

    $announce->invoke($this->command);

    expect($requests->take())->toMatchArray(['tab' => 'insights']);

    $announce->invoke($this->command);

    expect($requests->take())->toBeNull();

    app(SnapshotSource::class)->asked = ['phpstan'];
    $announce->invoke($this->command);

    expect($requests->take())->toMatchArray(['tab' => 'insights']);
});

it('fixes every rule asked for from Insights, with one Enter', function (): void {
    $insights = ($this->insights)();

    expect($insights)
        ->toContain('[fix.asked]')
        ->not->toContain('AI')
        ->toContain('[fix.asked line]')
        ->toContain('Pint')
        ->toContain('PHPStan')
        ->toContain('Pest type coverage')
        ->and(collect($this->studio->footerKeys('insights'))->pluck('label'))->toContain('Fix with SAMI');

    $this->studio->tab('insights')->enter();
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['rector', 'pint'], ['pint' => 47]);
    $this->progress->running(['rector']);
    $running = ($this->insights)();

    expect(app(BackgroundTasks::class)->isRunning('fixes'))->toBeTrue()
        ->and($running)->toContain('[fix.fixing]')
        ->toContain('Attempting to fix grouped issues')
        ->toContain('fixing…')
        ->toContain('next')
        ->toContain('0/47')
        ->and(collect(Spinner::FRAMES)->contains(fn (string $frame): bool => str_contains($running, $frame.' fixing…')))->toBeTrue();
});

it('says what is left on each row as soon as that rule is checked again, so the score moves one row at a time', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['house-check', 'node-audit', 'rector', 'pint', 'composer-audit'], ['house-check' => 2, 'node-audit' => 58, 'pint' => 47]);
    $this->progress->running(['house-check', 'node-audit']);
    $this->progress->done('node-audit', true, 45, 2);
    $this->progress->rechecking('node-audit');

    $checking = ($this->insights)();

    expect($checking)->toContain('checking again…')
        ->toContain('45/58')
        ->and(collect(Spinner::FRAMES)->contains(fn (string $frame): bool => str_contains($checking, $frame.' checking again…')))->toBeTrue();

    $this->progress->left('node-audit', 13);
    $screen = ($this->insights)();

    expect($screen)->toContain('45/58')
        ->toContain('13 left')
        ->toContain('[fix.fixing]')
        ->and(strpos($screen, 'House check'))->toBeLessThan(strpos($screen, 'pnpm or npm audit'))
        ->and(strpos($screen, 'pnpm or npm audit'))->toBeLessThan(strpos($screen, 'Rector'))
        ->and(strpos($screen, 'Pint'))->toBeLessThan(strpos($screen, 'composer audit'));
});

it('keeps every rule\'s name whole in the table', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['house-check', 'node-audit', 'rector', 'pint', 'composer-audit'], []);

    $screen = ($this->insights)();

    expect($screen)->toContain('House check')
        ->toContain('pnpm or npm audit')
        ->toContain('composer audit')
        ->not->toContain('pnpm or np…')
        ->not->toContain('composer a…')
        ->and(collect(explode("\n", $screen))->filter(fn (string $line): bool => str_contains($line, 'next'))->count())->toBe(5);
});

it('says most were fixed while the checks land, then that a few are left to work through together', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['pest-type-coverage', 'phpstan'], ['pest-type-coverage' => 661, 'phpstan' => 12]);
    $this->progress->running(['pest-type-coverage']);
    $this->progress->done('pest-type-coverage', true, 474, 193, 661);
    $this->progress->running(['phpstan']);
    $this->progress->done('phpstan', true, 12, 4, 12);
    $this->progress->left('phpstan', 0);
    $this->progress->rechecking('pest-type-coverage');

    expect(($this->insights)())
        ->toContain('[fix.most]')
        ->toContain('[fix.most line]')
        ->toContain('474/661')
        ->toContain('12/12')
        ->toContain('All fixed')
        ->toContain('checking again…');

    $this->progress->left('pest-type-coverage', 178);
    $this->progress->finished([]);
    ($this->ask)([]);

    $screen = ($this->insights)();
    $lines = collect(explode("\n", $screen));
    $tabs = $lines->search(fn (string $line): bool => str_contains($line, 'Settings'));
    $said = $lines->search(fn (string $line): bool => str_contains($line, '╰'));
    $table = $lines->search(fn (string $line): bool => str_contains($line, 'Group') && str_contains($line, 'Rule'));

    expect($screen)
        ->toContain('[fix.left]')
        ->toContain('[fix.left line]')
        ->toContain('483/661')
        ->toContain('178 left')
        ->and([$tabs, $said, $table])->each->toBeInt()
        ->and($lines->search(fn (string $line): bool => str_contains($line, '╭')) - $tabs)->toBeLessThanOrEqual(2)
        ->and($table - $said)->toBeLessThanOrEqual(4);
});

it('fixes PHPStan, says they got them all, and makes way for a new ask', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['phpstan'], ['phpstan' => 12]);
    $this->progress->running(['phpstan']);
    $this->progress->done('phpstan', true, 12, 4, 12);
    $this->progress->rechecking('phpstan');

    expect(($this->insights)())->toContain('[fix.all]');

    $this->progress->left('phpstan', 0);
    $this->progress->finished([]);
    ($this->ask)([]);

    expect(($this->insights)())->toContain('[fix.clean]')->toContain('[fix.clean line]');

    ($this->ask)(['phpstan']);

    expect(($this->insights)())
        ->toContain('[fix.asked]')
        ->toContain('press ⏎ to start')
        ->not->toContain('[fix.clean]');
});

it('never shows the last run\'s cards, or its all done, over a fresh ask or a scan that has run since', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['phpstan'], ['phpstan' => 12]);
    $this->progress->running(['phpstan']);
    $this->progress->done('phpstan', true, 12, 4, 12);
    $this->progress->left('phpstan', 0);
    $this->progress->finished([]);
    ($this->ask)([]);

    expect(($this->insights)())->toContain('[fix.clean]')->toContain('12/12');

    $this->status->asked(['pint', 'phpstan']);
    ($this->ask)([]);

    expect(($this->insights)())->not->toContain('[fix.clean]')->not->toContain('12/12');

    $this->progress->forget();
    $this->status->forget();
    $this->progress->refused('You have uncommitted changes.', ['phpstan']);
    ($this->ask)([]);

    expect(($this->insights)())->toContain('[fix.refused]');

    $this->status->asked(['phpstan']);
    ($this->ask)(['phpstan']);

    expect(($this->insights)())->not->toContain('[fix.refused]')->toContain('[fix.asked]');
});

it('says why it could not start, and offers to try again', function (): void {
    $this->progress->refused('You have uncommitted changes. Commit or stash them first, so SAMI\'s fixes stay apart from yours.', ['pint']);

    expect(($this->insights)())
        ->toContain('[fix.refused]')
        ->toContain('You have uncommitted changes.')
        ->toContain('press ⏎ to start');
});

it('says the run stopped when its process has gone, rather than spinning for ever', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['rector', 'node-audit'], []);
    $this->progress->running(['node-audit']);
    $this->progress->done('rector', true, 12, 4, 12);
    $json = json_decode((string) file_get_contents($this->progress->path()), true);
    file_put_contents($this->progress->path(), (string) json_encode([...$json, 'pid' => 999999]));

    $screen = ($this->insights)();

    expect($this->progress->hasStopped())->toBeTrue()
        ->and($screen)->toContain('[fix.stopped]')
        ->toContain('stopped')
        ->not->toContain('fixing…')
        ->and(app(FixLauncher::class)->isRunning())->toBeFalse();
});

it('shows a tool that fixed files and then stopped with an error as what it did, with the error a look away', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['pint'], []);
    $this->progress->running(['pint']);
    $this->progress->done('pint', false, 0, 10);

    expect(($this->insights)())->toContain('error · 10 files')->not->toContain('did not run');
});

it('counts up how many are fixed of how many while the fixers work, and says how far along the whole run is', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['phpstan', 'pest-type-coverage'], ['phpstan' => 2445, 'pest-type-coverage' => 4757]);
    $this->progress->running(['phpstan', 'pest-type-coverage']);
    $this->progress->fixing(['phpstan' => 500, 'pest-type-coverage' => 1000]);

    $screen = ($this->insights)();

    expect($screen)->toContain('Attempting to fix grouped issues')
        ->toContain('500/2,445')
        ->toContain('1,000/4,757')
        ->toContain('1,500/7,202 issues · 21%')
        ->toContain('elapsed');

    $this->progress->fixing(['phpstan' => 1200]);

    expect(($this->insights)())->toContain('1,200/2,445')->toContain('2,200/7,202 issues · 31%');
});

it('never says 100% while any issue is left, and says All fixed in green once a rule is checked clean', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['phpstan'], ['phpstan' => 1000]);
    $this->progress->running(['phpstan']);
    $this->progress->done('phpstan', true, 998, 40, 1000);
    $this->progress->left('phpstan', 2);

    expect(($this->insights)())->toContain('998/1,000')->toContain('998/1,000 issues · 99%')->not->toContain('100%')->toContain('2 left');

    $this->progress->left('phpstan', 0);

    expect(($this->insights)())->toContain('1,000/1,000')->toContain('1,000/1,000 issues · 100%')->toContain('All fixed');
});

it('leaves the Scan tab exactly as the scan left it while and after fixes run', function (): void {
    $status = app(ToolStatus::class);
    $status->ran('phpstan', ['ran' => true, 'took' => 59219, 'findings' => array_fill(0, 3, ['x'])]);
    $before = (string) file_get_contents($status->path());

    $this->progress->begin('studio/fixes-2026-10-06-1900', ['phpstan'], ['phpstan' => 3]);
    $this->progress->running(['phpstan']);
    $this->progress->fixing(['phpstan' => 2]);
    $this->progress->done('phpstan', true, 2, 1, 3);
    $this->progress->rechecking('phpstan');
    $this->progress->left('phpstan', 1);
    $this->progress->finished([]);

    expect((string) file_get_contents($status->path()))->toBe($before);
});

it('lines the fixable rules up from the scan before the fixes are asked for, and waits for the fixes to be asked for in the app', function (): void {
    $status = app(ToolStatus::class);
    $status->forget();
    $status->running(['phpstan', 'pint', 'house-check', 'studio-security']);
    $status->ran('studio-security', ['ran' => true, 'took' => 4000, 'findings' => array_fill(0, 202, ['x'])]);
    $status->testsFinished(['ran' => true, 'took' => 117000, 'summary' => ['tests' => 3582, 'failed' => 22, 'skipped' => 3]]);
    $status->ran('tests', ['ran' => true, 'took' => 117000, 'findings' => array_fill(0, 22, ['x'])]);
    $status->ran('phpstan', ['ran' => true, 'took' => 59000, 'findings' => array_fill(0, 2445, ['x'])]);
    $status->ran('pint', ['ran' => true, 'took' => 3900, 'findings' => array_fill(0, 47, ['x'])]);
    $status->ran('house-check', ['ran' => true, 'took' => 2400, 'findings' => []]);
    ($this->ask)([]);

    $screen = ($this->insights)();
    $before = (string) file_get_contents($status->path());

    expect($screen)->toContain('Attempting to fix grouped issues')
        ->toContain('PHPStan')
        ->toContain('0/2,445')
        ->toContain('Pint')
        ->toContain('0/47')
        ->toContain('Autofixes available')
        ->toContain('22 left out of 3,582')
        ->toContain('0/202')
        ->toContain('SAMI will assist you')
        ->not->toContain('—/22')
        ->not->toContain('ask in the app')
        ->not->toContain('yours to fix')
        ->not->toContain('issues to fix')
        ->not->toContain('start with Fix with SAMI in the app')
        ->toContain('House check')
        ->toContain('all clear')
        ->toContain('Your tests')
        ->not->toContain('press ⏎ to start')
        ->and(strpos($screen, 'Pint'))->toBeLessThan(strpos($screen, 'PHPStan'))
        ->and((string) file_get_contents($status->path()))->toBe($before);

    ($this->ask)(['phpstan', 'pint', 'studio-security']);

    expect(($this->insights)())->toContain('press ⏎ to start')->not->toContain('Autofixes available');

    $status->forget();
});

it('titles the table with how many grouped issues Insights lists, the same number the Scan tab gives, once the studio has said', function (): void {
    $this->progress->begin('studio/fixes-2026-10-06-1900', ['phpstan'], ['phpstan' => 2445]);
    $this->progress->running(['phpstan']);

    expect(($this->insights)())->toContain('0/2,445 issues')->toContain('Attempting to fix grouped issues');

    $this->status->grouped(['problems' => 5351, 'open' => 5036]);

    expect(($this->insights)())->toContain('5,036 grouped issues attempting to autofix')->toContain('0/2,445 issues')->not->toContain('Attempting to fix grouped issues');
});

it('shows the tests row as all clear, with how many ran, once none are failing', function (): void {
    $this->status->testsFinished(['ran' => true, 'took' => 117000, 'summary' => ['tests' => 3587, 'failed' => 0, 'skipped' => 3]]);
    $this->status->ran('tests', ['ran' => true, 'took' => 117000, 'findings' => []]);

    $screen = ($this->insights)();
    $row = collect(explode("\n", $screen))->first(fn (string $line): bool => str_contains($line, 'Your tests'));

    expect($row)->toContain('0 left out of 3,587')->toContain('all clear');
});
