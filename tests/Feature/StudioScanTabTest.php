<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\FreshSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Scan\ScanRules;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\StudioTabs;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| The Scan tab
|--------------------------------------------------------------------------
|
| Every rule the scan checks, in the order of its group, with how many issues
| each caught. Initial (Pint and the tests) runs for everyone; the rest is
| whatever the developer picked in the app.
|
*/

beforeEach(function (): void {
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 62, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048]]);
        }

        public function forget(): void {}
    });
    $this->plain = fn (string $screen): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
    $this->root = sys_get_temp_dir().'/studio-scan-tab-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/vendor/bin', 0755, true);
    touch($this->root.'/vendor/bin/pint');
    app()->instance(ToolStatus::class, new ToolStatus($this->root));
    $this->status = app(ToolStatus::class);
    @unlink($this->status->path());
    $this->studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $this->scan = fn (): string => ($this->plain)($this->studio->render(170, 40, 'scan'));
});

afterEach(function (): void {
    @unlink($this->status->path());
});

it('sits before Insights, and the static analysis tab is gone', function (): void {
    $tabs = collect(app(StudioTabs::class)->tabs())->map(fn ($tab): string => $tab->getKey())->all();

    expect($tabs)->toBe(['dashboard', 'scan', 'insights', 'build', 'activity'])
        ->not->toContain('static-analysis');
})->skip(fn (): bool => ! in_array('studio:scan', array_keys(Artisan::all()), true), 'the studio commands are not registered in this run');

it('lists Pint and your tests first as Initial, waiting, before anything has run', function (): void {
    $screen = ($this->scan)();

    expect($screen)->toContain('[scan.idle]')->toContain('Initial')->toContain('Pint')->toContain('Your tests')->toContain('waiting')
        ->and(strpos($screen, 'Pint'))->toBeLessThan(strpos($screen, 'Your tests'));
});

it('counts the issues each rule caught, group by group, Initial first, then whatever was picked', function (): void {
    $this->status->asked(['pint', 'tests']);
    $this->status->pick(['phpstan', 'rector', 'composer-audit']);
    $this->status->running(['pint', 'rector', 'composer-audit', 'phpstan']);
    $this->status->ran('pint', ['ran' => true, 'findings' => array_fill(0, 47, ['x'])]);
    $this->status->ran('rector', ['ran' => true, 'findings' => array_fill(0, 3, ['x'])]);
    $this->status->ran('composer-audit', ['ran' => true, 'findings' => []]);
    $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 3577, 'failed' => 2, 'skipped' => 3]]);
    $rows = app(ScanRules::class)->rows();

    expect(collect($rows)->pluck('key')->all())->toBe(['pint', 'tests', 'rector', 'composer-audit', 'phpstan'])
        ->and(collect($rows)->pluck('group')->all())->toBe(['Initial', 'Initial', 'General', 'Security', 'Advanced'])
        ->and(collect($rows)->pluck('caught', 'key')->all())->toBe(['pint' => 47, 'tests' => 2, 'rector' => 3, 'composer-audit' => 0, 'phpstan' => null])
        ->and(app(ScanRules::class)->caught())->toBe(52);

    $screen = ($this->scan)();

    expect($screen)->toContain('52 issues')->toContain('detected across 5 rules')->toContain('Issues')->toContain('detected')->toContain('Initial')->toContain('General')->toContain('Security')->toContain('Advanced')
        ->toContain('2 failing of 3,577')->toContain('all clear')->toContain('waiting')
        ->and(strpos($screen, 'Pint'))->toBeLessThan(strpos($screen, 'Rector'))
        ->and(strpos($screen, 'Rector'))->toBeLessThan(strpos($screen, 'composer audit'))
        ->and(strpos($screen, 'composer audit'))->toBeLessThan(strpos($screen, 'PHPStan'));
});

