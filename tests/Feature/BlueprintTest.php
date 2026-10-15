<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Blueprint;
use ArtisanStudio\StudioCli\Console\BlueprintCommand;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitBlueprintRequest;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\StateFile;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Broken;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Customer;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Invoice;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\InvoiceStatus;
use ArtisanStudio\StudioCli\Work\WorkLauncher;
use ArtisanStudio\StudioCli\Work\WorkProgress;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config([
        'database.default' => 'blueprint',
        'database.connections.blueprint' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);

    DB::statement('create table customers (id integer primary key not null, name varchar(120) not null)');
    DB::statement("create table invoices (id integer primary key not null, customer_id integer not null, status varchar(20) not null default 'draft', total decimal(8,2) not null default 0, settled tinyint(1) not null default 0, lines text null, paid_at datetime null)");

    $this->blueprint = new Blueprint(dirname(__DIR__).'/Fixtures/Blueprint', 'ArtisanStudio\\StudioCli\\Tests\\Fixtures\\Blueprint\\', app(ModelInspector::class));

    $this->app->instance(Blueprint::class, $this->blueprint);
    $this->app->instance(LocalChanges::class, new LocalChanges(sys_get_temp_dir()));

    $this->sent = fn (): ?PendingRequest => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): PendingRequest => $response->getPendingRequest())
        ->first(fn (PendingRequest $request): bool => $request->getRequest() instanceof SubmitBlueprintRequest);
});

it('finds the models, and only the models', function (): void {
    expect($this->blueprint->models()->all())->toBe([Broken::class, Customer::class, Invoice::class]);
});

it('maps names, the database\'s own column types, enum casts and relationships, and leaves out a model it cannot read', function (): void {
    $mapped = $this->blueprint->map();
    $shapes = array_map(fn (array $model): array => [
        ...Arr::except($model, ['observers']),
        'columns' => array_map(fn (array $column): array => Arr::only($column, ['name', 'enum', 'nullable']), $model['columns']),
    ], $mapped['models']);
    $types = collect($mapped['models'])->flatMap(fn (array $model): array => array_column($model['columns'], 'database_type'));

    expect($mapped['skipped'])->toBe([Broken::class])
        ->and($types->every(fn (mixed $type): bool => is_string($type) && $type !== ''))->toBeTrue()
        ->and($shapes)->toBe([
            [
                'class' => Customer::class,
                'table' => 'customers',
                'columns' => [
                    ['name' => 'id', 'enum' => false, 'nullable' => false],
                    ['name' => 'name', 'enum' => false, 'nullable' => false],
                ],
                'relationships' => [
                    ['name' => 'invoices', 'type' => 'HasMany', 'related' => Invoice::class],
                ],
            ],
            [
                'class' => Invoice::class,
                'table' => 'invoices',
                'columns' => [
                    ['name' => 'id', 'enum' => false, 'nullable' => false],
                    ['name' => 'customer_id', 'enum' => false, 'nullable' => false],
                    ['name' => 'status', 'enum' => true, 'nullable' => false],
                    ['name' => 'total', 'enum' => false, 'nullable' => false],
                    ['name' => 'settled', 'enum' => false, 'nullable' => false],
                    ['name' => 'lines', 'enum' => false, 'nullable' => true],
                    ['name' => 'paid_at', 'enum' => false, 'nullable' => true],
                ],
                'relationships' => [
                    ['name' => 'customer', 'type' => 'BelongsTo', 'related' => Customer::class],
                ],
            ],
        ]);
});

it('adds what the model itself says about each column: its cast, and whether it is fillable, hidden or unique', function (): void {
    $invoice = collect($this->blueprint->map()['models'])->firstWhere('class', Invoice::class);
    $columns = collect($invoice['columns'])->keyBy('name');

    expect($columns['status'])->toMatchArray(['cast' => InvoiceStatus::class, 'fillable' => false, 'hidden' => false])
        ->and($columns['lines']['cast'])->toBe('array')
        ->and($columns['id']['unique'])->toBeBool()
        ->and($invoice['observers'])->toBeArray()
        ->and(json_encode($invoice))->not->toContain('draft');
});

it('prints what it would send, and sends nothing', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    $this->artisan(BlueprintCommand::SIGNATURE, ['--json' => true])
        ->expectsOutputToContain('"class": "'.str_replace('\\', '\\\\', Invoice::class).'"')
        ->doesntExpectOutputToContain('draft')
        ->assertSuccessful();

    expect(($this->sent)())->toBeNull();
});

