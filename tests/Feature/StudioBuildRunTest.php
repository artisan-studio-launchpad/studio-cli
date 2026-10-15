<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanWorkRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFixReportRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanToolResultsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanWorkRequest;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use ArtisanStudio\StudioCli\Work\BuildRun;
use ArtisanStudio\StudioCli\Work\StepChecks;
use ArtisanStudio\StudioCli\Work\WorkProgress;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Building an idea planned in the studio, for real, on a project of its own
|--------------------------------------------------------------------------
|
| A real git repository. The studio is faked: for each step the terminal
| sends, it answers with the whole file for each it creates and edits for
| each it changes. The run cuts the plan's branch, writes and
| commits each step on its own, and stops once at the end for the developer.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-build-run-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Models', 0755, true);
    mkdir($this->root.'/routes', 0755, true);
    file_put_contents($this->root.'/app/Models/Project.php', "<?php\n\nnamespace App\\Models;\n\nclass Project\n{\n    public function name(): string\n    {\n        return 'project';\n    }\n}\n");
    file_put_contents($this->root.'/app/Models/User.php', "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n}\n");
    file_put_contents($this->root.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    $this->git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    ($this->git)('init', '--quiet', '--initial-branch=develop');
    ($this->git)('config', 'user.email', 'dev@example.com');
    ($this->git)('config', 'user.name', 'Developer');
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'start');

    $this->plan = [
        'summary' => 'Coverage for every file of a run.',
        'branch' => 'studio/build-coverage-reports',
        'questions' => [],
        'read' => ['routes/web.php'],
        'steps' => [
            ['number' => 1, 'role' => 'mason', 'name' => 'Build', 'tone' => '#9d7bff', 'title' => 'The coverage data', 'line' => 'I add the model and its relation.', 'files' => [
                ['path' => 'app/Models/CoverageRun.php', 'action' => 'create', 'what' => 'The coverage of one run.', 'pattern' => 'app/Models/User.php'],
                ['path' => 'app/Models/Project.php', 'action' => 'change', 'what' => 'Its coverage runs.', 'pattern' => ''],
            ]],
            ['number' => 2, 'role' => 'prover', 'name' => 'Test', 'tone' => '#3b82ff', 'title' => 'Tests that prove it', 'line' => 'I prove it.', 'files' => [
                ['path' => 'tests/Feature/CoverageRunTest.php', 'action' => 'create', 'what' => 'Proves the model.', 'pattern' => ''],
            ]],
        ],
    ];
    $this->ask = fn (): array => ['id' => 9, 'job' => 'build', 'mode' => 'terminal', 'state' => 'asked', 'name' => 'Coverage reports', 'plan' => $this->plan, 'files' => []];
    $this->written = [
        'app/Models/CoverageRun.php' => ['content' => "<?php\n\nnamespace App\\Models;\n\nclass CoverageRun\n{\n}\n", 'edits' => [], 'note' => 'The coverage of one run.'],
        'app/Models/Project.php' => ['content' => null, 'edits' => [['find' => "        return 'project';\n    }\n", 'replace' => "        return 'project';\n    }\n\n    public function coverageRuns(): array\n    {\n        return [];\n    }\n"]], 'note' => 'Its coverage runs.'],
        'tests/Feature/CoverageRunTest.php' => ['content' => "<?php\n\nit('has coverage runs', function (): void {\n    expect(true)->toBeTrue();\n});\n", 'edits' => [], 'note' => 'Proves the model.'],
    ];
    $this->replies = [];
    $this->answered = [];
    $this->answer = ['decision' => 'works', 'note' => null];
    $this->proposals = fn (): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->filter(fn ($response): bool => class_basename($response->getRequest()) === 'SubmitScanWorkRequest')
        ->map(fn ($response): array => $response->getPendingRequest()->body()->all())
        ->filter(fn (array $body): bool => isset($body['propose']))
        ->values()
        ->all();
    $this->sent = fn (string $request): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->filter(fn ($response): bool => class_basename($response->getRequest()) === $request)
        ->map(fn ($response): array => $response->getPendingRequest()->body()->all())
        ->values()
        ->all();
    $this->prepared = function (): ?array {
        $last = collect(($this->proposals)())->last();

        if ($last === null || ($last['propose']['done'] ?? false) || ($last['propose']['more'] ?? false)) {
            return null;
        }

        $id = $last['propose']['id'];
        $this->answered[$id] ??= collect($last['propose']['files'])->mapWithKeys(fn (array $file): array => [$file['path'] => ($this->replies[$file['path']] ?? []) === [] ? $this->written[$file['path']] : array_shift($this->replies[$file['path']])])->all();

        return ['id' => $id, 'state' => 'ready', 'files' => $this->answered[$id], 'working' => []];
    };
    $this->run = fn (): BuildRun => new BuildRun($this->root, app(Studio::class), new LocalBranch($this->root), new WorkProgress($this->root), new TaskJournal(null), new StepChecks($this->root, new Toolbox($this->root)), 0, 0);

    Saloon::fake([
        ShowScanWorkRequest::class => fn () => MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => $this->answer]], 'rechecks' => []]]),
        SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
});

