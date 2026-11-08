<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\BridgeBeat;
use ArtisanStudio\StudioCli\Bridge\BridgeConsent;
use ArtisanStudio\StudioCli\Bridge\BridgeDetection;
use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Bridge\BridgeKey;
use ArtisanStudio\StudioCli\Bridge\BridgeNotConnected;
use ArtisanStudio\StudioCli\Bridge\BridgeSetup;
use ArtisanStudio\StudioCli\Bridge\ClaudeAccount;
use ArtisanStudio\StudioCli\Bridge\Runners;
use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSnapshotRequest;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| Where the AI connection stands
|--------------------------------------------------------------------------
|
| One word for the setup screen and the Settings bridge section: on, a key in
| use, signed out, not installed, or consent not given. A runner or a key
| connects; with neither, the screen shows the two ways forward. Only the
| runners the config enables count.
|
*/

/**
 * A machine with each program given either not installed, installed but signed
 * out, or ready.
 *
 * @param  array<string, string>  $programs  program => 'missing' | 'signed-out' | 'ready'
 */
function aMachineWhere(array $programs): Shell
{
    return new class($programs) extends Shell
    {
        /** @param array<string, string> $programs */
        public function __construct(private readonly array $programs) {}

        public function find(string $program): ?string
        {
            return ($this->programs[$program] ?? 'missing') === 'missing' ? null : '/usr/local/bin/'.$program;
        }

        public function run(array $command, string $input, string $cwd, int $timeout): array
        {
            $ready = ($this->programs[$command[0]] ?? 'missing') === 'ready';

            return $command[0] === 'claude'
                ? ['exit' => $ready ? 0 : 1, 'out' => json_encode(['loggedIn' => $ready]), 'err' => '', 'timedOut' => false]
                : ['exit' => $ready ? 0 : 1, 'out' => $ready ? 'Logged in' : 'Not logged in', 'err' => '', 'timedOut' => false];
        }
    };
}

/**
 * The detection on that machine, with the developer's answer and whether the
 * studio holds a saved key.
 */
function detectionOn(Shell $shell, ?bool $consent = null, bool $key = false, bool $running = false): BridgeDetection
{
    $file = config('studio-cli.bridge.consent_file');
    @unlink($file);
    $consent === null || BridgeConsent::make()->{$consent ? 'allow' : 'refuse'}();
    BridgeKey::remember($key);
    $running ? BridgeBeat::mark() : BridgeBeat::unmark();

    app()->instance(Shell::class, $shell);
    app()->forgetInstance(ClaudeAccount::class);
    app()->forgetInstance(Runners::class);
    app()->forgetInstance(BridgeDetection::class);
    app()->forgetInstance(BridgeSetup::class);

    return app(BridgeDetection::class);
}

beforeEach(function (): void {
    @unlink(BridgeKey::path());
});

afterEach(function (): void {
    @unlink(BridgeKey::path());
    @unlink((string) config('studio-cli.bridge.consent_file'));
    BridgeBeat::unmark();
});

it('produces the right state for each situation', function (array $programs, ?bool $consent, bool $key, bool $running, string $state): void {
    expect(detectionOn(aMachineWhere($programs), $consent, $key, $running)->state())->toBe($state);
})->with([
    'on: said yes, signed in, running' => [['claude' => 'ready'], true, false, true, BridgeDetection::ON],
    'said yes, signed in, not running yet' => [['claude' => 'ready'], true, false, false, BridgeDetection::STOPPED],
    'key in use: a saved key and no runner' => [[], null, true, false, BridgeDetection::KEY],
    'key in use: a saved key and a runner signed out' => [['claude' => 'signed-out'], null, true, false, BridgeDetection::KEY],
    'key in use: a saved key, a runner and a no' => [['claude' => 'ready'], false, true, false, BridgeDetection::KEY],
    'signed out' => [['claude' => 'signed-out'], null, false, false, BridgeDetection::SIGNED_OUT],
    'not installed' => [[], null, false, false, BridgeDetection::NOT_INSTALLED],
    'consent not given: never asked' => [['claude' => 'ready'], null, false, false, BridgeDetection::CONSENT_NOT_GIVEN],
    'consent not given: a no' => [['claude' => 'ready'], false, false, false, BridgeDetection::CONSENT_NOT_GIVEN],
]);

it('says not installed when the claude program is not on the PATH, and signed out when it is there but signed out', function (): void {
    $bin = sys_get_temp_dir().'/studio-path-'.bin2hex(random_bytes(4));
    mkdir($bin);
    $path = getenv('PATH');
    putenv('PATH='.$bin);

    try {
        $hidden = detectionOn(new Shell, null)->state();
        file_put_contents($bin.'/claude', "#!/bin/sh\necho '{\"loggedIn\":false}'\nexit 1\n");
        chmod($bin.'/claude', 0755);
        $signedOut = detectionOn(new Shell, null)->state();
    } finally {
        putenv('PATH='.$path);
        @unlink($bin.'/claude');
        @rmdir($bin);
    }

    expect($hidden)->toBe(BridgeDetection::NOT_INSTALLED)
        ->and($signedOut)->toBe(BridgeDetection::SIGNED_OUT);
});

