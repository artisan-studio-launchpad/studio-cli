<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Bridge\BridgeNotConnected;
use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanWorkRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFixReportRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanToolResultsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanWorkRequest;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use ArtisanStudio\StudioCli\Tests\Support\FakeTestRunner;
use ArtisanStudio\StudioCli\Tests\Support\SecretLookingFiles;
use ArtisanStudio\StudioCli\Work\BuildRun;
use ArtisanStudio\StudioCli\Work\StepChecks;
use ArtisanStudio\StudioCli\Work\WorkProgress;
use Illuminate\Support\Facades\Process as Processes;
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

it('writes no file, cuts no branch and commits nothing while the bridge is not connected', function (): void {
    app()->instance(BridgeGate::class, new class extends BridgeGate
    {
        public function __construct() {}

        public function status(): string
        {
            return self::ATTENTION;
        }
    });
    $before = (string) file_get_contents($this->root.'/app/Models/Project.php');

    expect(fn () => ($this->run)()->run(($this->ask)()))->toThrow(BridgeNotConnected::class, __('studio-cli::bridge.blocked'));

    expect(file_get_contents($this->root.'/app/Models/Project.php'))->toBe($before)
        ->and(is_file($this->root.'/app/Models/CoverageRun.php'))->toBeFalse()
        ->and(($this->git)('branch', '--show-current'))->toBe('develop')
        ->and(($this->git)('log', '--format=%s', '-1'))->not->toStartWith('feat(studio)');
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
        ->and($asked[0]['question'])->toMatchArray(['kind' => 'final', 'batch' => 0, 'options' => ['works', 'broke', 'retry']])
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

it('opens the finished build\'s page, and has the step that wrote the file the app names write it again from what the app said', function (): void {
    file_put_contents($this->root.'/artisan', <<<'PHP'
        <?php
        $broken = str_contains((string) file_get_contents(__DIR__.'/app/Models/CoverageRun.php'), 'Broken');
        file_put_contents(__DIR__.'/opened.log', ($argv[2] ?? '')."\n", FILE_APPEND);
        echo json_encode(['path' => $argv[2] ?? '', 'status' => $broken ? 500 : 200, 'redirect' => null, 'error' => $broken ? 'RuntimeException: The model is Broken' : null, 'where' => $broken ? 'app/Models/CoverageRun.php:5' : null]);
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $this->plan['built_at'] = '/coverage';
    $this->replies['app/Models/CoverageRun.php'] = [
        ['content' => "<?php\n\nnamespace App\\Models;\n\nclass CoverageRunBroken\n{\n}\n", 'edits' => [], 'note' => 'Written.'],
        ['content' => "<?php\n\nnamespace App\\Models;\n\nclass CoverageRun\n{\n}\n", 'edits' => [], 'note' => 'Fixed.'],
    ];

    ($this->run)()->run(($this->ask)());

    $opened = array_values(array_filter(explode("\n", (string) file_get_contents($this->root.'/opened.log')), fn (string $line): bool => $line === '/coverage'));
    $failed = collect(($this->proposals)())->pluck('propose.failed')->filter()->values()->all();
    $again = collect(($this->proposals)())->first(fn (array $body): bool => ($body['propose']['failed'] ?? null) !== null);

    expect($again['propose']['files'])->toBe([['path' => 'app/Models/CoverageRun.php', 'content' => "<?php\n\nnamespace App\\Models;\n\nclass CoverageRunBroken\n{\n}\n"]])
        ->and($opened)->toHaveCount(2)
        ->and($failed)->toBe(['Opening /coverage in the app failed with: RuntimeException: The model is Broken (app/Models/CoverageRun.php:5)'])
        ->and(file_get_contents($this->root.'/app/Models/CoverageRun.php'))->toContain('class CoverageRun')->not->toContain('Broken')
        ->and(($this->git)('status', '--porcelain'))->toBe('?? opened.log');
});

it('writes again only the build\'s own file the app names, never the prototype\'s, when the finished page does not render', function (): void {
    file_put_contents($this->root.'/artisan', <<<'PHP'
        <?php
        $path = $argv[2] ?? '';
        $broken = $path === '/coverage' && str_contains((string) file_get_contents(__DIR__.'/routes/web.php'), 'Broken');
        echo json_encode(['path' => $path, 'status' => $broken ? 500 : 200, 'redirect' => null, 'error' => $broken ? 'ErrorException: The route is Broken' : null, 'where' => $broken ? 'routes/web.php:6' : null]);
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->plan['prototype_at'] = '/prototype/coverage';
    $this->plan['built_at'] = '/coverage';
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => 'app/Models/Prototype/CoverageRun.php', 'action' => 'create', 'what' => 'Sample coverage.', 'pattern' => ''],
            ['path' => 'routes/web.php', 'action' => 'change', 'what' => 'Its route.', 'pattern' => ''],
        ]],
        ['number' => 2, 'role' => 'mason', 'name' => 'Build', 'tone' => '#9d7bff', 'title' => 'The real page', 'line' => 'I build it.', 'files' => [
            ['path' => 'app/Models/CoverageRun.php', 'action' => 'create', 'what' => 'The coverage of one run.', 'pattern' => 'app/Models/Prototype/CoverageRun.php'],
            ['path' => 'routes/web.php', 'action' => 'change', 'what' => 'Its route.', 'pattern' => ''],
        ]],
        ['number' => 3, 'role' => 'prover', 'name' => 'Test', 'tone' => '#3b82ff', 'title' => 'Tests that prove it', 'line' => 'I prove it.', 'files' => [
            ['path' => 'tests/Feature/CoverageRunTest.php', 'action' => 'create', 'what' => 'Proves the model.', 'pattern' => ''],
        ]],
    ];
    $this->written['app/Models/Prototype/CoverageRun.php'] = ['content' => "<?php\n\nnamespace App\\Models\\Prototype;\n\nclass CoverageRun\n{\n}\n", 'edits' => [], 'note' => 'Sample coverage.'];
    $this->replies['routes/web.php'] = [
        ['content' => null, 'edits' => [['find' => "use Illuminate\\Support\\Facades\\Route;\n", 'replace' => "use Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/prototype/coverage', fn () => 'sample');\n"]], 'note' => 'The prototype route.'],
        ['content' => null, 'edits' => [['find' => "fn () => 'sample');\n", 'replace' => "fn () => 'sample');\nRoute::get('/coverage', fn () => 'Broken');\n"]], 'note' => 'The real route.'],
        ['content' => null, 'edits' => [['find' => "fn () => 'Broken'", 'replace' => "fn () => 'coverage'"]], 'note' => 'Fixed.'],
    ];

    ($this->run)()->run(($this->ask)());

    $sent = collect(($this->proposals)())->filter(fn (array $body): bool => isset($body['propose']['files']))->values();
    $again = $sent->filter(fn (array $body): bool => ($body['propose']['failed'] ?? null) !== null)->values();

    expect($sent->filter(fn (array $body): bool => in_array('app/Models/Prototype/CoverageRun.php', array_column($body['propose']['files'], 'path'), true)))->toHaveCount(1)
        ->and($again)->toHaveCount(1)
        ->and($again[0]['propose']['step'])->toBe(2)
        ->and(array_column($again[0]['propose']['files'], 'path'))->toBe(['routes/web.php'])
        ->and($again[0]['propose']['failed'])->toBe('Opening /coverage in the app failed with: ErrorException: The route is Broken (routes/web.php:6)')
        ->and(file_get_contents($this->root.'/routes/web.php'))->toContain("Route::get('/prototype/coverage', fn () => 'sample');")->toContain("Route::get('/coverage', fn () => 'coverage');");
});

