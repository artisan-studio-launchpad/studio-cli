<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFixReportRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanToolResultsRequest;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\TaskJournal;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| A fix run, for real, on a project of its own
|--------------------------------------------------------------------------
|
| The run cuts its own branch, commits each rule on its own, and checks the
| rule again the moment its commit lands: the fix report goes first, so the
| studio knows what was fixed, then what is left, so the score moves
| card by card.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-fix-run-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app', 0755, true);
    symlink(dirname(__DIR__, 2).'/vendor', $this->root.'/vendor');
    file_put_contents($this->root.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    file_put_contents($this->root.'/.gitignore', "/vendor\n");
    file_put_contents($this->root.'/app/Order.php', "<?php\n\nnamespace App;\n\nclass Order\n{\n    public function total( ){return 1;}\n}\n");
    $this->git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    ($this->git)('init', '--quiet', '--initial-branch=develop');
    ($this->git)('config', 'user.email', 'dev@example.com');
    ($this->git)('config', 'user.name', 'Developer');
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'start');
    app()->setBasePath($this->root);

    Saloon::fake([
        SubmitScanFixReportRequest::class => MockResponse::make(['received' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
});

afterEach(function (): void {
    (new FixProgress($this->root))->forget();
});

it('fixes a rule on a new branch, then checks it again and sends what is left, after the fix report', function (): void {
    $this->artisan('studio:fix', ['--tool' => ['pint'], '--yes' => true, '--plain' => true])->assertSuccessful();

    $progress = (new FixProgress($this->root))->read();

    expect(($this->git)('branch', '--show-current'))->toStartWith('studio/fixes-')
        ->and(($this->git)('log', '-1', '--format=%s'))->toBe('style(pint): SAMI ran Pint\'s own fixer on 1 file')
        ->and(($this->git)('show', '--name-only', '--format=', 'HEAD'))->toBe('app/Order.php')
        ->and(($this->git)('status', '--porcelain', '--untracked-files=no'))->toBe('')
        ->and($progress['phase'])->toBe(FixProgress::FINISHED)
        ->and($progress['rulesets']['pint'])->toMatchArray(['state' => FixProgress::DONE, 'files' => 1, 'left' => 0, 'rechecking' => false])
        ->and((new FixProgress($this->root))->reports()['pint']['commit'])->toBe(($this->git)('rev-parse', 'HEAD'));

    $sent = collect(Saloon::mockClient()->getRecordedResponses())->map(fn ($response): array => [class_basename($response->getRequest()), $response->getPendingRequest()->body()->all()]);
    $report = $sent->search(fn (array $request): bool => $request[0] === 'SubmitScanFixReportRequest' && isset($request[1]['rulesets']['pint']));
    $results = $sent->search(fn (array $request): bool => $request[0] === 'SubmitScanToolResultsRequest');

    $live = $sent->filter(fn (array $request): bool => $request[0] === 'SubmitScanFixReportRequest' && isset($request[1]['live']))->map(fn (array $request): array => $request[1]['live']);

    expect($report)->toBeInt()
        ->and($results)->toBeGreaterThan($report)
        ->and($sent[$results][1]['partial'])->toBeTrue()
        ->and($sent[$results][1]['tools']['pint'])->toMatchArray(['ran' => true, 'findings' => []])
        ->and($live->first())->toMatchArray(['phase' => FixProgress::FIXING])
        ->and($live->first()['rulesets']['pint'])->toMatchArray(['state' => FixProgress::WAITING])
        ->and($live->last())->toMatchArray(['phase' => FixProgress::FINISHED])
        ->and($live->last()['rulesets']['pint'])->toMatchArray(['state' => FixProgress::DONE, 'left' => 0]);
});

it('says the score has been updated once, when every rule has been checked again', function (): void {
    $journal = $this->root.'/journal.log';
    app()->instance(TaskJournal::class, new TaskJournal($journal));

    $this->artisan('studio:fix', ['--tool' => ['pint'], '--yes' => true, '--plain' => true])->assertSuccessful();

    $lines = TaskJournal::readFrom($journal, 0)['lines'];

    expect(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'Your score has been updated')))->toHaveCount(1)
        ->and(end($lines))->toStartWith('Your score has been updated');
});