it('unblocks writes on a saved key with no runner, and blocks them with neither', function (): void {
    $gate = fn (bool $key): BridgeGate => new BridgeGate(detectionOn(aMachineWhere([]), null, $key));

    expect($gate(true)->status())->toBe(BridgeGate::KEY)
        ->and($gate(true)->isConnected())->toBeTrue()
        ->and(fn () => $gate(true)->require())->not->toThrow(BridgeNotConnected::class)
        ->and($gate(false)->status())->toBe(BridgeGate::ATTENTION)
        ->and($gate(false)->isConnected())->toBeFalse()
        ->and(fn () => $gate(false)->require())->toThrow(BridgeNotConnected::class);
});

it('shows the block screen with both ways forward when neither a runner nor a key is there, and not otherwise', function (array $programs, bool $key, bool $blocked): void {
    detectionOn(aMachineWhere($programs), null, $key);
    $setup = app(BridgeSetup::class);
    $shown = fn (): int => collect($setup->blockTexts())->filter(fn ($text): bool => $text->render(new Canvas, 80, null) !== [])->count();

    expect($setup->isBlocked())->toBe($blocked)
        ->and($shown() > 0)->toBe($blocked);
})->with([
    'not installed and no key' => [[], false, true],
    'signed out and no key' => [['claude' => 'signed-out'], false, true],
    'a saved key' => [[], true, false],
    'a runner ready' => [['claude' => 'ready'], false, false],
]);

it('names the key route and the subscription route in the block screen, and never asks for or keeps the key', function (): void {
    config(['studio-cli.url' => 'https://studio.test']);
    detectionOn(aMachineWhere([]), null, false);
    $lines = (fn (): array => $this->blockLines())->call(app(BridgeSetup::class));
    $text = implode("\n", array_column($lines, 'text'));

    expect($text)->toContain(__('studio-cli::bridge.block.recommended', ['runners' => __('studio-cli::bridge.runner.names.claude')]))
        ->and($text)->toContain(__('studio-cli::bridge.block.key_title'))
        ->and($text)->toContain('https://studio.test/settings/claude')
        ->and($text)->toContain(__('studio-cli::bridge.block.unsupported'));
});

it('remembers that the studio holds a saved key from its snapshot, and forgets it when the studio stops saying so', function (): void {
    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make(['bridge' => ['state' => null, 'held' => true, 'key' => true]])]);

    app(Studio::class)->snapshot();

    expect(BridgeKey::isSaved())->toBeTrue();

    Saloon::fake([ShowSnapshotRequest::class => MockResponse::make(['bridge' => ['state' => null, 'held' => true, 'key' => false]])]);

    app(Studio::class)->snapshot();

    expect(BridgeKey::isSaved())->toBeFalse();
});

it('notices a key saved or Claude Code installed on the next refresh, without a restart', function (): void {
    $machine = aMachineWhere([]);
    $detection = detectionOn($machine, null, false);

    expect($detection->state())->toBe(BridgeDetection::NOT_INSTALLED);

    BridgeKey::remember(true);

    expect($detection->state())->toBe(BridgeDetection::KEY);

    BridgeKey::remember(false);
    detectionOn(aMachineWhere(['claude' => 'ready']), null, false);

    expect(app(BridgeDetection::class)->state())->toBe(BridgeDetection::CONSENT_NOT_GIVEN);
});

it('counts only the runners the config enables, and adds Codex automatically when its flag is switched on', function (): void {
    config(['studio-cli.bridge.codex' => false]);
    $offState = detectionOn(aMachineWhere(['codex' => 'ready']), null, false)->state();
    $offRunners = app(Runners::class)->enabled();
    $offComing = app(Runners::class)->isComing(Runners::CODEX);
    $offLines = array_column((fn (): array => $this->blockLines())->call(app(BridgeSetup::class)), 'text');

    config(['studio-cli.bridge.codex' => true]);
    $onState = detectionOn(aMachineWhere(['codex' => 'ready']), null, false)->state();
    $onRunners = app(Runners::class)->enabled();
    $onComing = app(Runners::class)->isComing(Runners::CODEX);

    config(['studio-cli.bridge.codex' => true]);
    detectionOn(aMachineWhere(['claude' => 'signed-out']), null, false);
    $onLines = array_column((fn (): array => $this->blockLines())->call(app(BridgeSetup::class)), 'text');
    $coming = __('studio-cli::bridge.block.coming', ['runner' => __('studio-cli::bridge.runner.names.codex')]);

    expect($offRunners)->toBe([Runners::CLAUDE])
        ->and($offState)->toBe(BridgeDetection::NOT_INSTALLED)
        ->and($offComing)->toBeTrue()
        ->and($offLines)->toContain($coming)
        ->and($onRunners)->toBe([Runners::CLAUDE, Runners::CODEX])
        ->and($onState)->toBe(BridgeDetection::CONSENT_NOT_GIVEN)
        ->and($onComing)->toBeFalse()
        ->and($onLines)->not->toContain($coming)
        ->and(count($onLines))->toBeGreaterThan(count($offLines) - 1)
        ->and(collect($onLines)->contains(fn (string $line): bool => str_contains($line, __('studio-cli::bridge.runner.names.codex'))))->toBeTrue();
});