it('commits the prototype only when the developer signs it off, so nothing they have not seen is committed', function (string $decision, int $commits): void {
    $this->answer = ['decision' => $decision, 'note' => null];
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Wireframe', 'tone' => '#ffb020', 'title' => 'The wireframe', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => 'app/Livewire/Prototype/CoverageReport.php', 'action' => 'create', 'what' => 'The shell.', 'pattern' => ''],
        ]],
    ];
    $this->written['app/Livewire/Prototype/CoverageReport.php'] = ['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n}\n", 'edits' => [], 'note' => 'The shell.'];

    ($this->run)()->run(($this->ask)());

    expect(file_exists($this->root.'/app/Livewire/Prototype/CoverageReport.php'))->toBeTrue()
        ->and(count(array_filter(explode("\n", ($this->git)('log', '--format=%s', 'develop..HEAD')))))->toBe($commits);
})->with([
    'signed off' => ['approve', 1],
    'stopped' => ['stop', 0],
]);

it('copies the signed-off prototype as the base of the real build, and the build changes the copy while the prototype stays as it is', function (): void {
    $prototype = "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n    public array \$runs = ['sample'];\n}\n";
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->plan['copies'] = ['app/Livewire/Prototype/CoverageReport.php' => 'app/Livewire/CoverageReport.php'];
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => 'app/Livewire/Prototype/CoverageReport.php', 'action' => 'create', 'what' => 'Sample coverage.', 'pattern' => ''],
        ]],
        ['number' => 2, 'role' => 'mason', 'name' => 'Build', 'tone' => '#9d7bff', 'title' => 'Real coverage', 'line' => 'I give it real data.', 'files' => [
            ['path' => 'app/Livewire/CoverageReport.php', 'action' => 'change', 'what' => 'Reads the real runs.', 'pattern' => ''],
        ]],
    ];
    $this->written['app/Livewire/Prototype/CoverageReport.php'] = ['content' => $prototype, 'edits' => [], 'note' => 'Sample coverage.'];
    $this->written['app/Livewire/CoverageReport.php'] = ['content' => null, 'edits' => [['find' => "['sample']", 'replace' => '[]']], 'note' => 'Reads the real runs.'];

    ($this->run)()->run(($this->ask)());

    $sent = collect(($this->proposals)())->filter(fn (array $body): bool => ($body['propose']['step'] ?? null) === 2 && isset($body['propose']['files']))->first();

    expect(($this->git)('log', '--reverse', '--format=%s', 'develop..HEAD'))->toBe("feat(studio): the prototype\nfeat(studio): the signed-off prototype as the base of the real build\nfeat(studio): real coverage")
        ->and($sent['propose']['files'])->toBe([['path' => 'app/Livewire/CoverageReport.php', 'content' => str_replace('namespace App\\Livewire\\Prototype;', 'namespace App\\Livewire;', $prototype)]])
        ->and(file_get_contents($this->root.'/app/Livewire/Prototype/CoverageReport.php'))->toBe($prototype)
        ->and(file_get_contents($this->root.'/app/Livewire/CoverageReport.php'))->toContain('namespace App\\Livewire;')->toContain('public array $runs = [];');
});

it('keeps a file as it is when the studio says what was asked has nothing to do with it', function (): void {
    file_put_contents($this->root.'/artisan', <<<'PHP'
        <?php
        $broken = str_contains((string) file_get_contents(__DIR__.'/app/Models/CoverageRun.php'), 'Broken');
        echo json_encode(['path' => $argv[2] ?? '', 'status' => $broken ? 500 : 200, 'redirect' => null, 'error' => $broken ? 'RuntimeException: The model is Broken' : null, 'where' => $broken ? 'app/Models/CoverageRun.php:5' : null]);
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $this->plan['built_at'] = '/coverage';
    $this->replies['app/Models/CoverageRun.php'] = [
        ['content' => "<?php\n\nnamespace App\\Models;\n\nclass CoverageRunBroken\n{\n}\n", 'edits' => [], 'note' => 'Written.'],
        ['content' => null, 'edits' => [], 'note' => 'Nothing here is about that.', 'unneeded' => true],
        ['content' => null, 'edits' => [], 'note' => 'Nothing here is about that.', 'unneeded' => true],
    ];

    ($this->run)()->run(($this->ask)());

    $notes = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->values();

    expect(file_get_contents($this->root.'/app/Models/CoverageRun.php'))->toContain('class CoverageRunBroken')
        ->and($notes->contains(fn (string $line): bool => str_contains($line, 'not written')))->toBeFalse();
});

it('writes nothing again when the finished page turns the developer\'s user away', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\necho json_encode(['path' => \$argv[2] ?? '', 'status' => 403, 'redirect' => null, 'error' => 'AccessDeniedHttpException: This action is unauthorized.', 'where' => null]);\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $this->plan['built_at'] = '/coverage';

    ($this->run)()->run(($this->ask)());

    $said = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->values();

    expect(collect(($this->proposals)())->pluck('propose.failed')->filter()->all())->toBe([])
        ->and($said)->toContain('/coverage does not let your user in, so I could not see it render.');
});

it('opens each step\'s files in the developer\'s editor together as soon as they are on disk, once each, so they can read them while the rest is written', function (): void {
    config(['studio-cli.review.editor' => Editor::PHPSTORM, 'studio-cli.review.keep_front' => false]);
    $allWritten = [];
    Processes::fake(['*phpstorm://*' => function () use (&$allWritten) {
        $allWritten[] = is_file($this->root.'/tests/Feature/CoverageRunTest.php');

        return Processes::result();
    }]);

    (new BuildRun($this->root, app(Studio::class), new LocalBranch($this->root), new WorkProgress($this->root), new TaskJournal(null), new StepChecks($this->root, new Toolbox($this->root)), 0, 0, editor: new Editor($this->root)))->run(($this->ask)());

    expect($allWritten)->toBe([false, false, true]);
    Processes::assertRanTimes(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'phpstorm://open?file=') && str_contains(implode(' ', (array) $process->command), 'CoverageRun.php'), 1);
});