it('leaves the Scan tab as the scan left it, however the fix run checks a rule again', function (): void {
    $status = app(ToolStatus::class);
    $status->forget();
    $status->ran('pint', ['ran' => true, 'took' => 3866, 'findings' => array_fill(0, 47, ['x'])]);
    $before = $status->outcome('pint');
    $recorded = (string) file_get_contents($status->path());

    $this->artisan('studio:fix', ['--tool' => ['pint'], '--yes' => true, '--plain' => true])->assertSuccessful();

    expect($status->outcome('pint'))->toBe($before)
        ->and((string) file_get_contents($status->path()))->toBe($recorded);

    $status->forget();
});
it('says why it would not start, for the Insights tab, and changes nothing', function (): void {
    file_put_contents($this->root.'/app/Order.php', "<?php\n// mine\n");

    $this->artisan('studio:fix', ['--tool' => ['pint'], '--yes' => true, '--plain' => true])->assertFailed();

    expect(($this->git)('branch', '--show-current'))->toBe('develop')
        ->and((new FixProgress($this->root))->read())->toMatchArray(['phase' => FixProgress::REFUSED, 'reason' => 'You have uncommitted changes. Commit or stash them first, so SAMI\'s fixes stay apart from yours.']);
});

it('tells the studio which files each rule has fixed so far, summed across rules, as it works', function (): void {
    $progress = new FixProgress(sys_get_temp_dir().'/studio-live-'.bin2hex(random_bytes(4)));
    $told = [];
    $progress->listen(function (array $state) use (&$told): void {
        $told = $state;
    });

    $progress->begin('studio/fixes-live', ['phpstan', 'pint'], ['phpstan' => 3, 'pint' => 2]);
    $progress->fixing(['phpstan' => 3], ['phpstan' => ['app/A.php' => 2, 'app/B.php' => 1]]);
    $progress->fixedIn('pint', ['app/A.php' => 2]);

    expect(FixProgress::live($told)['files'])->toBe([['path' => 'app/A.php', 'fixed' => 4], ['path' => 'app/B.php', 'fixed' => 1]]);

    $progress->forget();
});

it('hands the studio each file the run changed as its own patch, leaving lock files out', function (): void {
    $base = ($this->git)('rev-parse', 'HEAD');
    ($this->git)('switch', '--quiet', '-c', 'studio/fixes-diffs');
    file_put_contents($this->root.'/app/Order.php', "<?php\n\nnamespace App;\n\nclass Order\n{\n    public function total(): int\n    {\n        return 1;\n    }\n}\n");
    file_put_contents($this->root.'/composer.lock', '{}');
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'fix');

    $changes = (new LocalBranch($this->root))->changesSince($base);

    expect(array_column($changes, 'path'))->toBe(['app/Order.php'])
        ->and($changes[0]['patch'])->toStartWith('diff --git a/app/Order.php b/app/Order.php')
        ->toContain('-    public function total( ){return 1;}')
        ->toContain('+    public function total(): int');
});

it('never commits anywhere but the studio\'s fixes branch, so a run the developer walked away from leaves their branch alone', function (): void {
    file_put_contents($this->root.'/app/Order.php', "<?php\n\nnamespace App;\n\nclass Order {}\n");
    $head = ($this->git)('rev-parse', 'HEAD');

    expect((new LocalBranch($this->root))->commit('fix: should not land'))->toBeNull()
        ->and(($this->git)('rev-parse', 'HEAD'))->toBe($head)
        ->and((new LocalBranch($this->root))->isOnFixes())->toBeFalse();
});
