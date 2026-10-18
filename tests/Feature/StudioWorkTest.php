<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanWorkRequest;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Work\WorkLauncher;
use ArtisanStudio\StudioCli\Work\WorkPanel;
use ArtisanStudio\StudioCli\Work\WorkProgress;
use Illuminate\Support\Facades\Process as Processes;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| The card for working through a job, on the Insights tab
|--------------------------------------------------------------------------
|
| The app records the ask, and the terminal turns to Insights with the
| card: press ⏎ and the work starts, on a safety branch of its own. When it
| stops to ask, ⏎ opens the question; the answer goes to the run and to the app.
|
*/

beforeEach(function (): void {
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    $this->plain = fn (string $screen): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
    $this->root = sys_get_temp_dir().'/studio-work-tab-'.bin2hex(random_bytes(4));
    @mkdir($this->root, 0755, true);
    file_put_contents($this->root.'/artisan', '<?php sleep(2); echo "Worked.", PHP_EOL;');
    app()->setBasePath($this->root);
    app()->instance(BackgroundTasks::class, new BackgroundTasks($this->root, app(ActivityLog::class)));
    app()->instance(SnapshotSource::class, new class implements SnapshotSource
    {
        /**
         * @var list<array<string, mixed>>
         */
        public array $work = [['id' => 7, 'job' => 'types', 'name' => 'Types', 'mode' => 'terminal', 'files' => 34, 'state' => 'asked']];

        public int $rechecks = 0;

        /**
         * @var array<string, mixed>|null
         */
        public ?array $together = null;

        /**
         * @var list<array<string, mixed>>
         */
        public array $searches = [];

        public function snapshot(): DashboardSnapshot
        {
            return DashboardSnapshot::fromApi(['health' => ['percent' => 76, 'label' => 'Fair', 'scored' => true], 'scan' => ['status' => 'Read locally', 'files_done' => 3048, 'files_total' => 3048], 'work' => ['asked' => $this->work, 'rechecks' => $this->rechecks, 'together' => $this->together, 'searches' => $this->searches]]);
        }

        public function forget(): void {}
    });
    $this->progress = app(WorkProgress::class);
    $this->progress->forget();
    app(FixProgress::class)->forget();
    app(ToolStatus::class)->forget();
    $this->command = app(StudioCommand::class);
    $this->studio = $this->command->screen(ScreenContainer::make());
    $this->insights = fn (): string => ($this->plain)($this->studio->render(170, 40, 'insights'));
    $this->refresh = fn () => $this->studio->tab('insights')->refreshState();
});

afterEach(function (): void {
    $this->progress->forget();
    app(ToolStatus::class)->forget();
});

it('turns to Insights for the job asked to be worked through, with its card saying it starts by itself', function (): void {
    $announce = (new ReflectionClass(StudioCommand::class))->getMethod('openInsightsWhenFixesAreAsked');
    (new ReflectionProperty(StudioCommand::class, 'repository'))->setValue($this->command, 'artisan-studio-projects/artisan-studio');
    $this->command->setLaravel(app());
    $requests = app(ScreenRequests::class);

    $announce->invoke($this->command);

    expect($requests->take())->toMatchArray(['tab' => 'insights']);

    $announce->invoke($this->command);

    expect($requests->take())->toBeNull();

    $screen = ($this->insights)();

    expect($screen)->toContain('[work.asked]')->toContain('Types · 34 files')->toContain('[work.asked line]')
        ->not->toContain('Claude')->not->toContain(' AI')
        ->and(collect($this->studio->footerKeys('insights'))->pluck('label'))->toContain('Work with SAMI');
});