it('sends the map to the studio', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    $this->artisan(BlueprintCommand::SIGNATURE)
        ->expectsOutputToContain('Could not read Broken')
        ->expectsOutputToContain('Sent the map of 2 models')
        ->assertSuccessful();

    $request = ($this->sent)();

    expect($request->getUrl())->toBe('https://studio.test/api/v1/projects/1/blueprint')
        ->and($request->body()->all())->toBe(['commit' => null, 'models' => $this->blueprint->map()['models']])
        ->and((string) $request->body())->not->toContain('draft')->not->toContain('(20)');
});

it('says so when the studio does not take it', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['message' => 'Forbidden.'], 403)]);

    $this->artisan(BlueprintCommand::SIGNATURE)
        ->expectsOutputToContain('The studio did not take the blueprint')
        ->assertFailed();
});

it('sends nothing from a project that is not linked', function (): void {
    config(['studio-cli.token' => null]);
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    $this->artisan(BlueprintCommand::SIGNATURE)
        ->expectsOutputToContain('not linked yet')
        ->assertSuccessful();

    expect(($this->sent)())->toBeNull();
});

function runsInTheBackgroundAt(string $root, int $exit, string $line): BackgroundTasks
{
    @mkdir($root, 0755, true);
    file_put_contents($root.'/artisan', '<?php echo '.var_export($line, true).', PHP_EOL; exit('.$exit.');');

    return app()->instance(BackgroundTasks::class, new BackgroundTasks($root, app(ActivityLog::class)));
}

function untilBothFinish(BackgroundTasks $tasks): void
{
    $until = microtime(true) + 10;

    while (($tasks->isRunning('blueprint') || $tasks->isRunning('conventions')) && microtime(true) < $until) {
        usleep(20_000);
    }

    $tasks->tick();
}

it('maps the blueprint and counts the conventions in the background when the studio asks, saying so at once', function (): void {
    $tasks = runsInTheBackgroundAt(sys_get_temp_dir().'/studio-asked-'.bin2hex(random_bytes(4)), 0, 'Sent the map of 2 models. SAMI labels them next.');

    event(new StudioReported(['type' => 'blueprint', 'kind' => 'project']));

    expect(app(ActivityLog::class)->ofKinds(['blueprint'])[0])->toMatchArray(['label' => 'Blueprint', 'detail' => 'Mapping your models…', 'colour' => 'cyan'])
        ->and(app(ActivityLog::class)->ofKinds(['conventions'])[0])->toMatchArray(['label' => 'Conventions', 'detail' => 'Counting how your project is written…'])
        ->and(app(ActivityLog::class)->ofKinds(['tests'])[0])->toMatchArray(['label' => 'Your tests', 'detail' => 'Checking whether you switched your tests on…'])
        ->and(app(ActivityLog::class)->ofKinds(['tools'])[0])->toMatchArray(['label' => 'Scan tools']);

    untilBothFinish($tasks);

    expect(app(ActivityLog::class)->ofKinds(['blueprint'])[0])->toMatchArray(['detail' => 'Sent the map of 2 models. SAMI labels them next.', 'colour' => 'green']);
});

it('runs the checking tools again when the studio asks for the extra checks picked in the app, keeping the last test run', function (): void {
    $tasks = runsInTheBackgroundAt(sys_get_temp_dir().'/studio-tools-'.bin2hex(random_bytes(4)), 0, 'Ran your checking tools.');
    app(ToolStatus::class)->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);

    event(new StudioReported(['type' => 'tools', 'kind' => 'project']));

    expect(app(ActivityLog::class)->ofKinds(['tools'])[0])->toMatchArray(['label' => 'Scan tools', 'detail' => 'Running the extra checks you picked…'])
        ->and(app(ActivityLog::class)->ofKinds(['blueprint']))->toBe([])
        ->and(app(ToolStatus::class)->tests())->toMatchArray(['tests' => 10]);

    $until = microtime(true) + 10;

    while ($tasks->isRunning('tools') && microtime(true) < $until) {
        usleep(20_000);
    }

    $tasks->tick();
    @unlink(app(ToolStatus::class)->path());

    expect(app(ActivityLog::class)->ofKinds(['tools'])[0])->toMatchArray(['detail' => 'Ran your checking tools.', 'colour' => 'green']);
});

it('waits for a picked rule to be installed before it runs any tools, so they all run in one go', function (): void {
    runsInTheBackgroundAt(sys_get_temp_dir().'/studio-picked-'.bin2hex(random_bytes(4)), 0, 'Ran your checking tools.');
    @unlink(app(ToolStatus::class)->path());

    app(ScreenRequests::class)->take();

    event(new StudioReported(['type' => 'tools', 'kind' => 'project', 'tools' => ['phpstan']]));

    expect(app(ActivityLog::class)->ofKinds(['tools']))->toBe([])
        ->and(app(ToolStatus::class)->asks('phpstan'))->toBeTrue()
        ->and(array_keys(app(ToolStatus::class)->missing()))->toBe(['phpstan'])
        ->and(app(ScreenRequests::class)->take())->toMatchArray(['tab' => 'scan']);

    @unlink(app(ToolStatus::class)->path());
});