afterEach(function (): void {
    (new WorkProgress($this->root))->forget();
});

it('builds a planned idea step by step on its own branch, commits each step, and stops once at the end for the developer to try it', function (): void {
    $project = (string) file_get_contents($this->root.'/app/Models/Project.php');
    $line = ($this->run)()->run(($this->ask)());
    $work = ($this->sent)('SubmitScanWorkRequest');
    $asked = collect($work)->filter(fn (array $report): bool => isset($report['question']))->values();
    $steps = collect(($this->proposals)())->filter(fn (array $body): bool => isset($body['propose']['files']))->values();
    $last = end($work);

    expect(($this->git)('branch', '--show-current'))->toBe('studio/build-coverage-reports')
        ->and(($this->git)('log', '--reverse', '--format=%s', 'develop..HEAD'))->toBe("feat(studio): the coverage data\nfeat(studio): tests that prove it")
        ->and(($this->git)('show', '--name-only', '--format=', 'HEAD~1'))->toBe("app/Models/CoverageRun.php\napp/Models/Project.php")
        ->and(($this->git)('status', '--porcelain'))->toBe('')
        ->and(file_get_contents($this->root.'/app/Models/Project.php'))->toContain('public function coverageRuns(): array')->toContain("return 'project';")
        ->and(file_get_contents($this->root.'/tests/Feature/CoverageRunTest.php'))->toContain('has coverage runs')
        ->and($steps)->toHaveCount(2)
        ->and($steps->pluck('propose.kind')->all())->toBe(['build', 'build'])
        ->and($steps->pluck('propose.step')->all())->toBe([1, 2])
        ->and($steps[0]['propose']['files'])->toBe([['path' => 'app/Models/CoverageRun.php', 'content' => ''], ['path' => 'app/Models/Project.php', 'content' => $project]])
        ->and(array_column($steps[0]['propose']['related'], 'path'))->toBe(['app/Models/User.php', 'routes/web.php'])
        ->and(array_column($steps[1]['propose']['related'], 'path'))->toContain('app/Models/CoverageRun.php')->toContain('app/Models/Project.php')
        ->and($asked)->toHaveCount(1)
        ->and($asked[0]['question'])->toMatchArray(['kind' => 'final', 'batch' => 0, 'options' => ['works', 'broke']])
        ->and($asked[0]['question']['reason'])->toContain('I committed all 2 steps on studio/build-coverage-reports: The coverage data and Tests that prove it.')->toContain('Every file compiles.')->toContain('Try your app on studio/build-coverage-reports')
        ->and($last)->toMatchArray(['session' => 9, 'state' => 'done'])
        ->and(collect($last['batches'])->pluck('state')->all())->toBe(['committed', 'committed'])
        ->and(collect($last['batches'])->pluck('rule')->all())->toBe(['The coverage data', 'Tests that prove it'])
        ->and($last['batches'][0])->toMatchArray(['fixed' => 2, 'left' => 0])
        ->and(($this->sent)('SubmitScanFixReportRequest'))->toBe([])
        ->and($line)->toContain('Coverage reports on studio/build-coverage-reports: 2 of 2 steps committed. Nothing was pushed.');
});