it('starts the work only when Enter is pressed, in the background, on the ask it was shown', function (): void {
    expect(app(BackgroundTasks::class)->isRunning(WorkLauncher::TASK))->toBeFalse();

    $this->studio->tab('insights')->enter();

    expect(app(BackgroundTasks::class)->isRunning(WorkLauncher::TASK))->toBeTrue()
        ->and(app(ActivityLog::class)->ofKinds([WorkLauncher::TASK])[0])->toMatchArray(['label' => 'Work with SAMI'])
        ->and(app(ActivityLog::class)->ofKinds([WorkLauncher::TASK])[0]['detail'])->toContain('Working through Types on a new branch, 34 files')
        ->and($this->studio->tab('insights')->canEnter())->toBeFalse();
});

it('offers to apply what was agreed in the app, with the same press', function (): void {
    app(SnapshotSource::class)->work = [['id' => 9, 'job' => 'types', 'name' => 'Types', 'mode' => 'app', 'files' => 2, 'state' => 'running']];
    ($this->refresh)();

    expect(($this->insights)())->toContain('[work.apply]')->toContain('[work.apply line]')
        ->and(collect($this->studio->footerKeys('insights'))->pluck('label'))->toContain('Work with SAMI');
});

it('follows each kind of problem as it lands, says what is being done, and says when it is done', function (): void {
    $this->progress->begin(7, 'types', 'Types', 'terminal', 'studio/fixes-2026-10-12-1300', array_map(fn (int $n): string => "app/F{$n}.php", range(1, 7)));
    $this->progress->planned(2);
    $this->progress->batch(['number' => 1, 'files' => ['app/F1.php', 'app/F2.php'], 'rule' => 'Missing return types', 'state' => 'committed', 'commit' => 'abcdef1234', 'fixed' => 4, 'left' => 1, 'note' => null, 'started' => microtime(true) - 90, 'worked' => 75.0]);
    $this->progress->batch(['number' => 2, 'files' => ['app/F3.php'], 'rule' => 'Missing parameter types', 'state' => 'running', 'commit' => null, 'fixed' => 0, 'left' => 0, 'note' => null]);
    $this->progress->says('2 of 2: "Missing parameter types", 1 file. I am fixing them all now, then PHPStan checks them again.');
    app(SnapshotSource::class)->work = [];
    ($this->refresh)();

    $working = ($this->insights)();

    expect($working)->toContain('[work.working]')->toContain('I am fixing them all now')
        ->toContain('Working through Types on studio/fixes-2026-10-12-1300')->toContain('4 fixed · 1 of 2 problems · 1m 15s')
        ->toContain('Missing return types')->toContain('2 files')->toContain('4 findings fixed · 1 left')->toContain('abcdef1')->toContain('working…');

    $this->progress->says('All done with Types. Everything you approved is committed on studio/fixes-2026-10-12-1300. Nothing was pushed.');
    $this->progress->finished();

    expect(($this->insights)())->toContain('[work.done]')->toContain('[work.done line]')->toContain('Types on studio/fixes-2026-10-12-1300');

    $this->progress->finished('You stopped me. What you approved is committed on the safety branch.');

    expect(($this->insights)())->toContain('[work.halted]')->not->toContain('[work.done]');
});