it('asks before it runs the migrations it added, runs only those, never one that drops or renames, and says where to open what was built before asking if it works', function (string $up, string $down, string $decision, bool $asks, bool $runs): void {
    file_put_contents($this->root.'/artisan', "<?php\necho json_encode(['path' => \$argv[2] ?? '', 'status' => 200, 'redirect' => null, 'error' => null, 'where' => null]);\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    Processes::fake(['*migrate*' => Processes::result()]);
    config(['app.url' => 'https://shop.test']);
    $path = 'database/migrations/2026_10_22_100000_create_coverage_runs_table.php';
    $this->plan['built_at'] = '/coverage-reports';
    $this->plan['steps'][0]['files'][] = ['path' => $path, 'action' => 'create', 'what' => 'The table.', 'pattern' => ''];
    $this->written[$path] = ['content' => "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        {$up}\n    }\n\n    public function down(): void\n    {\n        {$down}\n    }\n};\n", 'edits' => [], 'note' => 'The table.'];

    $this->answer = ['decision' => $decision, 'note' => null];

    ($this->run)()->run(($this->ask)());

    $questions = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('question')->filter();

    expect($questions->pluck('kind')->contains('migrate'))->toBe($asks)
        ->and($questions->last()['reason'])->toContain('Try your app on studio/build-coverage-reports at https://shop.test/coverage-reports, then tell me: does everything work?');
    $runs
        ? Processes::assertRanTimes(fn ($process): bool => in_array('--path='.$path, (array) $process->command, true) && in_array('migrate', (array) $process->command, true), 1)
        : Processes::assertNotRan(fn ($process): bool => in_array('migrate', (array) $process->command, true));
})->with([
    'a table it creates, which the developer allows' => ["Schema::create('coverage_runs', fn (\$table) => \$table->id());", "Schema::dropIfExists('coverage_runs');", 'approve', true, true],
    'a table it creates, which the developer declines' => ["Schema::create('coverage_runs', fn (\$table) => \$table->id());", "Schema::dropIfExists('coverage_runs');", 'skip', true, false],
    'a column it drops, which is never run and never offered' => ["Schema::table('projects', fn (\$table) => \$table->dropColumn('name'));", "Schema::dropIfExists('coverage_runs');", 'approve', false, false],
]);

it('does not open the files again when it asks again after a fix, since they are open already', function (): void {
    config(['studio-cli.review.editor' => Editor::PHPSTORM, 'studio-cli.review.keep_front' => false]);
    Processes::fake(['*phpstorm://*' => Processes::result()]);
    Saloon::fake([
        ShowScanWorkRequest::class => fn () => MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => str_contains((string) @file_get_contents($this->root.'/app/Models/CoverageRun.php'), '$fixed') ? ['decision' => 'works', 'note' => null] : ['decision' => 'retry', 'note' => 'The developer tried the app and asks: mark the run as fixed.']]], 'rechecks' => []]]),
        SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $unneeded = ['content' => null, 'edits' => [], 'note' => 'Nothing here is about that.', 'unneeded' => true];
    $this->replies['app/Models/CoverageRun.php'] = [$this->written['app/Models/CoverageRun.php'], ['content' => null, 'edits' => [['find' => "class CoverageRun\n{\n}", 'replace' => "class CoverageRun\n{\n    public bool \$fixed = true;\n}"]], 'note' => 'Marked as fixed.']];
    $this->replies['app/Models/Project.php'] = [$this->written['app/Models/Project.php'], $unneeded];
    $this->replies['tests/Feature/CoverageRunTest.php'] = [$this->written['tests/Feature/CoverageRunTest.php'], $unneeded];

    (new BuildRun($this->root, app(Studio::class), new LocalBranch($this->root), new WorkProgress($this->root), new TaskJournal(null), new StepChecks($this->root, new Toolbox($this->root)), 0, 0, editor: new Editor($this->root)))->run(($this->ask)());

    expect(collect(($this->sent)('SubmitScanWorkRequest'))->filter(fn (array $body): bool => ($body['question']['kind'] ?? null) === 'final')->count())->toBe(2);
    Processes::assertRanTimes(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'phpstorm://open?file='), 3);
});

it('records the build\'s prototype in the app so it can be opened by its build, and tells the studio where the app is and whether screenshots are sent', function (bool $sends): void {
    config(['app.url' => 'https://shop.test', 'studio-cli.prototypes.send_screenshots' => $sends]);
    $this->plan['prototype_component'] = 'App\\Livewire\\Prototype\\CoverageReport';
    $ask = [...($this->ask)(), 'build' => '01a15b59-5b9b-70ad-8c19-b13a3c0393a5'];

    ($this->run)()->run($ask);

    $told = collect(($this->sent)('SubmitScanWorkRequest'))->filter(fn (array $body): bool => isset($body['app_url']))->values();

    expect(json_decode((string) file_get_contents($this->root.'/storage/app/studio-cli/prototypes.json'), true))->toBe(['01a15b59-5b9b-70ad-8c19-b13a3c0393a5' => 'App\\Livewire\\Prototype\\CoverageReport'])
        ->and($told->pluck('app_url')->all())->toBe(['https://shop.test'])
        ->and($told->pluck('screenshots')->all())->toBe([$sends]);
})->with(['off' => false, 'on' => true]);