it('starts measuring code coverage in the background the moment it is picked, beside the other checks, without the tests row running again', function (): void {
    $tasks = runsInTheBackgroundAt(sys_get_temp_dir().'/studio-coverage-'.bin2hex(random_bytes(4)), 0, 'Code coverage measured.');
    @unlink(app(ToolStatus::class)->path());
    app(ToolStatus::class)->testsFinished(['ran' => true, 'took' => 58_000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);

    event(new StudioReported(['type' => 'tools', 'kind' => 'project', 'tools' => ['pest-coverage']]));

    expect(app(ActivityLog::class)->ofKinds(['coverage'])[0])->toMatchArray(['label' => 'Code coverage', 'detail' => 'Measuring code coverage in the background…'])
        ->and(app(ActivityLog::class)->ofKinds(['tools'])[0])->toMatchArray(['label' => 'Scan tools'])
        ->and($tasks->isRunning('coverage'))->toBeTrue()
        ->and(app(ToolStatus::class)->tests()['state'])->toBe(ToolStatus::RAN);

    $until = microtime(true) + 10;

    while (($tasks->isRunning('coverage') || $tasks->isRunning('tools')) && microtime(true) < $until) {
        usleep(20_000);
    }

    $tasks->tick();
    @unlink(app(ToolStatus::class)->path());
});

it('stops what the scan is running, and everything that started, when the developer resets it in the app', function (): void {
    $root = sys_get_temp_dir().'/studio-reset-'.bin2hex(random_bytes(4));
    @mkdir($root, 0755, true);
    file_put_contents($root.'/artisan', '<?php sleep(30);');
    $tasks = app()->instance(BackgroundTasks::class, new BackgroundTasks($root, app(ActivityLog::class)));
    app(ToolStatus::class)->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);

    $fixes = app(FixProgress::class);
    $fixes->begin('studio/fixes-reset', ['pint'], ['pint' => 3]);
    $fixes->finished(['pint' => 0]);

    $tasks->start('tools', 'Scan tools', 'Running the extra checks you picked…', ['studio:tools']);
    $tasks->start(WorkLauncher::RECHECKS, 'Check again', 'Checking the places you marked as done…', ['studio:work', '--rechecks']);

    expect($tasks->isRunning('tools'))->toBeTrue()
        ->and($tasks->isRunning(WorkLauncher::RECHECKS))->toBeTrue();

    event(new StudioReported(['type' => 'reset', 'kind' => 'project']));

    expect($tasks->isRunning('tools'))->toBeFalse()
        ->and($tasks->isRunning(WorkLauncher::RECHECKS))->toBeFalse()
        ->and(app(ActivityLog::class)->ofKinds(['tools'])[0])->toMatchArray(['detail' => 'Stopped, because the scan was reset.', 'colour' => 'amber'])
        ->and(app(ToolStatus::class)->tests())->toBeNull()
        ->and($fixes->read())->toBeNull();
});

it('acts once on a reset made while the terminal was closed, when the studio says it again on connecting', function (): void {
    $resetAt = (int) (microtime(true) * 1000) + 60_000;
    @unlink(StateFile::for('reset', base_path(), '.txt'));
    app(ToolStatus::class)->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 10, 'failed' => 0, 'skipped' => 0]]);
    $fixes = app(FixProgress::class);
    $fixes->begin('studio/fixes-closed', ['pint'], ['pint' => 3]);
    $fixes->finished(['pint' => 0]);

    event(new StudioReported(['type' => 'reset', 'kind' => 'project', 'reset_at' => $resetAt, 'history' => true]));

    expect($fixes->read())->toBeNull()
        ->and(app(ToolStatus::class)->tests())->toBeNull();

    app(ToolStatus::class)->testsFinished(['ran' => true, 'took' => 1000, 'summary' => ['tests' => 12, 'failed' => 0, 'skipped' => 0]]);

    event(new StudioReported(['type' => 'reset', 'kind' => 'project', 'reset_at' => $resetAt, 'history' => true]));

    expect(app(ToolStatus::class)->tests())->not->toBeNull();
});
it('only maps the blueprint when asked', function (): void {
    Saloon::fake([SubmitBlueprintRequest::class => MockResponse::make(['accepted' => 2], 202)]);

    event(new StudioReported(['type' => 'changed', 'kind' => 'project']));

    expect(($this->sent)())->toBeNull()
        ->and(app(ActivityLog::class)->ofKinds(['blueprint']))->toBe([]);
});

