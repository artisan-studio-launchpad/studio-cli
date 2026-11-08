<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\Console\BridgeCommand;
use ArtisanStudio\StudioCli\Saloon\Requests\ClaimAiTaskRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitAiTaskRequest;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Support\Facades\File;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * Stands in for the machine: says which programs exist and what they print,
 * and remembers every command it was asked to run.
 */
function aMachineWith(array $programs, array $answers = []): Shell
{
    return new class($programs, $answers) extends Shell
    {
        /** @var list<array{command: list<string>, input: string, cwd: string, timeout: int}> */
        public array $ran = [];

        public function __construct(private readonly array $programs, private readonly array $answers) {}

        public function find(string $program): ?string
        {
            return in_array($program, $this->programs, true) ? '/usr/local/bin/'.$program : null;
        }

        public function run(array $command, string $input, string $cwd, int $timeout): array
        {
            $this->ran[] = compact('command', 'input', 'cwd', 'timeout');
            $key = $command[0].' '.($command[1] ?? '');

            return ($this->answers[$key] ?? $this->answers[$command[0]] ?? ['exit' => 1, 'out' => '', 'err' => 'unplanned', 'timedOut' => false]) + ['timedOut' => false];
        }
    };
}

function claudeAnswers(array $print): array
{
    return [
        'claude auth' => ['exit' => 0, 'out' => json_encode(['loggedIn' => true, 'authMethod' => 'claude.ai']), 'err' => ''],
        'claude --version' => ['exit' => 0, 'out' => "2.1.308 (Claude Code)\n", 'err' => ''],
        'claude -p' => ['exit' => $print['exit'] ?? 0, 'out' => json_encode($print['json'] ?? []), 'err' => '', 'timedOut' => $print['timedOut'] ?? false],
    ];
}

function aTask(array $payload = [], string $kind = 'text'): MockResponse
{
    return MockResponse::make(['task' => [
        'id' => '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000',
        'kind' => $kind,
        'payload' => $payload + ['instructions' => 'You answer in one word.', 'prompt' => 'Reply with exactly: pong', 'model' => 'haiku', 'timeout' => 120],
        'lease_seconds' => 360,
    ]]);
}

beforeEach(function (): void {
    $this->logs = storage_path('studio/bridge');
    File::deleteDirectory($this->logs);

    $this->reported = fn (): ?PendingRequest => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): PendingRequest => $response->getPendingRequest())
        ->first(fn (PendingRequest $request): bool => $request->getRequest() instanceof SubmitAiTaskRequest);

    $this->pong = ['json' => [
        'type' => 'result', 'is_error' => false, 'result' => 'pong', 'duration_ms' => 2264, 'duration_api_ms' => 1603, 'total_cost_usd' => 0.0101,
        'usage' => ['input_tokens' => 2, 'output_tokens' => 4, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
    ]];
});

afterEach(fn () => File::deleteDirectory($this->logs));