it('shows the work on the row of the tool it is for, opens it there with how long each kind took, and stops browsing while it needs an answer', function (): void {
    $status = app(ToolStatus::class);
    $status->running(['pint', 'phpstan']);
    $status->ran('pint', ['ran' => true, 'took' => 3900, 'findings' => []]);
    $status->ran('phpstan', ['ran' => true, 'took' => 59000, 'findings' => array_fill(0, 12, ['x'])]);
    app(FixProgress::class)->begin('studio/fixes-2026-10-15-0800', ['phpstan'], ['phpstan' => 12]);
    app(FixProgress::class)->done('phpstan', true, 8, 3);
    app(FixProgress::class)->finished(['phpstan' => 4]);
    $this->progress->begin(7, 'types', 'Types', 'terminal', 'studio/fixes-2026-10-15-0900', ['app/F1.php', 'app/F2.php', 'app/F3.php']);
    $this->progress->planned(2);
    $this->progress->batch(['number' => 1, 'files' => ['app/F1.php', 'app/F2.php'], 'rule' => 'Missing return types', 'state' => 'committed', 'commit' => 'abcdef1234', 'fixed' => 4, 'left' => 0, 'note' => null, 'started' => microtime(true) - 95, 'finished' => microtime(true) - 20, 'tools' => ['phpstan']]);
    $this->progress->batch(['number' => 2, 'files' => ['app/F3.php'], 'rule' => 'Missing parameter types', 'state' => 'running', 'commit' => null, 'fixed' => 0, 'left' => 0, 'note' => null, 'started' => microtime(true) - 12, 'finished' => null, 'tools' => ['phpstan']]);
    $this->progress->says('2 of 2: "Missing parameter types", 1 file.');
    file_put_contents($this->progress->path(), (string) json_encode([...json_decode((string) file_get_contents($this->progress->path()), true), 'pid' => getmypid()]));
    app(SnapshotSource::class)->work = [];
    ($this->refresh)();
    $tab = $this->studio->tab('insights');

    $top = ($this->insights)();

    expect($top)->toContain('SAMI · 2 of 2')->toContain('[work.working]')->toContain('"Missing parameter types", 1 file.')->not->toContain('[fix.left]')->not->toContain('[fix.clean]')->not->toContain('Working through Types')
        ->and(collect(explode("\n", $top))->first(fn (string $line): bool => str_contains($line, '▸')))->toContain('PHPStan')
        ->and($tab->isNavigable())->toBeTrue()
        ->and(collect($this->studio->footerKeys('insights'))->pluck('label'))->toContain('Details');

    $tab->openWhere(fn (array $row): bool => $row['key'] === 'pint');

    expect($tab->isOpen())->toBeFalse();

    $tab->openWhere(fn (array $row): bool => $row['key'] === 'phpstan');

    expect($tab->isOpen())->toBeTrue()
        ->and(($this->insights)())->toContain('Working through Types on studio/fixes-2026-10-15-0900')->toContain('Missing return types')->toContain('1m 15s')->toContain('working…')->toContain('Esc goes back to the fixes');

    $tab->close();
    $this->progress->stopped(['batch' => 2, 'files' => ['app/F3.php'], 'reason' => 'Should F3 keep its string?', 'options' => ['retry', 'stop']]);
    ($this->refresh)();

    expect(($this->insights)())->toContain('SAMI needs you')->toContain('Should F3 keep its string?')
        ->and($tab->isNavigable())->toBeFalse()
        ->and(collect($this->studio->footerKeys('insights'))->pluck('label'))->toContain('Answer SAMI')->not->toContain('Details');
});

it('opens the work on its own once it starts on a kind, and only once for a run', function (): void {
    $status = app(ToolStatus::class);
    $status->running(['phpstan']);
    $status->ran('phpstan', ['ran' => true, 'took' => 59000, 'findings' => array_fill(0, 12, ['x'])]);
    app(FixProgress::class)->begin('studio/fixes-2026-10-15-0800', ['phpstan'], ['phpstan' => 12]);
    app(FixProgress::class)->done('phpstan', true, 8, 3);
    app(FixProgress::class)->finished(['phpstan' => 4]);
    $this->progress->begin(7, 'types', 'Types', 'terminal', 'studio/fixes-2026-10-15-0900', ['app/F1.php']);
    $this->progress->batch(['number' => 1, 'files' => ['app/F1.php'], 'rule' => 'Missing return types', 'state' => 'running', 'commit' => null, 'fixed' => 0, 'left' => 0, 'note' => null, 'started' => microtime(true), 'finished' => null, 'tools' => ['phpstan']]);
    file_put_contents($this->progress->path(), (string) json_encode([...json_decode((string) file_get_contents($this->progress->path()), true), 'pid' => getmypid()]));
    app(SnapshotSource::class)->work = [];
    ($this->refresh)();
    $opens = (new ReflectionClass(StudioCommand::class))->getMethod('openTheWorkOnceItStarts');
    (new ReflectionProperty(StudioCommand::class, 'repository'))->setValue($this->command, 'artisan-studio-projects/artisan-studio');
    $this->command->setLaravel(app());
    $requests = app(ScreenRequests::class);

    $opens->invoke($this->command);
    $request = $requests->take();
    $request['then']($this->studio->tab('insights'));

    expect($request['tab'])->toBe('insights')
        ->and($this->studio->tab('insights')->isOpen())->toBeTrue()
        ->and(($this->insights)())->toContain('Working through Types on studio/fixes-2026-10-15-0900')->toContain('Esc goes back to the fixes');

    $this->studio->tab('insights')->close();
    $opens->invoke($this->command);

    expect($requests->take())->toBeNull();
});