it('says so in the activity feed when the studio does not take it', function (): void {
    $tasks = runsInTheBackgroundAt(sys_get_temp_dir().'/studio-refused-'.bin2hex(random_bytes(4)), 1, 'The studio did not take the blueprint.');

    event(new StudioReported(['type' => 'blueprint', 'kind' => 'project']));
    untilBothFinish($tasks);

    expect(app(ActivityLog::class)->ofKinds(['blueprint'])[0])->toMatchArray(['detail' => 'The studio did not take the blueprint.', 'colour' => 'amber']);
});

it('stops a fix run still going when the scan is reset, and clears it from the terminal', function (): void {
    $run = new Process(['sleep', '30']);
    $run->start();
    $fixes = app(FixProgress::class);
    file_put_contents($fixes->path(), (string) json_encode(['phase' => FixProgress::FIXING, 'branch' => 'studio/fixes-reset', 'at' => microtime(true), 'pid' => $run->getPid(), 'rulesets' => ['rector' => ['state' => FixProgress::RUNNING, 'found' => 3, 'fixed' => 0, 'files' => 0]]]));
    @unlink(StateFile::for('reset', base_path(), '.txt'));

    expect($fixes->isAlive())->toBeTrue();

    event(new StudioReported(['type' => 'reset', 'kind' => 'project', 'reset_at' => (int) (microtime(true) * 1000) + 1000]));
    usleep(300_000);

    expect($run->isRunning())->toBeFalse()
        ->and($fixes->read())->toBeNull();
});

it('stops the work on a job when the scan is reset, and clears its card and question from the terminal', function (): void {
    $run = new Process(['sleep', '30']);
    $run->start();
    $work = app(WorkProgress::class);
    file_put_contents($work->path(), (string) json_encode(['phase' => WorkProgress::STOPPED, 'session' => 4, 'job' => 'livewire', 'name' => 'Livewire safety', 'mode' => 'terminal', 'branch' => 'studio/fixes-reset', 'files' => ['app/Livewire/Cart.php'], 'at' => microtime(true), 'pid' => $run->getPid(), 'batches' => [], 'question' => ['batch' => 1, 'files' => ['app/Livewire/Cart.php'], 'reason' => 'Check it on your machine.', 'options' => ['approve', 'stop']], 'answer' => null, 'reason' => null]));
    @unlink(StateFile::for('reset', base_path(), '.txt'));

    expect($work->isAlive())->toBeTrue();

    event(new StudioReported(['type' => 'reset', 'kind' => 'project', 'reset_at' => (int) (microtime(true) * 1000) + 1000]));
    usleep(300_000);

    expect($run->isRunning())->toBeFalse()
        ->and($work->read())->toBeNull();
});

it('clears a finished work run from the terminal when the scan is reset', function (): void {
    $work = app(WorkProgress::class);
    $work->begin(2, 'livewire', 'Livewire safety', 'terminal', 'studio/fixes-reset', ['app/Livewire/Cart.php']);
    $work->finished('You stopped me.');
    @unlink(StateFile::for('reset', base_path(), '.txt'));

    expect($work->read())->not->toBeNull();

    event(new StudioReported(['type' => 'reset', 'kind' => 'project', 'reset_at' => (int) (microtime(true) * 1000) + 1000]));

    expect($work->read())->toBeNull();
});

it('leaves a work run started after the reset alone when the studio says that reset again on connecting', function (): void {
    $run = new Process(['sleep', '30']);
    $run->start();
    $work = app(WorkProgress::class);
    file_put_contents($work->path(), (string) json_encode(['phase' => WorkProgress::WORKING, 'session' => 5, 'job' => 'livewire', 'name' => 'Livewire safety', 'mode' => 'terminal', 'branch' => 'studio/fixes-later', 'files' => ['app/Livewire/Cart.php'], 'at' => microtime(true), 'pid' => $run->getPid(), 'batches' => [], 'question' => null, 'answer' => null, 'reason' => null]));
    @unlink(StateFile::for('reset', base_path(), '.txt'));

    event(new StudioReported(['type' => 'reset', 'kind' => 'project', 'reset_at' => (int) (microtime(true) * 1000) - 60_000, 'history' => true]));
    usleep(300_000);

    expect($run->isRunning())->toBeTrue()
        ->and($work->read())->toMatchArray(['session' => 5, 'phase' => WorkProgress::WORKING]);

    $run->stop();
});