it('says when the tests went well, off when left off, and follows the tests with code coverage', function (): void {
    $this->status->asked(['pint', 'tests']);
    $this->status->pick(['pest-coverage']);
    $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 100, 'failed' => 0, 'skipped' => 0]]);

    expect(collect(app(ScanRules::class)->rows())->firstWhere('key', 'pest-coverage'))->toMatchArray(['state' => 'waiting', 'note' => 'waiting its turn']);

    $this->status->coverageFinished(['ran' => true, 'findings' => array_fill(0, 30, ['x']), 'summary' => ['coverage' => 82]]);

    expect(collect(app(ScanRules::class)->rows())->firstWhere('key', 'pest-coverage'))->toMatchArray(['state' => 'done', 'caught' => 30, 'note' => '82% covered'])
        ->and(collect(app(ScanRules::class)->rows())->firstWhere('key', 'tests'))->toMatchArray(['state' => 'done', 'caught' => 0, 'note' => '100 passed']);

    $this->status->testsOff();

    expect(collect(app(ScanRules::class)->rows())->firstWhere('key', 'tests'))->toMatchArray(['state' => 'off']);
});

it('counts everything a rule caught, however many, and shows a dash while it is unknown', function (): void {
    $this->status->asked(['pint', 'tests']);
    $this->status->pick(['second-check', 'pest-coverage']);
    $this->status->running(['pint', 'second-check']);
    $this->status->ran('second-check', ['ran' => true, 'findings' => array_fill(0, 5074, ['x'])]);

    $rows = collect(app(ScanRules::class)->rows());

    expect($rows->firstWhere('key', 'second-check')['caught'])->toBe(5074)
        ->and($rows->firstWhere('key', 'pest-coverage')['name'])->toBe('Pest code coverage');

    $screen = ($this->scan)();

    expect($screen)->toContain('5,074')->toContain('Pest code coverage')->not->toContain('pest-coverage')
        ->and(preg_match('/Pint\s+0/', $screen))->toBe(0);
});

it('starts over when the studio resets the scan: Pint and the tests waiting, nothing an earlier scan left on this machine', function (): void {
    $this->status->asked(['pint', 'tests']);
    $this->status->pick(['phpstan', 'rector']);
    $this->status->running(['pint', 'rector', 'phpstan']);
    $this->status->ran('rector', ['ran' => true, 'findings' => [['x']], 'took' => 20_000]);
    $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);
    app()->instance(SnapshotSource::class, new FreshSnapshots);
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());

    $screen = ($this->plain)($studio->render(170, 40, 'scan'));

    expect($screen)->toContain('Pint')->toContain('Your tests')->toContain('[scan.idle]')->toContain('0 issues')
        ->not->toContain('Rector')->not->toContain('PHPStan')->not->toContain('10 passed')
        ->and(collect(app(ScanRules::class)->forStudio(false)->rows())->pluck('key')->all())->toBe(['pint', 'tests']);
});

it('shows how long each rule takes: counting while it runs, and its time once done', function (): void {
    $this->status->asked(['pint', 'tests']);
    $this->status->pick(['rector', 'phpstan']);
    $this->status->running(['pint', 'rector', 'phpstan']);
    $this->status->ran('pint', ['ran' => true, 'findings' => [], 'took' => 4_700]);
    $this->status->ran('rector', ['ran' => true, 'findings' => [['x']], 'took' => 26_800]);
    $this->status->testsFinished(['ran' => true, 'took' => 125_000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);

    $rows = collect(app(ScanRules::class)->rows());

    expect($rows->pluck('elapsed', 'key')->only(['pint', 'rector', 'tests'])->all())->toBe(['pint' => 4_700, 'tests' => 125_000, 'rector' => 26_800])
        ->and($rows->firstWhere('key', 'phpstan')['elapsed'])->toBeNull();

    $screen = ($this->scan)();

    expect($screen)->toContain('Time')->toContain('4.7s')->toContain('26s')->toContain('2m 05s');
});

it('says what it is doing while rules run, and that it is all checked once they have', function (): void {
    $this->status->asked(['pint', 'tests']);
    $this->status->running(['pint']);
    $this->status->testsRunning();

    expect(($this->scan)())->toContain('[scan.checking]')->toContain('running');

    $this->status->ran('pint', ['ran' => true, 'findings' => []]);
    $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);

    expect(($this->scan)())->toContain('[scan.done]')->toContain('0 issues');
});