it('starts the waiting job with ⏎ from the work\'s detail view too, and shows it in the footer', function (): void {
    file_put_contents($this->root.'/artisan', '<?php file_put_contents(__DIR__."/started.txt", implode(" ", array_slice($argv, 1)));');
    $status = app(ToolStatus::class);
    $status->running(['phpstan']);
    $status->ran('phpstan', ['ran' => true, 'took' => 59000, 'findings' => array_fill(0, 12, ['x'])]);
    app(FixProgress::class)->begin('studio/fixes-2026-10-16-0100', ['phpstan'], ['phpstan' => 12]);
    app(FixProgress::class)->done('phpstan', true, 8, 3);
    app(FixProgress::class)->finished(['phpstan' => 4]);
    $this->progress->begin(6, 'types', 'Types', 'terminal', 'studio/fixes-2026-10-16-0100', ['app/F1.php']);
    $this->progress->batch(['number' => 1, 'files' => ['app/F1.php'], 'rule' => 'Missing return types', 'state' => 'committed', 'commit' => 'abcdef1234', 'fixed' => 2, 'left' => 0, 'note' => null, 'started' => microtime(true) - 30, 'finished' => microtime(true), 'tools' => ['phpstan']]);
    app(SnapshotSource::class)->work = [];
    ($this->refresh)();
    $tab = $this->studio->tab('insights');
    $tab->openWhere(fn (array $record): bool => in_array($record['key'] ?? null, WorkPanel::insights()->tools(app(SnapshotSource::class)->snapshot()), true));
    $this->progress->finished();
    app(SnapshotSource::class)->work = [['id' => 9, 'job' => 'tests', 'name' => 'Tests for the fixes', 'mode' => 'terminal', 'files' => 3, 'state' => 'asked']];
    ($this->refresh)();

    expect($tab->isOpen())->toBeTrue()
        ->and(collect($this->studio->footerKeys('insights'))->pluck('label'))->toContain('Work with SAMI');

    $tab->enter();
    $tries = 0;

    while (! is_file($this->root.'/started.txt') && $tries++ < 50) {
        usleep(100_000);
    }

    expect((string) @file_get_contents($this->root.'/started.txt'))->toContain('--session=9');
});

