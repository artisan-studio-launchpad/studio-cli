<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Blueprint;
use ArtisanStudio\StudioCli\Console\BlueprintCommand;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitBlueprintRequest;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Broken;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Customer;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Invoice;
use ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\InvoiceStatus;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

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