it('looks at the prototype in a browser, has the studio say what is wrong with how it looks, and writes it again from that before the developer tries it', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\necho json_encode(['path' => \$argv[2] ?? '', 'status' => 200, 'redirect' => null, 'error' => null, 'where' => null]);\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    config(['app.url' => 'https://shop.test', 'studio-cli.prototypes.serve' => true, 'studio-cli.prototypes.review' => true, 'studio-cli.prototypes.send_screenshots' => true]);
    $shots = [];
    app()->instance('studio-cli.prototype.shot', function (string $url, int $width, int $height) use (&$shots): string {
        $shots[] = [$url, $width, $height];

        return base64_encode('png');
    });
    $audited = [];
    app()->instance('studio-cli.prototype.audit', function (string $url) use (&$audited): array {
        $audited[] = $url;

        return count($audited) === 1 ? ['<div .card> cuts off 40px of its content at the bottom.'] : [];
    });
    $reviews = [['looks_finished' => false, 'problems' => ['Centre the slide\'s content in the frame.']], ['looks_finished' => true, 'problems' => []]];
    Saloon::fake([
        ShowScanWorkRequest::class => fn () => MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => $this->answer]], 'rechecks' => []]]),
        SubmitScanWorkRequest::class => function ($pending) use (&$reviews) {
            return isset($pending->body()->all()['review']) ? MockResponse::make(['review' => array_shift($reviews)]) : MockResponse::make(['recorded' => true], 202);
        },
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->plan['prototype_at'] = '/prototype/'.$uuid;
    $this->plan['prototype_component'] = 'App\\Livewire\\Prototype\\CoverageReport';
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => 'app/Livewire/Prototype/CoverageReport.php', 'action' => 'create', 'what' => 'Sample coverage.', 'pattern' => ''],
        ]],
        ['number' => 2, 'role' => 'prover', 'name' => 'Test', 'tone' => '#3b82ff', 'title' => 'Tests that prove it', 'line' => 'I prove it.', 'files' => [
            ['path' => 'tests/Feature/CoverageRunTest.php', 'action' => 'create', 'what' => 'Proves the model.', 'pattern' => ''],
        ]],
    ];
    $this->replies['app/Livewire/Prototype/CoverageReport.php'] = [
        ['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n    public string \$top = 'top';\n}\n", 'edits' => [], 'note' => 'Written.'],
        ['content' => null, 'edits' => [['find' => "'top'", 'replace' => "'centre'"]], 'note' => 'Centred.'],
    ];

    ($this->run)()->run([...($this->ask)(), 'build' => $uuid]);

    $again = collect(($this->proposals)())->first(fn (array $body): bool => ($body['propose']['failed'] ?? null) !== null);

    $views = [['https://shop.test/prototype/'.$uuid, 1440, 810], ['https://shop.test/prototype/'.$uuid.'?full-page', 1440, 810], ['https://shop.test/?prototype='.$uuid, 1440, 810]];
    $reviewed = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('review')->filter()->first();

    expect($shots)->toBe([...$views, ...$views])
        ->and(array_column($reviewed['shots'], 'view'))->toBe(['layout', 'full', 'modal'])
        ->and($audited)->toBe(['https://shop.test/prototype/'.$uuid.'?full-page', 'https://shop.test/?prototype='.$uuid, 'https://shop.test/prototype/'.$uuid.'?full-page', 'https://shop.test/?prototype='.$uuid])
        ->and($again['propose']['failed'])->toContain('in their layout, full page and in a modal')->toContain('Centre the slide\'s content in the frame.')->toContain('Measured in the full view: <div .card> cuts off 40px of its content at the bottom.')
        ->and(file_get_contents($this->root.'/app/Livewire/Prototype/CoverageReport.php'))->toContain("'centre'");
});

it('measures the prototype here but takes and sends no screenshot unless send_screenshots is on', function (bool $sends): void {
    file_put_contents($this->root.'/artisan', "<?php\necho json_encode(['path' => \$argv[2] ?? '', 'status' => 200, 'redirect' => null, 'error' => null, 'where' => null]);\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    config(['app.url' => 'https://shop.test', 'studio-cli.prototypes.serve' => true, 'studio-cli.prototypes.review' => true, 'studio-cli.prototypes.send_screenshots' => $sends]);
    $shots = 0;
    app()->instance('studio-cli.prototype.shot', function () use (&$shots): string {
        $shots++;

        return base64_encode('png');
    });
    $audited = 0;
    app()->instance('studio-cli.prototype.audit', function () use (&$audited): array {
        $audited++;

        return [];
    });
    Saloon::fake([
        ShowScanWorkRequest::class => fn () => MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => $this->answer]], 'rechecks' => []]]),
        SubmitScanWorkRequest::class => fn ($pending) => isset($pending->body()->all()['review']) ? MockResponse::make(['review' => ['looks_finished' => true, 'problems' => []]]) : MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->plan['prototype_at'] = '/prototype/'.$uuid;
    $this->plan['prototype_component'] = 'App\\Livewire\\Prototype\\CoverageReport';
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => 'app/Livewire/Prototype/CoverageReport.php', 'action' => 'create', 'what' => 'Sample coverage.', 'pattern' => ''],
        ]],
    ];
    $this->replies['app/Livewire/Prototype/CoverageReport.php'] = [['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n}\n", 'edits' => [], 'note' => 'Written.']];

    ($this->run)()->run([...($this->ask)(), 'build' => $uuid]);

    $sentShots = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('review.shots')->filter()->flatten(1)->all();

    expect($audited)->toBeGreaterThan(0)
        ->and($shots > 0)->toBe($sends)
        ->and($sentShots !== [])->toBe($sends);
})->with(['off, the default' => false, 'on' => true]);