it('starts what the developer asked for in the app by itself, agreed changes before tests before the next job, each only once', function (): void {
    file_put_contents($this->root.'/artisan', '<?php file_put_contents(__DIR__."/started.txt", implode(" ", array_slice($argv, 1)).PHP_EOL, FILE_APPEND);');
    app(SnapshotSource::class)->work = [
        ['id' => 7, 'job' => 'types', 'name' => 'Types', 'mode' => 'terminal', 'files' => 34, 'state' => 'asked'],
        ['id' => 9, 'job' => 'tests', 'name' => 'Tests for the fixes', 'mode' => 'terminal', 'files' => 3, 'state' => 'asked'],
        ['id' => 8, 'job' => 'livewire', 'name' => 'Livewire safety', 'mode' => 'app', 'files' => 1, 'state' => 'running'],
    ];
    $starts = (new ReflectionClass(StudioCommand::class))->getMethod('startWhatTheDeveloperAgreed');
    (new ReflectionProperty(StudioCommand::class, 'repository'))->setValue($this->command, 'artisan-studio-projects/artisan-studio');
    $this->command->setLaravel(app());
    $started = function (int $lines): array {
        $tries = 0;

        while (count(array_filter(explode(PHP_EOL, (string) @file_get_contents($this->root.'/started.txt')))) < $lines && $tries++ < 50) {
            usleep(100_000);
        }

        usleep(300_000);

        return array_values(array_filter(explode(PHP_EOL, (string) @file_get_contents($this->root.'/started.txt'))));
    };

    $starts->invoke($this->command);
    $first = $started(1);
    $starts->invoke($this->command);
    $second = $started(2);
    $starts->invoke($this->command);
    $third = $started(3);
    $starts->invoke($this->command);

    expect($first)->toHaveCount(1)
        ->and($first[0])->toContain('--session=8')
        ->and($second)->toHaveCount(2)
        ->and($second[1])->toContain('--session=9')
        ->and($third)->toHaveCount(3)
        ->and($third[2])->toContain('--session=7')
        ->and($started(4))->toHaveCount(3);
});

it('opens the file being worked on in Leftovers in the editor once, and tells the studio about a file the developer changes there, once', function (): void {
    $git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    @mkdir($this->root.'/app', 0755, true);
    file_put_contents($this->root.'/app/A.php', "<?php\n");
    file_put_contents($this->root.'/app/B.php', "<?php\n");
    $git('init', '--quiet', '--initial-branch=develop');
    $git('config', 'user.email', 'dev@example.com');
    $git('config', 'user.name', 'Developer');
    $git('add', '-A');
    $git('commit', '--quiet', '-m', 'start');
    config(['studio-cli.review.editor' => Editor::VSCODE]);
    app()->instance(Editor::class, new Editor($this->root));
    Processes::fake();
    Saloon::fake([SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202)]);
    app(SnapshotSource::class)->together = ['session' => 12, 'open' => 'app/A.php', 'watch' => ['app/A.php', 'app/B.php']];
    $alongside = (new ReflectionClass(StudioCommand::class))->getMethod('workTogetherWhenAsked');
    (new ReflectionProperty(StudioCommand::class, 'repository'))->setValue($this->command, 'artisan-studio-projects/artisan-studio');
    $this->command->setLaravel(app());

    $alongside->invoke($this->command);
    file_put_contents($this->root.'/app/B.php', "<?php\n// theirs\n");
    $alongside->invoke($this->command);
    $alongside->invoke($this->command);

    expect(collect(Saloon::mockClient()->getRecordedResponses())->map(fn ($response): array => $response->getPendingRequest()->body()->all())->filter(fn (array $body): bool => isset($body['changed']))->values()->all())
        ->toBe([['session' => 12, 'changed' => ['app/B.php']]]);
    Processes::assertRanTimes(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'app/A.php'), 1);
});