it('claims a task, runs it through the Claude on this machine and sends back the answer with what it cost', function (): void {
    $machine = aMachineWith(['claude'], claudeAnswers($this->pong));
    app()->instance(Shell::class, $machine);
    Saloon::fake([ClaimAiTaskRequest::class => aTask(), SubmitAiTaskRequest::class => MockResponse::make('', 204)]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--once' => true])->expectsOutputToContain('Answered ffff0000')->assertSuccessful();

    $report = ($this->reported)()->body()->all();

    expect(($this->reported)()->getUrl())->toBe('https://studio.test/api/v1/projects/1/ai/tasks/0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000')
        ->and($report['status'])->toBe('done')
        ->and($report['text'])->toBe('pong')
        ->and($report['usage'])->toMatchArray(['input_tokens' => 2, 'output_tokens' => 4, 'cache_write_tokens' => 0, 'duration_ms' => 2264, 'api_ms' => 1603, 'runner' => 'claude', 'version' => '2.1.308 (Claude Code)']);
});

it('runs Claude with no tools, no settings, no saved session, its own system prompt and a folder of its own', function (): void {
    $machine = aMachineWith(['claude'], claudeAnswers($this->pong));
    app()->instance(Shell::class, $machine);
    Saloon::fake([ClaimAiTaskRequest::class => aTask(), SubmitAiTaskRequest::class => MockResponse::make('', 204)]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--once' => true])->assertSuccessful();

    $ran = collect($machine->ran)->first(fn (array $call): bool => ($call['command'][1] ?? null) === '-p');
    $command = $ran['command'];

    expect($command)->toContain('--output-format', 'json', '--model', 'haiku', '--no-session-persistence', '--system-prompt', 'You answer in one word.')
        ->and($command[array_search('--tools', $command, true) + 1])->toBe('')
        ->and($command[array_search('--setting-sources', $command, true) + 1])->toBe('')
        ->and($command[array_search('--max-turns', $command, true) + 1])->toBe('1')
        ->and($ran['input'])->toBe('Reply with exactly: pong')
        ->and($ran['cwd'])->toBe(sys_get_temp_dir().'/studio-bridge')
        ->and($ran['cwd'])->not->toBe(base_path());
});

it('writes every task it ran to a log the developer can read, prompt included', function (): void {
    app()->instance(Shell::class, aMachineWith(['claude'], claudeAnswers($this->pong)));
    Saloon::fake([ClaimAiTaskRequest::class => aTask(), SubmitAiTaskRequest::class => MockResponse::make('', 204)]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--once' => true])->assertSuccessful();

    $line = json_decode(trim((string) File::get(collect(File::files($this->logs))->first()->getPathname())), true);

    expect($line)->toMatchArray(['task' => '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000', 'runner' => 'claude', 'model' => 'haiku', 'ok' => true, 'prompt' => 'Reply with exactly: pong', 'instructions' => 'You answer in one word.']);
});

it('reports Claude not being signed in as a plain failure, not a hang', function (): void {
    app()->instance(Shell::class, aMachineWith(['claude'], claudeAnswers(['exit' => 1, 'json' => ['is_error' => true, 'result' => 'Not logged in · Please run /login', 'usage' => []]])));
    Saloon::fake([ClaimAiTaskRequest::class => aTask(), SubmitAiTaskRequest::class => MockResponse::make('', 204)]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--once' => true])->assertFailed();

    expect(($this->reported)()->body()->all())->toMatchArray(['status' => 'failed', 'error' => 'Claude Code is not signed in. Run claude, sign in, then try again.']);
});

it('stops a task that runs past its time limit and says so', function (): void {
    app()->instance(Shell::class, aMachineWith(['claude'], claudeAnswers(['timedOut' => true])));
    Saloon::fake([ClaimAiTaskRequest::class => aTask(['timeout' => 30]), SubmitAiTaskRequest::class => MockResponse::make('', 204)]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--once' => true])->assertFailed();

    expect(($this->reported)()->body()->all())->toMatchArray(['status' => 'failed', 'error' => 'Claude Code took longer than 30 seconds, so the bridge stopped it.']);
});

it('never lets a task ask for more time than the terminal allows', function (): void {
    $machine = aMachineWith(['claude'], claudeAnswers($this->pong));
    app()->instance(Shell::class, $machine);
    Saloon::fake([ClaimAiTaskRequest::class => aTask(['timeout' => 86400]), SubmitAiTaskRequest::class => MockResponse::make('', 204)]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--once' => true])->assertSuccessful();

    $ran = collect($machine->ran)->first(fn (array $call): bool => ($call['command'][1] ?? null) === '-p');

    expect($ran['timeout'])->toBe(600);
});

it('refuses a task of any kind but plain text, or one that names a strange model, without running anything', function (string $kind, array $payload): void {
    $machine = aMachineWith(['claude'], claudeAnswers($this->pong));
    app()->instance(Shell::class, $machine);
    Saloon::fake([ClaimAiTaskRequest::class => aTask($payload, $kind), SubmitAiTaskRequest::class => MockResponse::make('', 204)]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--once' => true])->assertFailed();

    expect(collect($machine->ran)->contains(fn (array $call): bool => ($call['command'][1] ?? null) === '-p'))->toBeFalse()
        ->and(($this->reported)()->body()->all()['status'])->toBe('failed');
})->with([
    'a shell task' => ['shell', []],
    'a model that is really an option' => ['text', ['model' => '--dangerously-skip-permissions']],
    'an empty prompt' => ['text', ['prompt' => '   ']],
]);

it('does not start when Claude is not installed or not signed in, and says what to do', function (array $programs, array $answers, string $says): void {
    app()->instance(Shell::class, aMachineWith($programs, $answers));
    Saloon::fake([ClaimAiTaskRequest::class => aTask()]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--runner' => 'claude'])->expectsOutputToContain($says)->assertFailed();

    Saloon::assertNothingSent();
})->with([
    'not installed' => [[], [], 'Claude Code is not installed'],
    'not signed in' => [['claude'], ['claude auth' => ['exit' => 1, 'out' => '{"loggedIn":false}', 'err' => '']], 'Claude Code is not signed in'],
]);

it('stops with --once when no task comes in the time given, and counts as listening only because it asked', function (): void {
    app()->instance(Shell::class, aMachineWith(['claude'], claudeAnswers($this->pong)));
    Saloon::fake([ClaimAiTaskRequest::class => MockResponse::make('', 204)]);

    $this->artisan(BridgeCommand::SIGNATURE, ['--once' => true, '--wait' => 0])->expectsOutputToContain('No task came')->assertSuccessful();

    Saloon::assertSent(ClaimAiTaskRequest::class);
});

it('reads a claim of nothing as no task', function (): void {
    Saloon::fake([ClaimAiTaskRequest::class => MockResponse::make('', 204)]);

    expect(app(Studio::class)->claimAiTask())->toBeNull();
});