it('rewrites only the prototype file a problem names, and leaves the others as they are', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\necho json_encode(['path' => \$argv[2] ?? '', 'status' => 200, 'redirect' => null, 'error' => null, 'where' => null]);\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    config(['app.url' => 'https://shop.test', 'studio-cli.prototypes.serve' => true, 'studio-cli.prototypes.review' => true, 'studio-cli.prototypes.send_screenshots' => true]);
    app()->instance('studio-cli.prototype.shot', fn (): string => base64_encode('png'));
    app()->instance('studio-cli.prototype.audit', fn (): array => []);
    $reviews = [['looks_finished' => false, 'problems' => ['CoverageSummary shows no figure.']], ['looks_finished' => true, 'problems' => []]];
    Saloon::fake([
        ShowScanWorkRequest::class => fn () => MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => $this->answer]], 'rechecks' => []]]),
        SubmitScanWorkRequest::class => fn ($pending) => isset($pending->body()->all()['review']) ? MockResponse::make(['review' => array_shift($reviews)]) : MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->plan['prototype_at'] = '/prototype/'.$uuid;
    $this->plan['prototype_component'] = 'App\\Livewire\\Prototype\\CoverageReport';
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => 'app/Livewire/Prototype/CoverageReport.php', 'action' => 'create', 'what' => 'The page.', 'pattern' => ''],
            ['path' => 'app/Livewire/Prototype/CoverageSummary.php', 'action' => 'create', 'what' => 'The figure.', 'pattern' => ''],
        ]],
    ];
    $this->replies['app/Livewire/Prototype/CoverageReport.php'] = [['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n}\n", 'edits' => [], 'note' => 'Written.']];
    $this->replies['app/Livewire/Prototype/CoverageSummary.php'] = [
        ['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageSummary\n{\n    public string \$figure = 'empty';\n}\n", 'edits' => [], 'note' => 'Written.'],
        ['content' => null, 'edits' => [['find' => "'empty'", 'replace' => "'82'"]], 'note' => 'Shows the figure.'],
    ];

    ($this->run)()->run([...($this->ask)(), 'build' => $uuid]);

    $fix = collect(($this->proposals)())->first(fn (array $body): bool => ($body['propose']['failed'] ?? null) !== null);

    expect(array_column($fix['propose']['files'], 'path'))->toBe(['app/Livewire/Prototype/CoverageSummary.php'])
        ->and(file_get_contents($this->root.'/app/Livewire/Prototype/CoverageSummary.php'))->toContain("'82'");
});

it('sends the signed-off prototype to the studio, waits for the plan of the real build, and builds the steps it gets', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\necho json_encode(['path' => \$argv[2] ?? '', 'status' => 200, 'redirect' => null, 'error' => null, 'where' => null]);\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    config(['app.url' => 'https://shop.test', 'studio-cli.prototypes.serve' => true, 'studio-cli.prototypes.review' => true, 'studio-cli.prototypes.send_screenshots' => true]);
    app()->instance('studio-cli.prototype.shot', fn (): string => base64_encode('png'));
    app()->instance('studio-cli.prototype.audit', fn (): array => []);
    $prototype = ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
        ['path' => 'app/Livewire/Prototype/CoverageReport.php', 'action' => 'create', 'what' => 'The page.', 'pattern' => ''],
    ]];
    $tests = ['number' => 2, 'role' => 'prover', 'name' => 'Test', 'tone' => '#3b82ff', 'title' => 'Tests that prove it', 'line' => 'I prove it.', 'files' => [
        ['path' => 'tests/Feature/CoverageRunTest.php', 'action' => 'create', 'what' => 'Proves the model.', 'pattern' => ''],
    ]];
    $this->plan['prototype_at'] = '/prototype/'.$uuid;
    $this->plan['prototype_component'] = 'App\\Livewire\\Prototype\\CoverageReport';
    $this->plan['steps'] = [$prototype];
    $this->plan['rest'] = 'pending';
    $planned = [...$this->plan, 'steps' => [$prototype, $tests], 'rest' => 'planned', 'prototype_stage' => 'failed'];
    Saloon::fake([
        ShowScanWorkRequest::class => function () use ($planned) {
            $asked = collect(($this->sent)('SubmitScanWorkRequest'))->contains(fn (array $report): bool => isset($report['plan_request']));

            return MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => $this->answer, 'plan' => $asked ? $planned : [...$this->plan, 'rest' => 'planning']]], 'rechecks' => []]]);
        },
        SubmitScanWorkRequest::class => fn ($pending) => isset($pending->body()->all()['review']) ? MockResponse::make(['review' => ['looks_finished' => true, 'problems' => []]]) : MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->replies['app/Livewire/Prototype/CoverageReport.php'] = [['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n}\n", 'edits' => [], 'note' => 'Written.']];

    $result = ($this->run)()->run([...($this->ask)(), 'plan' => $this->plan, 'build' => $uuid]);

    $request = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('plan_request')->filter()->first();
    $steps = collect(($this->proposals)())->filter(fn (array $body): bool => isset($body['propose']['files']))->pluck('propose.step')->unique()->values()->all();

    expect(array_column($request['files'], 'path'))->toBe(['app/Livewire/Prototype/CoverageReport.php'])
        ->and($request['files'][0]['content'])->toContain('class CoverageReport')
        ->and($steps)->toBe([1, 2])
        ->and($result)->toContain('2 of 2 steps committed');
});

it('asks after the wireframe is signed off whether to make a quick prototype, builds that step on yes, and copies the files only from the last thing signed off', function (string $demoAnswer, bool $builds): void {
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    $path = 'app/Livewire/Wireframe/CoverageReport.php';
    $prototypePath = 'app/Livewire/Prototype/CoverageReport.php';
    $wireframe = ['number' => 1, 'role' => 'spark', 'name' => 'Wireframe', 'tone' => '#ffb020', 'title' => 'The wireframe', 'line' => 'I show it.', 'prototype' => true, 'files' => [['path' => $path, 'action' => 'create', 'what' => 'The shell.', 'pattern' => '']]];
    $demo = ['number' => 2, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The whole product', 'line' => 'I make it demonstrable.', 'prototype' => true, 'demo' => true, 'files' => [['path' => $prototypePath, 'action' => 'create', 'what' => 'Demo data.', 'pattern' => '']]];
    $tests = ['number' => $builds ? 3 : 2, 'role' => 'prover', 'name' => 'Test', 'tone' => '#3b82ff', 'title' => 'Tests that prove it', 'line' => 'I prove it.', 'files' => [['path' => 'tests/Feature/CoverageRunTest.php', 'action' => 'create', 'what' => 'Proves it.', 'pattern' => '']]];
    $this->plan = [...$this->plan, 'prototype_at' => '/wireframe/'.$uuid, 'wireframe_component' => 'App\\Livewire\\Wireframe\\CoverageReport', 'prototype_component' => null, 'steps' => [$wireframe], 'rest' => 'pending', 'demo' => true, 'copies' => [$path => 'app/Livewire/CoverageReport.php']];
    Saloon::fake([
        ShowScanWorkRequest::class => function () use ($demoAnswer, $wireframe, $demo, $tests, $builds, $prototypePath) {
            $sent = collect(($this->sent)('SubmitScanWorkRequest'));
            $kind = $sent->pluck('question.kind')->filter()->last();
            $requests = $sent->pluck('plan_request')->filter()->values();
            $prototyped = $requests->contains(fn (array $request): bool => ($request['stage'] ?? null) === 'prototype');
            $restAsked = $requests->contains(fn (array $request): bool => ($request['stage'] ?? 'rest') === 'rest');
            $steps = match (true) {
                $restAsked => $builds ? [$wireframe, $demo, $tests] : [$wireframe, $tests],
                $prototyped => [$wireframe, $demo],
                default => [$wireframe],
            };
            $stage = $prototyped ? ['prototype_stage' => 'planned', 'prototype_component' => 'App\\Livewire\\Prototype\\CoverageReport', 'copies' => [$prototypePath => 'app/Livewire/CoverageReport.php']] : [];

            return MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => $kind === 'demo' ? ['decision' => $demoAnswer, 'note' => null] : ['decision' => 'approve', 'note' => null], 'plan' => [...$this->plan, ...$stage, 'steps' => $steps, 'rest' => $restAsked ? 'planned' : 'pending']]], 'rechecks' => []]]);
        },
        SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $this->replies[$path] = [['content' => "<?php\n\nnamespace App\\Livewire\\Wireframe;\n\nclass CoverageReport\n{\n}\n", 'edits' => [], 'note' => 'The shell.']];
    $this->replies[$prototypePath] = [['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n    public int \$total = 82;\n}\n", 'edits' => [], 'note' => 'Demo data.']];

    $result = ($this->run)()->run([...($this->ask)(), 'plan' => $this->plan, 'build' => $uuid]);

    $kinds = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('question.kind')->filter()->values()->all();
    $written = collect(($this->proposals)())->filter(fn (array $body): bool => isset($body['propose']['files']))->pluck('propose.step')->unique()->values()->all();
    $copied = is_file($this->root.'/app/Livewire/CoverageReport.php') ? file_get_contents($this->root.'/app/Livewire/CoverageReport.php') : '';

    expect($kinds)->toBe($builds ? ['prototype', 'demo', 'prototype', 'final'] : ['prototype', 'demo', 'final'])
        ->and($written)->toBe($builds ? [1, 2, 3] : [1, 2])
        ->and($copied)->toContain('class CoverageReport')
        ->and(str_contains($copied, 'public int $total = 82;'))->toBe($builds)
        ->and($result)->toContain(($builds ? '3' : '2').' of '.($builds ? '3' : '2').' steps committed');
})->with([
    'yes' => ['approve', true],
    'no' => ['skip', false],
]);