it('runs the searches the studio asks for on the real files with git grep, once each, and never sends a secret', function (): void {
    $git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    @mkdir($this->root.'/resources/views', 0755, true);
    file_put_contents($this->root.'/resources/views/panel.blade.php', "<div>\n<textarea wire:model=\"revisionNote\"></textarea>\n</div>\n");
    file_put_contents($this->root.'/.env', "REVISION_NOTE_KEY=secret\n");
    $git('init', '--quiet', '--initial-branch=develop');
    $git('config', 'user.email', 'dev@example.com');
    $git('config', 'user.name', 'Developer');
    $git('add', '-A', '-f');
    $git('commit', '--quiet', '-m', 'start');
    Saloon::fake([SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202)]);
    app(SnapshotSource::class)->searches = [['id' => 'abc123', 'pattern' => 'revisionNote|REVISION_NOTE', 'in' => null]];
    $searches = (new ReflectionClass(StudioCommand::class))->getMethod('runTheSearchesAsked');
    (new ReflectionProperty(StudioCommand::class, 'repository'))->setValue($this->command, 'artisan-studio-projects/artisan-studio');
    $this->command->setLaravel(app());

    $searches->invoke($this->command);
    $searches->invoke($this->command);

    expect(collect(Saloon::mockClient()->getRecordedResponses())->map(fn ($response): array => $response->getPendingRequest()->body()->all())->filter(fn (array $body): bool => isset($body['searched']))->values()->all())
        ->toBe([['searched' => [['id' => 'abc123', 'matches' => ['resources/views/panel.blade.php:2:<textarea wire:model="revisionNote"></textarea>'], 'cut' => false]]]]);
});

it('counts only the time spent working on each kind, never the time waiting for the developer', function (): void {
    $now = microtime(true);
    $this->progress->begin(7, 'types', 'Types', 'terminal', 'studio/fixes-2026-10-15-1400', ['app/F1.php', 'app/F2.php', 'app/F3.php']);
    $this->progress->planned(3);
    $this->progress->batch(['number' => 1, 'files' => ['app/F1.php'], 'rule' => 'Missing return types', 'state' => 'committed', 'commit' => 'abcdef1234', 'fixed' => 2, 'left' => 0, 'note' => null, 'started' => $now - 300, 'finished' => $now - 10, 'tools' => ['phpstan'], 'waited' => 250.0, 'worked' => 40.0]);
    $this->progress->batch(['number' => 2, 'files' => ['app/F2.php'], 'rule' => 'Missing parameter types', 'state' => 'ready', 'commit' => null, 'fixed' => 1, 'left' => 0, 'note' => null, 'started' => $now - 300, 'finished' => $now - 260, 'tools' => ['phpstan'], 'waited' => 0.0, 'worked' => 40.0]);
    $this->progress->batch(['number' => 3, 'files' => ['app/F3.php'], 'rule' => 'Missing property types', 'state' => 'ready', 'commit' => null, 'fixed' => 1, 'left' => 0, 'note' => null, 'started' => $now - 300, 'finished' => $now - 260, 'tools' => ['phpstan'], 'waited' => 0.0, 'worked' => 40.0]);
    $this->progress->stopped(['batch' => 2, 'files' => ['app/F2.php'], 'reason' => 'Check it.', 'options' => ['approve', 'leave'], 'kind' => 'review', 'asked_at' => $now - 5]);
    file_put_contents($this->progress->path(), (string) json_encode([...json_decode((string) file_get_contents($this->progress->path()), true), 'pid' => getmypid(), 'at' => $now - 300]));
    app(SnapshotSource::class)->work = [];
    ($this->refresh)();
    $tab = $this->studio->tab('insights');
    $status = WorkPanel::insights()->status(app(SnapshotSource::class)->snapshot());

    $screen = ($this->insights)();

    expect($screen)->toContain('40s')->not->toContain('4m 50s')->toContain('waiting for you')->toContain('checked')
        ->and($status['elapsed'])->toBeLessThan(50_000)->toBeGreaterThan(40_000)
        ->and(collect($tab->enter()[0]->visibleActions())->map(fn ($action): string => $action->getLabel())->all())->toBe(['It works, commit it and go on', 'Skip this and move on']);
});

