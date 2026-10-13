<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanWorkRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFixReportRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanToolResultsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanWorkRequest;
use ArtisanStudio\StudioCli\Scan\LastFindings;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use ArtisanStudio\StudioCli\Work\CoveringTests;
use ArtisanStudio\StudioCli\Work\WorkProgress;
use ArtisanStudio\StudioCli\Work\WorkRun;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Working through a job with the developer, for real, on a project of its own
|--------------------------------------------------------------------------
|
| A real git repository and a stand-in checking tool that reports every
| TODO_FIX line. The studio is faked: it answers each file the terminal
| sends with its edits. No AI runs on the machine. The run cuts its
| branch, fixes each kind of problem, checks it again, and stops for the
| developer to try it before anything is committed.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-work-run-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app', 0755, true);
    $this->git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    $this->seed = function (int $count, int $todos = 1): void {
        collect(range(1, $count))->each(fn (int $number): int|false => file_put_contents($this->root."/app/F{$number}.php", "<?php\n".str_repeat("\$x = 'TODO_FIX';\n", $todos)));
    };
    ($this->seed)(7);
    ($this->git)('init', '--quiet', '--initial-branch=develop');
    ($this->git)('config', 'user.email', 'dev@example.com');
    ($this->git)('config', 'user.name', 'Developer');
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'start');

    $this->edit = fn (string $replace): array => ['edits' => [['find' => "\$x = 'TODO_FIX';", 'replace' => $replace]], 'note' => '', 'unneeded' => false];
    $this->clear = fn (array $asked): array => collect($asked['files'])->mapWithKeys(fn (array $file): array => [$file['path'] => [...($this->edit)("\$x = 'DONE';"), 'note' => 'Cleared it.']])->all();
    $this->touched = fn (array $asked): array => collect($asked['files'])->mapWithKeys(fn (array $file): array => [$file['path'] => ($this->edit)("\$x = 'TODO_FIX'; // touched")])->all();
    $this->replies = [];
    $this->silent = false;
    $this->drip = false;
    $this->answered = [];
    $this->polls = [];
    $this->checks = 0;
    $this->tool = function (string $tool): array {
        $this->checks++;
        $findings = collect(glob($this->root.'/app/*.php') ?: [])
            ->flatMap(fn (string $file): array => collect(explode("\n", (string) file_get_contents($file)))
                ->filter(fn (string $line): bool => str_contains($line, 'TODO_FIX'))
                ->map(fn (string $line, int $index): array => ['where' => 'app/'.basename($file).':'.($index + 1), 'rule' => 'todo', 'message' => 'Fix me.'])
                ->values()
                ->all())
            ->values()
            ->all();

        return ['ran' => true, 'findings' => $findings];
    };
    $this->ask = fn (int $files, string $mode = 'terminal'): array => [
        'id' => 7,
        'job' => 'leftovers',
        'mode' => $mode,
        'state' => 'asked',
        'files' => array_map(fn (int $n): array => ['path' => "app/F{$n}.php", 'findings' => [['tool' => 'phpstan', 'rule' => 'todo', 'line' => 2, 'title' => 'Fix me.']]], range(1, $files)),
    ];
    $this->kinds = fn (): array => [...($this->ask)(7), 'files' => array_map(fn (int $n): array => ['path' => "app/F{$n}.php", 'findings' => [['tool' => 'phpstan', 'rule' => $n <= 4 ? 'alpha' : 'beta', 'line' => 2, 'title' => 'Fix me.']]], range(1, 7))];
    $this->run = fn (?CoveringTests $tests = null): WorkRun => new WorkRun($this->root, app(Studio::class), new Toolbox($this->root), new LocalBranch($this->root), new WorkProgress($this->root), new TaskJournal(null), 0, $this->tool, 0, $tests);
    $this->answer = ['decision' => 'works', 'note' => null];
    $this->answers = [];
    $this->meanwhile = [];
    $this->atQuestion = [];
    $this->proposals = fn (): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->filter(fn ($response): bool => class_basename($response->getRequest()) === 'SubmitScanWorkRequest')
        ->map(fn ($response): array => $response->getPendingRequest()->body()->all())
        ->filter(fn (array $body): bool => isset($body['propose']))
        ->values()
        ->all();
    $this->asked = fn (): array => collect(($this->proposals)())
        ->filter(fn (array $body): bool => isset($body['propose']['files']))
        ->groupBy('propose.id')
        ->map(fn ($parts): array => ['files' => $parts->flatMap(fn (array $part): array => $part['propose']['files'])->all(), 'note' => $parts->last()['propose']['note'], 'failed' => $parts->last()['propose']['failed'], 'parts' => $parts->count()])
        ->values()
        ->all();
    $this->sent = fn (string $request): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->filter(fn ($response): bool => class_basename($response->getRequest()) === $request)
        ->map(fn ($response): array => $response->getPendingRequest()->body()->all())
        ->values()
        ->all();
    $this->summary = fn (): string => (string) collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->last(fn (string $line): bool => preg_match('/^I committed \d+ of \d+/', $line) === 1);
    $this->changed = fn (): array => array_values(array_filter(explode("\n", ($this->git)('diff', '--name-only', 'develop..HEAD'))));
    $this->prepared = function (): ?array {
        $last = collect(($this->proposals)())->last();

        if ($this->silent || $last === null || ($last['propose']['done'] ?? false) || ($last['propose']['more'] ?? false)) {
            return null;
        }

        $id = $last['propose']['id'];
        $this->answered[$id] ??= (array_shift($this->replies) ?? $this->clear)(collect(($this->asked)())->last());
        $this->polls[$id] = ($this->polls[$id] ?? 0) + 1;
        $files = $this->drip ? array_slice($this->answered[$id], 0, $this->polls[$id], true) : $this->answered[$id];

        return ['id' => $id, 'state' => count($files) === count($this->answered[$id]) ? 'ready' : 'running', 'files' => $files, 'working' => []];
    };

    Saloon::fake([
        ShowScanWorkRequest::class => function () {
            $pending = (new WorkProgress($this->root))->read()['question'] ?? null;

            if ($pending !== null) {
                $this->atQuestion[] = ['commits' => ($this->git)('log', '--format=%s', 'develop..HEAD'), 'status' => ($this->git)('status', '--porcelain', '--untracked-files=no')];
                $developer = array_shift($this->meanwhile);
                $developer !== null && $developer();
            }

            return MockResponse::make(['work' => ['sessions' => [['id' => 7, 'prepared' => ($this->prepared)(), 'answer' => $this->answers !== [] && $pending !== null ? array_shift($this->answers) : $this->answer]], 'rechecks' => []]]);
        },
        SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);
});