it('opens the prototype again after changing it for the developer\'s note, and fixes it from what the app says before they try it', function (): void {
    file_put_contents($this->root.'/artisan', <<<'PHP'
        <?php
        $broken = str_contains((string) file_get_contents(__DIR__.'/app/Livewire/Prototype/CoverageReport.php'), 'broken');
        echo json_encode(['path' => $argv[2] ?? '', 'status' => $broken ? 500 : 200, 'redirect' => null, 'error' => $broken ? 'TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string, array given' : null, 'where' => $broken ? 'resources/views/livewire/prototype/spending.blade.php:23' : null]);
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    $file = $this->root.'/app/Livewire/Prototype/CoverageReport.php';
    Saloon::fake([
        ShowScanWorkRequest::class => fn () => MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => str_contains((string) @file_get_contents($file), 'fixed') ? ['decision' => 'approve', 'note' => null] : ['decision' => 'retry', 'note' => 'Show a month picker.']]], 'rechecks' => []]]),
        SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $this->plan['prototype_at'] = '/prototype/'.$uuid;
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => 'app/Livewire/Prototype/CoverageReport.php', 'action' => 'create', 'what' => 'Sample coverage.', 'pattern' => ''],
        ]],
        ['number' => 2, 'role' => 'prover', 'name' => 'Test', 'tone' => '#3b82ff', 'title' => 'Tests that prove it', 'line' => 'I prove it.', 'files' => [
            ['path' => 'tests/Feature/CoverageRunTest.php', 'action' => 'create', 'what' => 'Proves the model.', 'pattern' => ''],
        ]],
    ];
    $this->replies['app/Livewire/Prototype/CoverageReport.php'] = [
        ['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n    public string \$state = 'clean';\n}\n", 'edits' => [], 'note' => 'Written.'],
        ['content' => null, 'edits' => [['find' => "'clean'", 'replace' => "'broken'"]], 'note' => 'A month picker.'],
        ['content' => null, 'edits' => [['find' => "'broken'", 'replace' => "'fixed'"]], 'note' => 'Fixed.'],
    ];

    ($this->run)()->run([...($this->ask)(), 'build' => $uuid]);

    $said = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->values();

    expect(file_get_contents($file))->toContain("'fixed'")
        ->and($said->contains(fn (string $line): bool => str_contains($line, 'does not render: TypeError') && str_contains($line, 'I’m fixing it')))->toBeTrue();
});

it('keeps the prototype files the developer changed themselves when they say so, rewrites nothing, and commits their version', function (): void {
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    $file = $this->root.'/app/Livewire/Prototype/CoverageReport.php';
    Saloon::fake([
        ShowScanWorkRequest::class => function () use ($file) {
            $content = (string) @file_get_contents($file);
            $mine = str_contains($content, 'mine');
            $asking = collect(($this->sent)('SubmitScanWorkRequest'))->contains(fn (array $report): bool => ($report['question']['kind'] ?? null) === 'prototype');
            ! $asking || ! str_contains($content, 'clean') || file_put_contents($file, str_replace("'clean'", "'mine'", $content));

            return MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => $mine ? ['decision' => 'approve', 'note' => null] : ['decision' => 'retry', 'note' => 'The developer changed the files themselves: I made some changes']]], 'rechecks' => []]]);
        },
        SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $this->plan['prototype_at'] = '/prototype/'.$uuid;
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => 'app/Livewire/Prototype/CoverageReport.php', 'action' => 'create', 'what' => 'Sample coverage.', 'pattern' => ''],
        ]],
    ];
    $this->replies['app/Livewire/Prototype/CoverageReport.php'] = [
        ['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n    public string \$state = 'clean';\n}\n", 'edits' => [], 'note' => 'Written.'],
    ];

    ($this->run)()->run([...($this->ask)(), 'build' => $uuid]);

    $written = collect(($this->proposals)())->filter(fn (array $body): bool => isset($body['propose']['files']))->count();
    $said = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->values();

    expect($written)->toBe(1)
        ->and(file_get_contents($file))->toContain("'mine'")
        ->and(($this->git)('show', 'HEAD:app/Livewire/Prototype/CoverageReport.php'))->toContain("'mine'")
        ->and($said->contains(fn (string $line): bool => str_contains($line, 'I keep the changes you made')))->toBeTrue();
});

it('puts back the prototype that rendered when a change for the developer\'s note breaks it and the fixes do not mend it', function (): void {
    file_put_contents($this->root.'/artisan', <<<'PHP'
        <?php
        $broken = str_contains((string) file_get_contents(__DIR__.'/app/Livewire/Prototype/CoverageReport.php'), 'broken');
        echo json_encode(['path' => $argv[2] ?? '', 'status' => $broken ? 500 : 200, 'redirect' => null, 'error' => $broken ? 'ErrorException: Undefined array key "credits"' : null, 'where' => null]);
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    $path = 'app/Livewire/Prototype/CoverageReport.php';
    Saloon::fake([
        ShowScanWorkRequest::class => fn () => MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => str_contains((string) @file_get_contents($this->root.'/'.$path), 'clean') && ($this->replies[$path] ?? []) === [] ? ['decision' => 'approve', 'note' => null] : ['decision' => 'retry', 'note' => 'Show credits per month.']]], 'rechecks' => []]]),
        SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $this->plan['prototype_at'] = '/prototype/'.$uuid;
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'files' => [
            ['path' => $path, 'action' => 'create', 'what' => 'Sample coverage.', 'pattern' => ''],
        ]],
        ['number' => 2, 'role' => 'prover', 'name' => 'Test', 'tone' => '#3b82ff', 'title' => 'Tests that prove it', 'line' => 'I prove it.', 'files' => [
            ['path' => 'tests/Feature/CoverageRunTest.php', 'action' => 'create', 'what' => 'Proves the model.', 'pattern' => ''],
        ]],
    ];
    $this->replies[$path] = [
        ['content' => "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nclass CoverageReport\n{\n    public string \$state = 'clean';\n}\n", 'edits' => [], 'note' => 'Written.'],
        ['content' => null, 'edits' => [['find' => "'clean'", 'replace' => "'broken'"]], 'note' => 'Credits per month.'],
    ];
    $this->written[$path] = ['content' => null, 'edits' => [['find' => "'broken'", 'replace' => "'broken'"]], 'note' => 'Tried again.'];

    ($this->run)()->run([...($this->ask)(), 'build' => $uuid]);

    $said = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->values();

    expect(file_get_contents($this->root.'/'.$path))->toContain("'clean'")->not->toContain("'broken'")
        ->and($said->contains(fn (string $line): bool => str_contains($line, 'I put back the version that rendered')))->toBeTrue()
        ->and(($this->git)('status', '--porcelain'))->not->toContain($path);
});