it('writes nothing that does not compile, asks the studio once more with what PHP said, and commits it once it does', function (): void {
    $this->replies = ['app/Models/CoverageRun.php' => [['content' => "<?php\n\nclass CoverageRun {\n", 'edits' => [], 'note' => '']]];

    ($this->run)()->run(($this->ask)());
    $steps = collect(($this->proposals)())->filter(fn (array $body): bool => isset($body['propose']['files']))->values();

    expect($steps)->toHaveCount(3)
        ->and(array_column($steps[1]['propose']['files'], 'path'))->toBe(['app/Models/CoverageRun.php'])
        ->and($steps[1]['propose']['step'])->toBe(1)
        ->and($steps[1]['propose']['failed'])->toContain('app/Models/CoverageRun.php:')->toContain('line')
        ->and(file_get_contents($this->root.'/app/Models/CoverageRun.php'))->toContain('class CoverageRun')->toContain('}')
        ->and(($this->git)('show', '--name-only', '--format=', 'HEAD~1'))->toBe("app/Models/CoverageRun.php\napp/Models/Project.php");
});

it('leaves out what still does not compile after the second try, and says so on the step', function (): void {
    $this->replies = ['app/Models/CoverageRun.php' => [['content' => "<?php\n\nclass CoverageRun {\n", 'edits' => [], 'note' => ''], ['content' => "<?php\n\nclass CoverageRun {\n", 'edits' => [], 'note' => '']]];

    ($this->run)()->run(($this->ask)());
    $work = ($this->sent)('SubmitScanWorkRequest');
    $first = collect(end($work)['batches'])->firstWhere('number', 1);

    expect(file_exists($this->root.'/app/Models/CoverageRun.php'))->toBeFalse()
        ->and(($this->git)('show', '--name-only', '--format=', 'HEAD~1'))->toBe('app/Models/Project.php')
        ->and($first)->toMatchArray(['state' => 'committed', 'fixed' => 1, 'left' => 1])
        ->and($first['note'])->toContain('I could not write app/Models/CoverageRun.php');
});

it('never writes over a file that is already here when the studio sends it as new', function (): void {
    $this->written['app/Models/CoverageRun.php'] = ['content' => "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n    // replaced\n}\n", 'edits' => [], 'note' => ''];
    $this->plan['steps'][0]['files'][0]['path'] = 'app/Models/User.php';
    $this->written['app/Models/User.php'] = $this->written['app/Models/CoverageRun.php'];

    ($this->run)()->run(($this->ask)());
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(file_get_contents($this->root.'/app/Models/User.php'))->not->toContain('replaced')
        ->and(collect(end($work)['batches'])->firstWhere('number', 1)['note'])->toContain('I could not write app/Models/User.php: It is already on your machine');
});

it('changes nothing on a tree with changes not committed', function (): void {
    file_put_contents($this->root.'/routes/web.php', "<?php\n// mine\n");

    $line = ($this->run)()->run(($this->ask)());

    expect($line)->toContain('You have uncommitted changes')
        ->and(($this->git)('branch', '--show-current'))->toBe('develop')
        ->and(($this->proposals)())->toBe([]);
});

it('starts a build branch by the plan\'s name, numbers it on when that name is taken, and starts no branch that is not the studio\'s', function (): void {
    $branch = new LocalBranch($this->root);

    expect($branch->startAt('studio/build-coverage-reports'))->toBe('studio/build-coverage-reports')
        ->and($branch->switchTo('develop'))->toBeTrue()
        ->and($branch->startAt('studio/build-coverage-reports'))->toBe('studio/build-coverage-reports-2')
        ->and($branch->switchTo('develop'))->toBeTrue()
        ->and($branch->startAt('develop-copy'))->toBeNull()
        ->and($branch->isOnStudioBranch())->toBeFalse()
        ->and(LocalBranch::isStudioBranch('studio/build-x'))->toBeTrue()
        ->and(LocalBranch::isStudioBranch('studio/fixes-x'))->toBeTrue()
        ->and(LocalBranch::isStudioBranch('feature/x'))->toBeFalse();
});