afterEach(function (): void {
    (new WorkProgress($this->root))->forget();
    (new LastFindings($this->root))->forget();
});

it('works a job one kind of problem at a time with the studio, applies its edits here, commits what passes, and says what it did at the end without stopping', function (): void {
    $line = ($this->run)()->run(($this->ask)(7));
    $work = ($this->sent)('SubmitScanWorkRequest');
    $last = end($work);
    $sent = ($this->asked)();
    $states = collect($work)->filter(fn (array $report): bool => isset($report['batches'][0]))->map(fn (array $report): array => $report['batches'][0]);

    expect(($this->git)('branch', '--show-current'))->toStartWith('studio/fixes-')
        ->and($sent)->toHaveCount(1)
        ->and(array_column($sent[0]['files'], 'path'))->toBe(array_map(fn (int $n): string => "app/F{$n}.php", range(1, 7)))
        ->and($sent[0]['files'][0]['content'])->toBe("<?php\n\$x = 'TODO_FIX';\n")
        ->and(collect(($this->proposals)())->last()['propose'])->toMatchArray(['done' => true])
        ->and(collect($work)->filter(fn (array $report): bool => isset($report['question']))->count())->toBe(0)
        ->and(collect($work)->pluck('said')->filter()->implode("\n"))->toContain('You look over everything once, at the end, before the pull request.')
        ->and(($this->summary)())->toContain('I committed 1 of 1 kind of problem on studio/fixes-')->toContain('Todo (7 fixed)')->toContain('Every file I changed compiles.')->not->toContain('Try your app on this branch')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 7 files of Todo')
        ->and(($this->git)('log', '-1', '--format=%b'))->toContain('Checked again by the tool that found them')->not->toContain('developer')
        ->and(($this->git)('status', '--porcelain', '--untracked-files=no'))->toBe('')
        ->and($line)->toContain('Finished')->toContain('1 of 1 problem committed')->toContain('Nothing was pushed')
        ->and($last)->toMatchArray(['session' => 7, 'state' => 'done'])
        ->and($last['batches'])->toHaveCount(1)
        ->and($last['batches'][0])->toMatchArray(['number' => 1, 'state' => 'committed', 'fixed' => 7, 'left' => 0, 'rule' => 'Todo', 'tools' => ['phpstan'], 'done' => 7])
        ->and($last['batches'][0]['note'])->toContain('Cleared it.')
        ->and($last['batches'][0]['finished'])->toBeGreaterThanOrEqual($last['batches'][0]['started'])
        ->and($states->pluck('state')->unique()->values()->all())->toBe(['queued', 'running', 'checking', 'committed'])
        ->and($states->firstWhere('state', 'running')['finished'])->toBeNull()
        ->and(collect($work)->pluck('said')->filter()->implode("\n"))->toContain('I fix one kind at a time')->toContain('Fixing 7 files at once…')->toContain('I changed 7 files.');

    $reports = ($this->sent)('SubmitScanFixReportRequest');
    $fixReport = collect($reports)->last(fn (array $report): bool => isset($report['rulesets']));
    $live = collect($reports)->filter(fn (array $report): bool => isset($report['live']))->pluck('live');
    $results = ($this->sent)('SubmitScanToolResultsRequest');

    expect($fixReport['rulesets']['phpstan'])->toMatchArray(['fixed' => 7, 'ran' => true])
        ->and($fixReport['branch'])->toStartWith('studio/fixes-')
        ->and($live->first()['rulesets']['phpstan'])->toMatchArray(['found' => 7])
        ->and($live->last())->toMatchArray(['phase' => 'finished'])
        ->and($results)->not->toBeEmpty()
        ->and(end($results))->toMatchArray(['partial' => true])
        ->and(end($results)['tools']['phpstan']['findings'])->toBe([])
        ->and((new WorkProgress($this->root))->read())->toMatchArray(['phase' => 'finished', 'name' => 'Leftovers'])
        ->and((new WorkProgress($this->root))->read()['says'])->toContain('All done with Leftovers');
});