it('changes what it built for what the developer saw when they tried the app, and asks them again', function (): void {
    $note = 'The developer tried the app and asks: mark the run as fixed.';
    Saloon::fake([
        ShowScanWorkRequest::class => fn () => MockResponse::make(['work' => ['sessions' => [['id' => 9, 'prepared' => ($this->prepared)(), 'answer' => str_contains((string) @file_get_contents($this->root.'/app/Models/CoverageRun.php'), '$fixed') ? ['decision' => 'works', 'note' => null] : ['decision' => 'retry', 'note' => $note]]], 'rechecks' => []]]),
        SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
    $unneeded = ['content' => null, 'edits' => [], 'note' => 'Nothing here is about that.', 'unneeded' => true];
    $this->replies['app/Models/CoverageRun.php'] = [$this->written['app/Models/CoverageRun.php'], ['content' => null, 'edits' => [['find' => "class CoverageRun\n{\n}", 'replace' => "class CoverageRun\n{\n    public bool \$fixed = true;\n}"]], 'note' => 'Marked as fixed.']];
    $this->replies['app/Models/Project.php'] = [$this->written['app/Models/Project.php'], $unneeded];
    $this->replies['tests/Feature/CoverageRunTest.php'] = [$this->written['tests/Feature/CoverageRunTest.php'], $unneeded];

    $result = ($this->run)()->run(($this->ask)());

    $fixes = collect(($this->proposals)())->filter(fn (array $body): bool => ($body['propose']['failed'] ?? null) === $note)->pluck('propose.step')->unique()->values()->all();
    $said = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->values();

    expect(file_get_contents($this->root.'/app/Models/CoverageRun.php'))->toContain('public bool $fixed = true;')
        ->and($fixes)->toBe([1, 2])
        ->and(($this->git)('log', '--reverse', '--format=%s', 'develop..HEAD'))->toBe("feat(studio): the coverage data\nfeat(studio): tests that prove it\nfeat(studio): the coverage data")
        ->and($said->contains(fn (string $line): bool => str_contains($line, 'I’m changing what I built on studio/build-coverage-reports for what you saw.')))->toBeTrue()
        ->and($said->contains(fn (string $line): bool => str_contains($line, 'Nothing in')))->toBeFalse()
        ->and($result)->toContain('2 of 2 steps committed');
});

it('fixes the code the tests fail inside instead of the tests, and leaves a test whose own assertion fails to its writer', function (): void {
    $root = $this->root;
    $tests = new class($root) extends FakeTestRunner
    {
        public function __construct(public string $root) {}

        public function failures(array $tests): ?array
        {
            $code = (string) @file_get_contents($this->root.'/app/Models/CoverageRun.php');

            return str_contains($code, 'fixed') ? [] : ['tests/Feature/CoverageRunTest.php › it has coverage runs' => "TypeError: total(): Return value must be of type float\n".$this->root."/app/Models/CoverageRun.php:7\n".$this->root.'/tests/Feature/CoverageRunTest.php:4'];
        }
    };
    $this->replies['app/Models/CoverageRun.php'] = [
        $this->written['app/Models/CoverageRun.php'],
        ['content' => null, 'edits' => [['find' => "class CoverageRun\n{\n}", 'replace' => "class CoverageRun\n{\n    public string \$state = 'fixed';\n}"]], 'note' => 'It returns a float.'],
    ];

    $result = (new BuildRun($this->root, app(Studio::class), new LocalBranch($this->root), new WorkProgress($this->root), new TaskJournal(null), new StepChecks($this->root, new Toolbox($this->root)), 0, 0, $tests))->run(($this->ask)());

    $fixes = collect(($this->proposals)())->filter(fn (array $body): bool => str_contains((string) ($body['propose']['failed'] ?? ''), 'fail with an error inside this file'))->values();
    $said = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->values();

    expect(file_get_contents($this->root.'/app/Models/CoverageRun.php'))->toContain("'fixed'")
        ->and($fixes)->toHaveCount(1)
        ->and($fixes[0]['propose']['step'])->toBe(2)
        ->and(array_column($fixes[0]['propose']['files'], 'path'))->toBe(['app/Models/CoverageRun.php'])
        ->and($fixes[0]['propose']['failed'])->toContain('app/Models/CoverageRun.php:7')->not->toContain($this->root)
        ->and($said->contains(fn (string $line): bool => str_contains($line, 'The tests fail inside app/Models/CoverageRun.php, so I’m fixing that code, not the tests.')))->toBeTrue()
        ->and($said->contains(fn (string $line): bool => str_contains($line, 'did not pass')))->toBeFalse()
        ->and($result)->toContain('2 of 2 steps committed');
});

it('says it could not look at the prototype when no browser can be opened, and still hands it over', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\necho json_encode(['path' => \$argv[2] ?? '', 'status' => 200, 'redirect' => null, 'error' => null, 'where' => null]);\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');
    $uuid = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    config(['app.url' => 'https://shop.test', 'studio-cli.prototypes.serve' => true, 'studio-cli.prototypes.review' => true, 'studio-cli.prototypes.send_screenshots' => true]);
    app()->instance('studio-cli.prototype.shot', fn (): ?string => null);
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->plan['prototype_at'] = '/prototype/'.$uuid;
    $this->plan['prototype_component'] = 'App\\Livewire\\Prototype\\CoverageReport';
    $this->plan['steps'][0]['prototype'] = true;

    ($this->run)()->run([...($this->ask)(), 'build' => $uuid]);

    $said = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->values();

    expect($said->contains(fn (string $line): bool => str_contains($line, 'could not open a browser')))->toBeTrue()
        ->and(collect(($this->sent)('SubmitScanWorkRequest'))->pluck('review')->filter()->all())->toBe([]);
});

it('never sends the studio a tracked env file, a key file or a file holding a secret from the signed-off prototype', function (): void {
    SecretLookingFiles::plant($this->root);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'files that look like secrets');
    $run = ($this->run)();
    $step = ['number' => 1, 'role' => 'spark', 'name' => 'Prototype', 'tone' => '#ffb020', 'title' => 'The prototype', 'line' => 'I show it.', 'prototype' => true, 'demo' => true, 'files' => array_map(fn (string $path): array => ['path' => $path, 'action' => 'create', 'what' => 'x', 'pattern' => ''], [...SecretLookingFiles::REFUSED, SecretLookingFiles::FINE])];

    $files = (new ReflectionMethod($run, 'signedOffFiles'))->invoke($run, [$step]);

    expect(array_column($files, 'path'))->toBe([SecretLookingFiles::FINE])
        ->and(SecretLookingFiles::leaks(json_encode($files, JSON_THROW_ON_ERROR)))->toBeFalse();
});