it('measures coverage quietly: the tests row keeps its first result and only the coverage row shows the second run', function (): void {
    $this->status->asked(['pint', 'tests']);
    $this->status->pick(['pest-coverage']);
    $this->status->testsFinished(['ran' => true, 'took' => 58_000, 'summary' => ['tests' => 3578, 'failed' => 0, 'skipped' => 3]]);

    expect(collect(app(ScanRules::class)->rows())->firstWhere('key', 'pest-coverage'))->toMatchArray(['state' => 'waiting', 'note' => 'waiting its turn']);

    $this->status->coverageRunning();
    $rows = collect(app(ScanRules::class)->rows());

    expect($rows->firstWhere('key', 'tests'))->toMatchArray(['state' => 'done', 'note' => '3,578 passed', 'elapsed' => 58_000])
        ->and($rows->firstWhere('key', 'pest-coverage'))->toMatchArray(['state' => 'running', 'note' => 'checking'])
        ->and($this->status->tests()['state'])->toBe(ToolStatus::RAN)
        ->and($this->status->testsAreRunning())->toBeFalse();

    $this->status->coverageFinished(['ran' => true, 'findings' => array_fill(0, 336, ['x']), 'summary' => ['coverage' => 63]]);

    expect(collect(app(ScanRules::class)->rows())->firstWhere('key', 'pest-coverage'))->toMatchArray(['state' => 'done', 'caught' => 336, 'note' => '63% covered']);
});

it('knows the tests are running while they do, so the other checks wait, and does not wait on a run that never finished', function (): void {
    expect($this->status->testsAreRunning())->toBeFalse();

    $this->status->testsRunning();

    expect($this->status->testsAreRunning())->toBeTrue();

    $this->status->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 1, 'failed' => 0, 'skipped' => 0]]);

    expect($this->status->testsAreRunning())->toBeFalse();

    file_put_contents($this->status->path(), (string) json_encode(['tests' => ['state' => ToolStatus::RUNNING, 'at' => now()->subHours(2)->toIso8601String()]]));

    expect(app(ToolStatus::class)->testsAreRunning())->toBeFalse();
});

it('never loses a change when several processes write the status at once', function (): void {
    $root = sys_get_temp_dir().'/studio-race-root-'.bin2hex(random_bytes(3));
    $status = new ToolStatus($root);
    @unlink($status->path());
    $status->running(['x']);

    $script = sys_get_temp_dir().'/studio-race-'.bin2hex(random_bytes(3)).'.php';
    file_put_contents($script, '<?php require "'.dirname(__DIR__, 2).'/vendor/autoload.php"; ArtisanStudio\StudioCli\StateFile::keepIn($argv[3]); $status = new ArtisanStudio\StudioCli\Scan\ToolStatus($argv[1]); for ($i = 0; $i < 40; $i++) { $status->started("tool-".$argv[2]."-".$i); }');

    $workers = collect(range(1, 6))->map(function (int $worker) use ($script, $root, $status): Process {
        $process = new Process([PHP_BINARY, $script, $root, (string) $worker, dirname($status->path())]);
        $process->start();

        return $process;
    });

    $workers->each(fn (Process $process) => $process->wait());

    expect((array) json_decode((string) file_get_contents($status->path()), true)['run']['starts'])->toHaveCount(240);

    @unlink($status->path());
    @unlink($script);
});

it('shows the problems Insights will list in the headline, with why, once the studio has said, and the issues until then', function (): void {
    $this->status->asked(['pint', 'tests']);
    $this->status->running(['pint']);
    $this->status->ran('pint', ['ran' => true, 'findings' => array_fill(0, 47, ['x'])]);

    expect(($this->scan)())->toContain('47 issues')->toContain('detected across 2 rules')->not->toContain('problems');

    $this->status->grouped(['problems' => 30, 'open' => 25]);

    expect(($this->scan)())->toContain('25 problems in Insights, from 47 issues across 2 rules, repeats grouped')->not->toContain('detected across');

    $this->status->grouped(['problems' => 1, 'open' => 1]);

    expect(($this->scan)())->toContain('1 problem in Insights');
});