it('sends a kind\'s files to the studio in parts that stay small, and still asks once for the whole kind', function (): void {
    collect(range(1, 3))->each(fn (int $n): int|false => file_put_contents($this->root."/app/F{$n}.php", "<?php\n\$x = 'TODO_FIX';\n// ".str_repeat('a', 250_000)."\n"));
    ($this->git)('commit', '--quiet', '-am', 'bigger');

    $line = ($this->run)()->run(($this->ask)(3));
    $sent = ($this->asked)();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['parts'])->toBe(3)
        ->and(array_column($sent[0]['files'], 'path'))->toBe(['app/F1.php', 'app/F2.php', 'app/F3.php'])
        ->and(collect(($this->proposals)())->filter(fn (array $body): bool => isset($body['propose']['files']))->pluck('propose.more')->all())->toBe([true, true, false])
        ->and(collect(($this->sent)('SubmitScanWorkRequest'))->filter(fn (array $report): bool => isset($report['question']))->count())->toBe(0)
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 3 files of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('commits each kind that passes without asking, and says what it did once, after the last', function (): void {
    $line = ($this->run)()->run(($this->kinds)());
    $work = ($this->sent)('SubmitScanWorkRequest');
    $batches = end($work)['batches'];

    expect(collect($work)->filter(fn (array $report): bool => isset($report['question']))->pluck('question.kind')->all())->toBe([])
        ->and($this->checks)->toBe(3)
        ->and(($this->asked)())->toHaveCount(2)
        ->and($batches)->toHaveCount(2)
        ->and(collect($batches)->pluck('state')->unique()->all())->toBe(['committed'])
        ->and(collect($batches)->pluck('rule')->all())->toBe(['Alpha', 'Beta'])
        ->and(explode("\n", ($this->git)('log', '--format=%s', 'develop..HEAD')))->toBe(['fix(leftovers): SAMI worked through 3 files of Beta', 'fix(leftovers): SAMI worked through 4 files of Alpha'])
        ->and(($this->summary)())->toContain('I committed 2 of 2 kinds of problem')->toContain('Alpha (4 fixed); Beta (3 fixed)')
        ->and($line)->toContain('2 of 2 problems committed');
});

it('leaves out the files the tools here no longer flag, tells the studio, and changes nothing when the whole job is clear', function (): void {
    file_put_contents($this->root.'/app/F8.php', "<?php\n\$x = 'DONE';\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'clear one');
    $ask = [...($this->ask)(1), 'files' => [...($this->ask)(1)['files'], ['path' => 'app/F8.php', 'findings' => [['tool' => 'phpstan', 'rule' => 'todo', 'line' => 2, 'title' => 'Fix me.']]]]];

    $line = ($this->run)()->run($ask);
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(($this->changed)())->toBe(['app/F1.php'])
        ->and(array_column(($this->asked)()[0]['files'], 'path'))->toBe(['app/F1.php'])
        ->and(collect($work)->pluck('said')->filter()->implode("\n"))->toContain('F8.php is already clear on this machine, so I left it out.')
        ->and(($this->sent)('SubmitScanToolResultsRequest')[0])->toMatchArray(['partial' => true])
        ->and($line)->toContain('1 of 1 problem committed');

    ($this->git)('checkout', '--quiet', 'develop');
    (new LastFindings($this->root))->forget();
    $before = count(($this->asked)());

    $line = ($this->run)()->run([...($this->ask)(1), 'files' => [['path' => 'app/F8.php', 'findings' => [['tool' => 'phpstan', 'rule' => 'todo', 'line' => 2, 'title' => 'Fix me.']]]]]);

    expect(($this->asked)())->toHaveCount($before)
        ->and((new WorkProgress($this->root))->read()['says'])->toContain('Everything in Leftovers is already clear on this machine')
        ->and(($this->git)('branch', '--show-current'))->toBe('develop')
        ->and($line)->toBe('Nothing to change: Leftovers is already clear. Nothing was changed.');
});

it('finishes every kind before it stops for a broken app, so the time the developer spends on it is never counted as hers', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\nfwrite(STDERR, 'Class \"App\\\\Missing\" not found'.PHP_EOL);\nexit(1);\n");
    $this->meanwhile = [fn () => usleep(400_000)];

    ($this->run)()->run(($this->ask)(1));
    $work = ($this->sent)('SubmitScanWorkRequest');
    $last = collect($work)->last(fn (array $report): bool => isset($report['batches']))['batches'][0];
    $asked = collect($work)->first(fn (array $report): bool => isset($report['question']))['question'];

    expect($last['state'])->toBe('committed')
        ->and($last['finished'])->toBeLessThanOrEqual($asked['asked_at'])
        ->and($last['worked'])->toBeLessThanOrEqual($last['finished'] - $last['started'] + 0.01);
});

it('takes the developer\'s word at the end: something broke goes to the app with their note, and what was committed stays', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\nfwrite(STDERR, 'Class \"App\\\\Missing\" not found'.PHP_EOL);\nexit(1);\n");
    $this->answer = ['decision' => 'broke', 'note' => 'The settings page is blank.'];

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(collect($work)->contains(fn (array $report): bool => ($report['consumed_answer'] ?? false) === true))->toBeTrue()
        ->and((new WorkProgress($this->root))->read()['says'])->toContain('You said something broke: The settings page is blank. We look at it together in the app.')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 2 files of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('applies the studio\'s edits file by file as they arrive, and says how many of the kind have landed', function (): void {
    $this->drip = true;

    $line = ($this->run)()->run(($this->ask)(3));
    $landed = collect(($this->sent)('SubmitScanWorkRequest'))->filter(fn (array $report): bool => ($report['batches'][0]['state'] ?? null) === 'running')->pluck('batches.0.done')->values()->all();

    expect($landed)->toBe([0, 1, 2, 3])
        ->and((new WorkProgress($this->root))->read()['batches'][0]['done'])->toBe(3)
        ->and(collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->implode("\n"))->toContain('app/F1.php: changed.')->toContain('app/F3.php: changed.')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('leaves a file the studio could not fix as it was, says why, and goes on with the rest', function (): void {
    $this->replies = [fn (array $asked): array => [
        'app/F1.php' => ($this->edit)("\$x = 'DONE';"),
        'app/F2.php' => ['edits' => [], 'note' => 'This one is used two ways.', 'unneeded' => false],
    ]];

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(($this->changed)())->toBe(['app/F1.php'])
        ->and(end($work)['batches'][0]['note'])->toContain('I left F2.php as it was: This one is used two ways.')
        ->and(end($work)['batches'][0]['files'])->toBe(['app/F1.php', 'app/F2.php'])
        ->and(file_get_contents($this->root.'/app/F2.php'))->toContain('TODO_FIX')
        ->and(end($work)['batches'][0])->toMatchArray(['state' => 'committed', 'fixed' => 1, 'left' => 1])
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 1 file of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('sends the studio only as much of a long batch note as it takes, in whole paragraphs, with every file still in its reasons', function (): void {
    $this->replies = [fn (array $asked): array => collect($asked['files'])->mapWithKeys(fn (array $file): array => [$file['path'] => [...($this->edit)("\$x = 'DONE';"), 'note' => $file['path'].': '.trim(str_repeat('Locked it. ', 140))]])->all()];

    ($this->run)()->run(($this->ask)(7));
    $work = ($this->sent)('SubmitScanWorkRequest');
    $last = collect($work)->last(fn (array $report): bool => ($report['batches'][0]['state'] ?? null) === 'committed')['batches'][0];

    expect(collect($work)->pluck('batches')->filter()->flatten(1)->every(fn (array $batch): bool => mb_strlen((string) $batch['note']) <= 8000))->toBeTrue()
        ->and($last['note'])->toStartWith('app/F1.php: Locked it.')->toEndWith('Locked it.')
        ->and($last['reasons'])->toHaveCount(7)
        ->and(mb_strlen((string) (new WorkProgress($this->root))->read()['batches'][0]['note']))->toBeGreaterThan(8000);
});

it('never sends the studio a file that is not in the repository, and leaves it as it was', function (): void {
    file_put_contents($this->root.'/app/F8.php', "<?php\n\$x = 'TODO_FIX';\n");
    $ask = [...($this->ask)(1), 'files' => [...($this->ask)(1)['files'], ['path' => 'app/F8.php', 'findings' => [['tool' => 'phpstan', 'rule' => 'todo', 'line' => 2, 'title' => 'Fix me.']]]]];

    $line = ($this->run)()->run($ask);
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(array_column(($this->asked)()[0]['files'], 'path'))->toBe(['app/F1.php'])
        ->and(collect(($this->asked)()[0]['files'])->pluck('content')->implode(''))->not->toContain('F8')
        ->and(end($work)['batches'][0]['note'])->toContain('F8.php is not in your repository, so it stays on your machine and I left it as it was.')
        ->and(file_get_contents($this->root.'/app/F8.php'))->toContain('TODO_FIX')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 1 file of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('runs the tests that cover a kind after the change, and reruns a failure on the files as they were to tell what the change broke', function (): void {
    $tests = new class($this->root, new Toolbox($this->root)) extends CoveringTests
    {
        public array $asked = [];

        public array $sawTodo = [];

        private array $runs = [['tests/F1Test.php › it keeps the string'], [], []];

        public function __construct(public string $where, Toolbox $toolbox)
        {
            parent::__construct($where, $toolbox);
        }

        public function for(array $paths): array
        {
            return ['tests/F1Test.php'];
        }

        public function failing(array $tests): ?array
        {
            $this->asked[] = $tests;
            $this->sawTodo[] = str_contains((string) file_get_contents($this->where.'/app/F1.php'), 'TODO_FIX');

            return array_shift($this->runs);
        }
    };

    $line = ($this->run)($tests)->run(($this->ask)(1));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect($tests->asked)->toBe([['tests/F1Test.php'], ['tests/F1Test.php'], ['tests/F1Test.php']])
        ->and($tests->sawTodo)->toBe([false, true, false])
        ->and(($this->asked)())->toHaveCount(2)
        ->and(($this->asked)()[1]['failed'])->toContain('After the change this test fails: tests/F1Test.php › it keeps the string. I have not committed it.')
        ->and(end($work)['batches'][0]['note'])->toContain('No new failures in the 1 test file that covers it.')
        ->and(($this->git)('log', '-1', '--format=%b'))->toContain('and by the tests that cover them')
        ->and(collect($work)->pluck('said')->filter()->implode("\n"))->toContain('Running the 1 test file that covers these files.')->toContain('1 test fails. Running that test file on your files as they were')
        ->and(file_get_contents($this->root.'/app/F1.php'))->toContain('DONE')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('does not blame the change for a test that already failed on the files as they were', function (): void {
    $tests = new class($this->root, new Toolbox($this->root)) extends CoveringTests
    {
        public int $runs = 0;

        public function for(array $paths): array
        {
            return ['tests/F1Test.php'];
        }

        public function failing(array $tests): ?array
        {
            $this->runs++;

            return ['tests/F1Test.php › it was already broken'];
        }
    };

    $line = ($this->run)($tests)->run(($this->ask)(1));

    expect($tests->runs)->toBe(2)
        ->and(($this->asked)())->toHaveCount(1)
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 1 file of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('finds the tests that name a changed class, and cannot run them without a test runner', function (): void {
    mkdir($this->root.'/tests/Feature', 0755, true);
    file_put_contents($this->root.'/tests/Feature/F1Test.php', "<?php\nit('works', fn () => new App\\F1);\n");
    file_put_contents($this->root.'/tests/Feature/OtherTest.php', "<?php\nit('works', fn () => new App\\F10);\n");
    file_put_contents($this->root.'/tests/Feature/F1Helper.php', "<?php\n// F1\n");
    $tests = new CoveringTests($this->root, new Toolbox($this->root));

    expect($tests->for(['app/F1.php', 'resources/views/f1.blade.php']))->toBe(['tests/Feature/F1Test.php'])
        ->and($tests->for(['resources/views/f1.blade.php', 'tests/Feature/F1Test.php']))->toBe([])
        ->and($tests->failing(['tests/Feature/F1Test.php']))->toBeNull();
});

it('runs many covering tests side by side through a suite of its own outside the project, reads which fail, and leaves nothing behind', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    file_put_contents($this->root.'/phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="All"><directory>tests</directory></testsuite></testsuites><source><include><directory>./app</directory></include></source><php><env name="APP_ENV" value="testing"/></php></phpunit>');
    file_put_contents($this->root.'/vendor/bin/pest', "#!/usr/bin/env php\n<?php\n"
        .'$config = $argv[array_search("--configuration", $argv) + 1]; $report = $argv[array_search("--log-junit", $argv) + 1];'
        .'$xml = simplexml_load_file($config); $files = array_map("strval", $xml->xpath("//testsuite/file"));'
        .'file_put_contents("seen.json", json_encode(["args" => array_slice($argv, 1), "config" => $config, "files" => $files, "suites" => count($xml->xpath("//testsuite")), "bootstrap" => (string) $xml["bootstrap"], "source" => (string) $xml->source->include->directory, "env" => (string) $xml->php->env["name"]]));'
        .'$cases = implode("", array_map(fn ($f) => "<testcase name=\"it works for ".basename($f, "Test.php")."\" file=\"".$f."\">".(str_contains($f, "CTest") ? "<failure>no</failure>" : "")."</testcase>", $files));'
        .'file_put_contents($report, "<?xml version=\"1.0\"?><testsuites><testsuite>".$cases."</testsuite></testsuites>");');
    chmod($this->root.'/vendor/bin/pest', 0755);
    $tests = array_map(fn (string $name): string => "tests/Feature/{$name}Test.php", ['A', 'B', 'C', 'D', 'E', 'F']);

    $failing = (new CoveringTests($this->root, new Toolbox($this->root)))->failing($tests);
    $seen = json_decode((string) file_get_contents($this->root.'/seen.json'), true);

    expect($failing)->toBe(['tests/Feature/CTest.php › it works for C'])
        ->and($seen['args'])->toContain('--parallel')->not->toContain('tests/Feature/ATest.php')
        ->and($seen['config'])->toStartWith(sys_get_temp_dir())
        ->and(is_file($seen['config']))->toBeFalse()
        ->and($seen['files'])->toBe(array_map(fn (string $test): string => $this->root.'/'.$test, $tests))
        ->and($seen['bootstrap'])->toBe($this->root.'/vendor/autoload.php')
        ->and($seen['source'])->toBe($this->root.'/app')
        ->and($seen['suites'])->toBe(1)
        ->and($seen['env'])->toBe('APP_ENV')
        ->and(glob($this->root.'/.studio-covering-*'))->toBe([]);
});

it('leaves a file the docs say needs no change as it was, says why with the link, and checks only the rest', function (): void {
    $this->replies = [fn (array $asked): array => [
        'app/F1.php' => ['edits' => [], 'note' => 'Livewire already locks a model property: https://livewire.laravel.com/docs/4.x/attribute-locked#when-to-use', 'unneeded' => true],
        'app/F2.php' => ($this->edit)("\$x = 'DONE';"),
    ]];

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(($this->changed)())->toBe(['app/F2.php'])
        ->and(end($work)['batches'][0]['note'])->toContain('I left F1.php as it was: Livewire already locks a model property: https://livewire.laravel.com/docs/4.x/attribute-locked#when-to-use')
        ->and(file_get_contents($this->root.'/app/F1.php'))->toContain('TODO_FIX')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 1 file of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('moves on without asking when the docs say no file of a kind needs a change', function (): void {
    $this->replies = [fn (array $asked): array => ['app/F1.php' => ['edits' => [], 'note' => 'Livewire already locks a model property.', 'unneeded' => true]]];

    $line = ($this->run)()->run(($this->ask)(1));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(collect($work)->contains(fn (array $report): bool => isset($report['question'])))->toBeFalse()
        ->and(end($work)['batches'][0])->toMatchArray(['state' => 'skipped', 'commit' => null])
        ->and(end($work)['batches'][0]['note'])->toBe('I left F1.php as it was: Livewire already locks a model property.')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('')
        ->and(file_get_contents($this->root.'/app/F1.php'))->toContain('TODO_FIX')
        ->and($line)->toContain('0 of 1 problem committed');
});

it('drops an import that the studio repeats, so the file still compiles', function (): void {
    $this->replies = [fn (array $asked): array => ['app/F1.php' => ['edits' => [
        ['find' => "<?php\n", 'replace' => "<?php\nuse App\\Models\\User;\nuse App\\Models\\User;\n"],
        ['find' => "\$x = 'TODO_FIX';", 'replace' => "\$x = 'DONE';"],
    ], 'note' => '', 'unneeded' => false]]];

    $line = ($this->run)()->run(($this->ask)(1));

    expect(substr_count(($this->git)('show', 'HEAD:app/F1.php'), 'use App\Models\User;'))->toBe(1)
        ->and($line)->toContain('1 of 1 problem committed');
});

it('leaves a file whose change PHP cannot compile as it was, and goes on with the rest', function (): void {
    $this->replies = [fn (array $asked): array => [
        'app/F1.php' => ($this->edit)("\$x = 'DONE';"),
        'app/F2.php' => ($this->edit)("\$x = 'DONE';"),
        'app/F3.php' => ($this->edit)('function broken( {'),
    ]];

    $line = ($this->run)()->run(($this->ask)(3));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(($this->changed)())->toBe(['app/F1.php', 'app/F2.php'])
        ->and(end($work)['batches'][0]['note'])->toContain('I left F3.php as it was, because PHP could not compile the change there (F3.php: syntax error')
        ->and(file_get_contents($this->root.'/app/F3.php'))->toContain('TODO_FIX')->not->toContain('broken')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 2 files of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('sends the studio the files as they were, with exactly what the check found, when its change cleared nothing', function (): void {
    $this->replies = [$this->touched];

    $line = ($this->run)()->run(($this->ask)(1));
    $sent = ($this->asked)();

    expect($sent)->toHaveCount(2)
        ->and($sent[0]['failed'])->toBeNull()
        ->and($sent[1]['failed'])->toContain('did not clear anything')
        ->and($sent[1]['files'][0]['content'])->toBe("<?php\n\$x = 'TODO_FIX';\n")
        ->and(file_get_contents($this->root.'/app/F1.php'))->toContain("'DONE'")->not->toContain('touched')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 1 file of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('never commits a kind that fails its check: it tries once more with what the check found, then puts it back, says why, and moves on without asking', function (): void {
    $this->replies = [$this->touched, $this->touched];

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(collect($work)->contains(fn (array $report): bool => isset($report['question'])))->toBeFalse()
        ->and(($this->asked)())->toHaveCount(2)
        ->and(end($work)['batches'][0])->toMatchArray(['state' => 'skipped', 'commit' => null])
        ->and(end($work)['batches'][0]['note'])->toContain('did not clear anything')
        ->and(collect($work)->pluck('said')->filter()->implode("\n"))->toContain('I put these files back as they were. On to the next one.')
        ->and(file_get_contents($this->root.'/app/F1.php'))->not->toContain('touched')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('')
        ->and(($this->git)('status', '--porcelain', '--untracked-files=no'))->toBe('')
        ->and($line)->toContain('Finished')->toContain('0 of 1 problem committed');
});

it('passes on the automatic second try, with exactly what the check found, and only asks the developer to try it', function (): void {
    $this->replies = [$this->touched];

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(collect($work)->filter(fn (array $report): bool => isset($report['question']))->pluck('question.kind')->all())->toBe([])
        ->and(end($work)['batches'][0])->toMatchArray(['state' => 'committed', 'fixed' => 2, 'left' => 0])
        ->and(($this->asked)())->toHaveCount(2)
        ->and(($this->asked)()[1]['failed'])->toContain('did not clear anything')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 2 files of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('puts back a kind it could not clear, commits the rest, and names what is left for the developer and the studio to work through together', function (): void {
    $this->replies = [$this->touched, $this->touched];

    $line = ($this->run)()->run(($this->kinds)());
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(collect(end($work)['batches'])->pluck('state', 'rule')->all())->toBe(['Alpha' => 'skipped', 'Beta' => 'committed'])
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 3 files of Beta')
        ->and(file_get_contents($this->root.'/app/F1.php'))->toContain('TODO_FIX')->not->toContain('touched')
        ->and(($this->summary)())->toContain('I committed 1 of 2 kinds of problem')->toContain('Left for us to work through together: Alpha.')
        ->and((new WorkProgress($this->root))->read()['says'])->toContain('Skipped: Alpha.')
        ->and($line)->toContain('1 of 2 problems committed');
});

it('names the changed files no test covers, and tells the studio so it can ask for tests', function (): void {
    $tests = new class($this->root, new Toolbox($this->root)) extends CoveringTests
    {
        public function for(array $paths): array
        {
            return in_array('app/F1.php', $paths, true) ? ['tests/F1Test.php'] : [];
        }

        public function failing(array $tests): ?array
        {
            return [];
        }
    };

    ($this->run)($tests)->run(($this->ask)(3));

    expect(($this->summary)())->toContain('No test covers F2.php, F3.php. I write a test for each once Leftovers is done')->not->toContain('F1.php, F2.php')
        ->and(collect(($this->sent)('SubmitScanWorkRequest'))->last()['untested'])->toBe(['app/F2.php', 'app/F3.php']);
});

it('writes a test for each file it is asked to and commits them all without running them, so Failing tests runs them last', function (): void {
    ($this->git)('checkout', '--quiet', '-b', 'studio/fixes-2026-10-16-0100');
    mkdir($this->root.'/tests/Feature', 0755, true);
    file_put_contents($this->root.'/tests/Feature/ExampleTest.php', "<?php\n\nit('works', fn () => expect(true)->toBeTrue());\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'an example test');
    $tests = new class($this->root, new Toolbox($this->root)) extends CoveringTests
    {
        public array $ran = [];

        public function failures(array $tests): ?array
        {
            $this->ran[] = $tests;

            return collect($tests)->filter(fn (string $test): bool => str_contains((string) file_get_contents($this->where.'/'.$test), 'BROKEN'))->mapWithKeys(fn (string $test): array => [$test.' › it works' => 'Failed asserting that false is true.'])->all();
        }

        public function __construct(public string $where, Toolbox $toolbox)
        {
            parent::__construct($where, $toolbox);
        }
    };
    $test = fn (string $name, string $body): array => ['edits' => [], 'note' => 'Proves the fix.', 'unneeded' => false, 'test' => ['path' => "tests/Feature/{$name}Test.php", 'content' => "<?php\n\nit('works', fn () => {$body});\n"]];
    $this->replies = [
        fn (array $asked): array => ['app/F1.php' => $test('F1', 'true'), 'app/F2.php' => $test('F2', 'BROKEN'), 'app/F3.php' => $test('F3', 'BROKEN')],
    ];
    $ask = ['id' => 7, 'job' => 'tests', 'mode' => 'terminal', 'state' => 'asked', 'branch' => 'studio/fixes-2026-10-16-0100', 'files' => array_map(fn (int $n): array => ['path' => "app/F{$n}.php", 'findings' => []], [1, 2, 3])];

    $line = ($this->run)($tests)->run($ask);
    $sent = ($this->asked)();
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(($this->git)('branch', '--show-current'))->toBe('studio/fixes-2026-10-16-0100')
        ->and($sent)->toHaveCount(1)
        ->and(collect(($this->proposals)())->first()['propose'])->toMatchArray(['kind' => 'tests', 'example' => ['path' => 'tests/Feature/ExampleTest.php', 'content' => "<?php\n\nit('works', fn () => expect(true)->toBeTrue());\n"]])
        ->and($tests->ran)->toBe([])
        ->and(($this->git)('log', '-1', '--format=%s'))->toBe('test(studio): tests for my fixes')
        ->and(($this->git)('show', '--name-only', '--format=', 'HEAD'))->toBe("tests/Feature/F1Test.php\ntests/Feature/F2Test.php\ntests/Feature/F3Test.php")
        ->and(end($work)['batches'][0])->toMatchArray(['state' => 'committed', 'fixed' => 3, 'left' => 0, 'written' => ['tests/Feature/F1Test.php', 'tests/Feature/F2Test.php', 'tests/Feature/F3Test.php']])
        ->and((new WorkProgress($this->root))->read()['says'])->toContain('I wrote 3 tests for my fixes and committed them on studio/fixes-2026-10-16-0100. Failing tests, the last job, runs them.')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('checks at the end that the app still boots, and says so when it does not', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\nfwrite(STDERR, 'Class \"App\\\\Missing\" not found'.PHP_EOL);\nexit(1);\n");

    ($this->run)()->run(($this->ask)(1));
    $asked = collect(($this->sent)('SubmitScanWorkRequest'))->first(fn (array $report): bool => isset($report['question']));

    expect($asked['question']['reason'])->toContain('Your app does not boot now: Class "App\Missing" not found')->not->toContain('still boots');

    file_put_contents($this->root.'/artisan', "<?php\necho '{}';\n");
    ($this->git)('checkout', '--quiet', 'develop');
    (new LastFindings($this->root))->forget();

    ($this->run)()->run(($this->ask)(1));

    expect(collect(($this->sent)('SubmitScanWorkRequest'))->pluck('said')->filter()->implode("\n"))->toContain('Every file I changed compiles, and your app still boots.');
});

it('tries again only the files the check faulted, and keeps the rest of the kind as it passed', function (): void {
    $faulty = fn (array $asked): array => [
        'app/F1.php' => ($this->edit)("\$x = 'DONE';"),
        'app/F2.php' => ($this->edit)("\$x = 'TODO_FIX';\n\$y = 'TODO_FIX';"),
        'app/F3.php' => ($this->edit)("\$x = 'DONE';"),
    ];
    $this->replies = [$faulty, fn (array $asked): array => ['app/F2.php' => ($this->edit)("\$x = 'DONE';")]];

    $line = ($this->run)()->run(($this->ask)(3));
    $sent = ($this->asked)();

    expect($sent)->toHaveCount(2)
        ->and(array_column($sent[1]['files'], 'path'))->toBe(['app/F2.php'])
        ->and($sent[1]['failed'])->toContain('app/F2.php:3 [todo]')
        ->and(($this->git)('show', 'HEAD:app/F1.php'))->toContain("'DONE'")
        ->and(($this->git)('show', 'HEAD:app/F2.php'))->toContain("'DONE'")->not->toContain('$y')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 3 files of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('catches a Blade view an edit would break, and leaves that view as it was', function (): void {
    file_put_contents($this->root.'/app/V.blade.php', "<div>\n\$x = 'TODO_FIX';\n</div>\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'a view');
    $this->replies = [fn (array $asked): array => ['app/F1.php' => ($this->edit)("\$x = 'DONE';"), 'app/V.blade.php' => ($this->edit)('@if ($open) open')]];
    $ask = [...($this->ask)(1), 'files' => [...($this->ask)(1)['files'], ['path' => 'app/V.blade.php', 'findings' => [['tool' => 'phpstan', 'rule' => 'todo', 'line' => 2, 'title' => 'Fix me.']]]]];

    ($this->run)()->run($ask);
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(file_get_contents($this->root.'/app/V.blade.php'))->not->toContain('@if')
        ->and(end($work)['batches'][0]['note'])->toContain('I left V.blade.php as it was, because PHP could not compile the change there (V.blade.php: the view does not compile')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 1 file of Todo');
});

it('counts only the files of the kind: a rise elsewhere is not its fault, and a file with a new finding is put back on its own', function (): void {
    $this->replies = [function (array $asked): array {
        file_put_contents($this->root.'/app/Z.php', "<?php\n\$x = 'TODO_FIX';\n");

        return ($this->clear)($asked);
    }];

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(end($work)['batches'][0])->toMatchArray(['state' => 'committed', 'fixed' => 2, 'left' => 0])
        ->and($line)->toContain('1 of 1 problem committed');

    ($this->git)('checkout', '--quiet', 'develop');
    (new LastFindings($this->root))->forget();
    $rise = fn (array $asked): array => ['app/F1.php' => ($this->edit)("\$x = 'DONE';"), 'app/F2.php' => ($this->edit)("\$x = 'TODO_FIX';\n\$y = 'TODO_FIX';")];
    $this->replies = [$rise, $rise];

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(($this->changed)())->toBe(['app/F1.php'])
        ->and(array_column(($this->asked)()[count(($this->asked)()) - 1]['files'], 'path'))->toBe(['app/F2.php'])
        ->and(end($work)['batches'][0]['note'])->toContain('I put F2.php back as it was')->toContain('1 new finding')->toContain('app/F2.php:3 [todo]')
        ->and(file_get_contents($this->root.'/app/F2.php'))->not->toContain('$y')
        ->and(($this->git)('show', 'HEAD:app/F1.php'))->toContain('DONE')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 1 file of Todo')
        ->and($line)->toContain('1 of 1 problem committed');
});

it('keeps what the studio chose and what it left with the kind, and asks nothing when nothing broke', function (): void {
    $this->replies = [fn (array $asked): array => [
        'app/F1.php' => [...($this->edit)("\$x = 'MAYBE';"), 'note' => 'Renamed F1 to MAYBE, since the page reads it as a label.'],
        'app/F2.php' => ['edits' => [], 'note' => 'They are read only.', 'unneeded' => false],
        'app/F3.php' => ['edits' => [], 'note' => 'They are read only.', 'unneeded' => false],
    ]];
    $this->answer = ['decision' => 'stop', 'note' => null];

    $line = ($this->run)()->run(($this->ask)(3));
    $work = ($this->sent)('SubmitScanWorkRequest');
    $asked = collect($work)->filter(fn (array $report): bool => isset($report['question']))->values();

    expect($asked)->toHaveCount(0)
        ->and(end($work)['batches'][0]['note'])->toContain('I left F2.php, F3.php as they were: They are read only.')->toContain('Renamed F1 to MAYBE, since the page reads it as a label.')
        ->and(($this->git)('show', 'HEAD:app/F1.php'))->toContain('MAYBE')
        ->and(($this->git)('log', '--format=%s', 'develop..HEAD'))->toBe('fix(leftovers): SAMI worked through 1 file of Todo')
        ->and(end($work))->toMatchArray(['state' => 'done'])
        ->and(end($work)['batches'][0]['state'])->toBe('committed');
});

it('takes the answer from the terminal when the developer gives it there', function (): void {
    file_put_contents($this->root.'/artisan', "<?php\nfwrite(STDERR, 'Class \"App\\\\Missing\" not found'.PHP_EOL);\nexit(1);\n");
    $this->answer = null;
    $this->meanwhile = [fn () => (new WorkProgress($this->root))->answered('broke', 'The menu is gone.')];

    ($this->run)()->run(($this->ask)(1));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect((new WorkProgress($this->root))->read()['says'])->toContain('You said something broke: The menu is gone.')
        ->and($this->atQuestion)->toHaveCount(1)
        ->and(collect($work)->contains(fn (array $report): bool => ($report['consumed_answer'] ?? false) === true))->toBeTrue();
});

it('refuses to start over uncommitted changes, and says so in both places, cutting no branch', function (): void {
    file_put_contents($this->root.'/app/F1.php', "<?php\n// mine\n");

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect($line)->toContain('uncommitted changes')
        ->and(($this->git)('branch', '--show-current'))->toBe('develop')
        ->and($work[0])->toMatchArray(['session' => 7, 'state' => 'refused'])
        ->and($work[0]['refused'])->toContain('uncommitted changes')
        ->and((new WorkProgress($this->root))->read())->toMatchArray(['phase' => 'refused']);
});

it('runs nothing of its own on the machine: when the studio does not pick the work up, the files stay as they were and it says so', function (): void {
    $this->silent = true;

    $line = ($this->run)()->run(($this->ask)(2));
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect(collect($work)->contains(fn (array $report): bool => isset($report['question'])))->toBeFalse()
        ->and(end($work)['batches'][0])->toMatchArray(['state' => 'skipped', 'commit' => null])
        ->and(end($work)['batches'][0]['note'])->toContain('F1.php is as it was: the studio did not pick the work up.')
        ->and(($this->git)('status', '--porcelain', '--untracked-files=no'))->toBe('')
        ->and($line)->toContain('0 of 1 problem committed');
});

it('takes a change the developer made in their own editor: asks them why, and commits it with their words', function (): void {
    ($this->git)('checkout', '--quiet', '-b', 'studio/fixes-2026-10-19-1000');
    file_put_contents($this->root.'/app/F1.php', "<?php\n\$x = 'DONE';\n");
    $this->answer = ['decision' => 'approve', 'note' => 'The owner check belongs in the policy'];
    $this->meanwhile = [fn (): int|false => file_put_contents($this->root.'/app/F2.php', "<?php\n\$x = 'DONE';\n")];

    ($this->run)()->run(['id' => 7, 'job' => 'leftovers', 'mode' => 'app', 'state' => 'running', 'branch' => 'studio/fixes-2026-10-19-1000', 'proposals' => [
        ['id' => 11, 'path' => 'app/F1.php', 'why' => 'You changed it in your editor.', 'edits' => [], 'yours' => true, 'tools' => ['phpstan']],
    ]]);
    $work = ($this->sent)('SubmitScanWorkRequest');
    $asked = collect($work)->first(fn (array $report): bool => isset($report['question']))['question'];

    expect($asked)->toMatchArray(['kind' => 'yours', 'files' => ['app/F1.php']])
        ->and($asked['reason'])->toStartWith('You changed app/F1.php in your editor. Why?')
        ->and(($this->git)('branch', '--show-current'))->toBe('studio/fixes-2026-10-19-1000')
        ->and(($this->git)('log', '-1', '--format=%s'))->toBe('fix: The owner check belongs in the policy')
        ->and(($this->git)('log', '-1', '--format=%b'))->toContain('- Why: The owner check belongs in the policy')
        ->and(explode("\n", ($this->git)('show', '--name-only', '--format=', 'HEAD')))->toBe(['app/F1.php', 'app/F2.php'])
        ->and(collect($work)->first(fn (array $report): bool => isset($report['proposals']))['proposals'][0])->toMatchArray(['id' => 11, 'status' => 'applied'])
        ->and(($this->git)('status', '--porcelain', '--untracked-files=no'))->toBe('');
});

it('commits the fixes as the author the studio names, with the developer as committer', function (): void {
    ($this->run)()->run([...($this->ask)(2), 'author' => 'Avatar <12345+artisan-studio[bot]@users.noreply.github.com>']);

    expect(($this->git)('log', '-1', '--format=%an <%ae>'))->toBe('Avatar <12345+artisan-studio[bot]@users.noreply.github.com>')
        ->and(($this->git)('log', '-1', '--format=%cn'))->toBe('Developer');
});

it('still will not start over uncommitted changes that are not the file the developer is fixing', function (): void {
    file_put_contents($this->root.'/app/F1.php', "<?php\n\$x = 'DONE';\n");
    file_put_contents($this->root.'/app/F2.php', "<?php\n// mine\n");

    $line = ($this->run)()->run(['id' => 7, 'job' => 'leftovers', 'mode' => 'app', 'state' => 'running', 'proposals' => [
        ['id' => 11, 'path' => 'app/F1.php', 'why' => 'You changed it in your editor.', 'edits' => [], 'yours' => true, 'tools' => []],
    ]]);

    expect($line)->toContain('uncommitted changes')
        ->and(($this->git)('branch', '--show-current'))->toBe('develop');
});

it('applies what the developer agreed in the app exactly as agreed, one commit each, and says which still have findings', function (): void {
    file_put_contents($this->root.'/app/F2.php', "<?php\n\$a = 'TODO_FIX';\n\$b = 'KEEP';\n\$c = 'TODO_FIX';\n");
    ($this->git)('commit', '--quiet', '-am', 'two todos');
    $ask = [
        'id' => 7,
        'job' => 'leftovers',
        'mode' => 'app',
        'state' => 'running',
        'proposals' => [
            ['id' => 11, 'path' => 'app/F1.php', 'why' => 'Cleared.', 'edits' => [['find' => "\$x = 'TODO_FIX';", 'replace' => "\$x = 'DONE';"]], 'tools' => ['phpstan']],
            ['id' => 12, 'path' => 'app/F2.php', 'why' => 'One of two.', 'edits' => [['find' => "\$a = 'TODO_FIX';", 'replace' => "\$a = 'DONE';"]], 'tools' => ['phpstan']],
            ['id' => 13, 'path' => 'app/F3.php', 'why' => 'Gone.', 'edits' => [['find' => 'not in the file', 'replace' => 'x']], 'tools' => ['phpstan']],
        ],
    ];

    $line = ($this->run)()->run($ask);
    $report = collect(($this->sent)('SubmitScanWorkRequest'))->first(fn (array $report): bool => isset($report['proposals']));
    $byId = collect($report['proposals'])->keyBy('id');

    expect($line)->toContain('Applied 2 of 3')->toContain('Nothing was pushed')
        ->and(($this->git)('branch', '--show-current'))->toStartWith('studio/fixes-')
        ->and(explode("\n", ($this->git)('log', '--format=%s', 'develop..HEAD')))->toBe(['fix(studio): the change you agreed to in app/F2.php', 'fix(studio): the change you agreed to in app/F1.php'])
        ->and($byId[11])->toMatchArray(['status' => 'applied', 'left' => 0])
        ->and($byId[12])->toMatchArray(['status' => 'unresolved', 'left' => 1])
        ->and($byId[12]['note'])->toContain('still reports 1')
        ->and($byId[13])->toMatchArray(['status' => 'failed'])
        ->and($byId[13]['note'])->toContain('not the one SAMI read')
        ->and($byId[11]['commit'])->toMatch('/^[0-9a-f]{7,64}$/')
        ->and(file_get_contents($this->root.'/app/F2.php'))->toContain("\$b = 'KEEP'")
        ->and(($this->git)('status', '--porcelain', '--untracked-files=no'))->toBe('')
        ->and(($this->proposals)())->toBe([]);
});

it('leaves the agreed changes waiting, with the reason, when the tree is not clean', function (): void {
    file_put_contents($this->root.'/app/F1.php', "<?php\n// mine\n");
    $ask = ['id' => 7, 'job' => 'leftovers', 'mode' => 'app', 'state' => 'running', 'proposals' => [['id' => 11, 'path' => 'app/F1.php', 'why' => 'x', 'edits' => [['find' => 'mine', 'replace' => 'yours']], 'tools' => []]]];

    ($this->run)()->run($ask);
    $work = ($this->sent)('SubmitScanWorkRequest');

    expect($work[0])->toMatchArray(['session' => 7])->not->toHaveKey('state')
        ->and($work[0]['refused'])->toContain('uncommitted changes')
        ->and(($this->git)('branch', '--show-current'))->toBe('develop');
});

it('checks only the rule the developer marked as done, in that file, and changes nothing', function (): void {
    file_put_contents($this->root.'/app/F1.php', "<?php\n\$key = 'AKIAIOSFODNN7REALKEY';\n");
    $secrets = fn (string $tool): array => ['ran' => true, 'findings' => [
        ['where' => 'app/F1.php:2', 'rule' => 'aws-access-key', 'message' => 'A key.'],
        ['where' => 'app/F2.php:2', 'rule' => 'github-token', 'message' => 'A token.'],
    ]];
    $run = new WorkRun($this->root, app(Studio::class), new Toolbox($this->root), new LocalBranch($this->root), new WorkProgress($this->root), new TaskJournal(null), 0, $secrets);

    $line = $run->recheck([
        ['id' => 1, 'tool' => 'studio-security', 'rule' => 'aws-access-key', 'path' => 'app/F1.php', 'line' => 2],
        ['id' => 2, 'tool' => 'studio-security', 'rule' => 'aws-access-key', 'path' => 'app/F2.php', 'line' => 2],
        ['id' => 3, 'tool' => 'studio-security', 'rule' => 'github-token', 'path' => 'app/F1.php', 'line' => 2],
    ]);
    $sent = collect(($this->sent)('SubmitScanWorkRequest'))->pluck('recheck')->keyBy('id');

    expect($line)->toBe('Checked 3 places. Nothing was changed.')
        ->and($sent[1]['passed'])->toBeFalse()
        ->and($sent[1]['reason'])->toContain('still in app/F1.php')
        ->and($sent[2]['passed'])->toBeTrue()
        ->and($sent[3]['passed'])->toBeTrue()
        ->and(($this->git)('branch', '--show-current'))->toBe('develop');
});