it('never sends the studio a tracked env file, a key file or a file holding a secret as a file it reads while it writes', function (): void {
    SecretLookingFiles::plant($this->root);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'files that look like secrets');
    $run = ($this->run)();
    (new ReflectionProperty($run, 'read'))->setValue($run, [...SecretLookingFiles::REFUSED, SecretLookingFiles::FINE]);
    $writing = [['path' => 'app/Models/Order.php', 'action' => 'create', 'what' => 'x', 'pattern' => SecretLookingFiles::REFUSED[0]]];

    $related = (new ReflectionMethod($run, 'related'))->invoke($run, $writing);
    $content = (new ReflectionMethod($run, 'contentOf'))->invoke($run, ['path' => '.env', 'action' => 'change', 'what' => 'x', 'pattern' => '']);

    expect(array_column($related, 'path'))->toBe([SecretLookingFiles::FINE])
        ->and(SecretLookingFiles::leaks(json_encode($related, JSON_THROW_ON_ERROR)))->toBeFalse()
        ->and($content)->toBe('');
});

it('opens nothing at the end when the plan names no page to open', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\nfile_put_contents(__DIR__.'/opened.log', (\$argv[2] ?? '').\"\\n\", FILE_APPEND);\necho json_encode(['path' => \$argv[2] ?? '', 'status' => 200]);\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'artisan');

    ($this->run)()->run(($this->ask)());

    expect(file_get_contents($this->root.'/opened.log'))->not->toContain('/');
});

it('times each thing it does while it builds, and sends it to the studio to be laid beside the studio\'s own', function (): void {
    ($this->run)()->run(($this->ask)());

    $events = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('events')->filter()->flatten(1);
    $kinds = $events->pluck('kind')->unique()->values()->all();

    expect($kinds)->toContain('write', 'commit', 'developer')
        ->and($events->every(fn (array $event): bool => $event['finished'] >= $event['started'] && $event['started'] > 1_000_000_000 && $event['name'] !== ''))->toBeTrue()
        ->and($events->firstWhere('kind', 'commit')['step'])->toBeInt()
        ->and($events->firstWhere('kind', 'write')['name'])->toContain('asking the studio to write')
        ->and($events->firstWhere('kind', 'developer')['meta']['detail'])->toStartWith('you said ');
});

it('does not leave the real build with the prototype\'s sample data: a copy that comes back untouched, or with its sample figures still in it, is written again', function (string $first, string $why): void {
    $prototype = 'resources/views/livewire/prototype/coverage.blade.php';
    $real = 'resources/views/livewire/coverage.blade.php';
    $sample = "<div>\n    <section :items=\"[['Jan', 1010], ['Feb', 1145]]\"></section>\n    <span :to=\"1284\"></span>\n</div>\n";
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->plan['copies'] = [$prototype => $real];
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Wireframe', 'tone' => '#ffb020', 'title' => 'The wireframe', 'line' => 'I show it.', 'prototype' => true, 'files' => [['path' => $prototype, 'action' => 'create', 'what' => 'The view.', 'pattern' => '']]],
        ['number' => 2, 'role' => 'mason', 'name' => 'Build', 'tone' => '#9d7bff', 'title' => 'Make it real', 'line' => 'I give it real data.', 'files' => [['path' => $real, 'action' => 'change', 'what' => 'Reads the component.', 'pattern' => '']]],
    ];
    $this->replies[$prototype] = [['content' => $sample, 'edits' => [], 'note' => 'The view.']];
    $this->replies[$real] = [
        $first === 'untouched'
            ? ['content' => null, 'edits' => [], 'unneeded' => true, 'note' => 'Nothing to change.']
            : ['content' => null, 'edits' => [['find' => '<span :to="1284"></span>', 'replace' => '<span :to="$total"></span>']], 'note' => 'Counts the real total.'],
        ['content' => null, 'edits' => [['find' => "<section :items=\"[['Jan', 1010], ['Feb', 1145]]\"></section>", 'replace' => '<section :items="$months"></section>'], ...($first === 'untouched' ? [['find' => '<span :to="1284"></span>', 'replace' => '<span :to="$total"></span>']] : [])], 'note' => 'Reads the component.'],
    ];

    ($this->run)()->run(($this->ask)());

    $again = collect(($this->proposals)())->first(fn (array $body): bool => ($body['propose']['failed'] ?? null) !== null && str_contains((string) $body['propose']['failed'], 'prototype'));
    $kept = (string) file_get_contents($this->root.'/'.$real);

    expect($again['propose']['failed'])->toContain($why)
        ->and($kept)->toContain('$months')->toContain('$total')->not->toContain('1010')->not->toContain('1284');
})->with([
    'untouched' => ['untouched', 'still exactly the prototype it was copied from'],
    'figures left' => ['figures', 'still holds the prototype\'s sample data'],
]);

it('fixes the version of a file that did not compile, and does not start again from the original', function (): void {
    $prototype = 'resources/views/livewire/prototype/coverage.blade.php';
    $real = 'resources/views/livewire/coverage.blade.php';
    $this->answer = ['decision' => 'approve', 'note' => null];
    $this->plan['copies'] = [$prototype => $real];
    $this->plan['steps'] = [
        ['number' => 1, 'role' => 'spark', 'name' => 'Wireframe', 'tone' => '#ffb020', 'title' => 'The wireframe', 'line' => 'I show it.', 'prototype' => true, 'files' => [['path' => $prototype, 'action' => 'create', 'what' => 'The view.', 'pattern' => '']]],
        ['number' => 2, 'role' => 'mason', 'name' => 'Build', 'tone' => '#9d7bff', 'title' => 'Make it real', 'line' => 'I give it real data.', 'files' => [['path' => $real, 'action' => 'change', 'what' => 'Shows a heading when there is data.', 'pattern' => '']]],
    ];
    $this->replies[$prototype] = [['content' => "<div>\n    <p>Hello</p>\n</div>\n", 'edits' => [], 'note' => 'The view.']];
    $this->replies[$real] = [
        ['content' => null, 'edits' => [['find' => '<p>Hello</p>', 'replace' => "@if (\$total > 0)\n<p>Hello</p>"]], 'note' => 'Shows it when there is data.'],
        ['content' => null, 'edits' => [['find' => "@if (\$total > 0)\n<p>Hello</p>", 'replace' => "@if (\$total > 0)\n<p>Hello</p>\n@endif"]], 'note' => 'Closes the condition.'],
    ];

    ($this->run)()->run(($this->ask)());

    $kept = (string) file_get_contents($this->root.'/'.$real);

    expect($kept)->toContain('@if ($total > 0)')->toContain('@endif');
});