it('replaces a finished run with the new ask, and with the scan that came after it', function (): void {
    $this->progress->begin(7, 'types', 'Types', 'terminal', 'studio/fixes-x', ['app/F1.php']);
    $this->progress->finished();
    app(SnapshotSource::class)->work = [];
    ($this->refresh)();

    expect(($this->insights)())->toContain('[work.done]');

    app(SnapshotSource::class)->work = [['id' => 8, 'job' => 'livewire', 'name' => 'Livewire safety', 'mode' => 'terminal', 'files' => 30, 'state' => 'asked']];
    ($this->refresh)();

    expect(($this->insights)())->not->toContain('[work.done]')->toContain('[work.asked]')->toContain('Livewire safety · 30 files');

    app(SnapshotSource::class)->work = [];
    app(ToolStatus::class)->asked(['pint']);
    ($this->refresh)();

    expect(($this->insights)())->not->toContain('[work.done]');
});

it('stops to ask in the terminal, and takes the answer there and sends it to the app as well', function (): void {
    Saloon::fake([SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202)]);
    $this->progress->begin(7, 'types', 'Types', 'terminal', 'studio/fixes-x', ['app/F1.php', 'app/F2.php']);
    $this->progress->stopped(['batch' => 1, 'files' => ['app/F1.php'], 'reason' => 'PHPStan still finds 4 things in these files after my change.', 'options' => ['retry', 'skip', 'talk', 'stop']]);
    app(SnapshotSource::class)->work = [['id' => 7, 'job' => 'types', 'name' => 'Types', 'mode' => 'terminal', 'files' => 2, 'state' => 'stopped']];
    ($this->refresh)();
    $pid = getmypid();
    file_put_contents($this->progress->path(), (string) json_encode([...json_decode((string) file_get_contents($this->progress->path()), true), 'pid' => $pid]));

    $screen = ($this->insights)();
    $tab = $this->studio->tab('insights');
    $panel = $tab->enter();

    expect($screen)->toContain('[work.stopped]')->toContain('PHPStan still finds 4 things in these files after my change.')
        ->and(collect($this->studio->footerKeys('insights'))->pluck('label'))->toContain('Answer SAMI')
        ->and($panel)->toHaveCount(1)
        ->and(collect($panel[0]->visibleActions())->map(fn ($action): string => $action->getLabel())->all())->toBe(['Try again', 'Keep it and move on', 'Talk it through in the app', 'Stop here']);

    $said = $panel[0]->visibleActions()[0]->run('Use a union type.');
    $sent = collect(Saloon::mockClient()->getRecordedResponses())->map(fn ($response): array => $response->getPendingRequest()->body()->all())->last();

    expect($said)->toBe('Told SAMI.')
        ->and($this->progress->takeAnswer())->toBe(['decision' => 'retry', 'note' => 'Use a union type.'])
        ->and($sent)->toBe(['session' => 7, 'answer' => ['decision' => 'retry', 'note' => 'Use a union type.']]);
});

it('says the work stopped when its process has gone, and offers to carry on', function (): void {
    $this->progress->begin(7, 'types', 'Types', 'terminal', 'studio/fixes-x', ['app/F1.php']);
    file_put_contents($this->progress->path(), (string) json_encode([...json_decode((string) file_get_contents($this->progress->path()), true), 'pid' => 999999]));
    ($this->refresh)();

    expect(($this->insights)())->toContain('[work.died]')
        ->and(collect($this->studio->footerKeys('insights'))->pluck('label'))->toContain('Work with SAMI');
});

it('checks the places marked as done on its own, since it only reads', function (): void {
    app(SnapshotSource::class)->work = [];
    app(SnapshotSource::class)->rechecks = 2;
    (new ReflectionProperty(StudioCommand::class, 'repository'))->setValue($this->command, 'artisan-studio-projects/artisan-studio');
    $this->command->setLaravel(app());

    (new ReflectionClass(StudioCommand::class))->getMethod('openInsightsWhenFixesAreAsked')->invoke($this->command);

    expect(app(BackgroundTasks::class)->isRunning(WorkLauncher::RECHECKS))->toBeTrue()
        ->and(app(ActivityLog::class)->ofKinds([WorkLauncher::RECHECKS])[0]['detail'])->toBe('Checking the places you marked as done…');
});
