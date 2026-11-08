<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\BridgeConsent;
use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Bridge\BridgeKeeper;
use ArtisanStudio\StudioCli\Bridge\BridgeSetup;
use ArtisanStudio\StudioCli\Bridge\ClaudeAccount;
use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\EnvFile;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ReportBridgeRequest;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use Illuminate\Console\OutputStyle;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| Saying yes or no to the AI bridge
|--------------------------------------------------------------------------
|
| Asked once on the setup screen after a good token, only when Claude Code is
| installed and signed in, kept on this machine in a file only the user can
| read, and changeable later in Settings. Claude Code is the only choice.
|
*/

/**
 * A machine whose Claude Code is, or is not, there and signed in.
 */
function aMachineWithClaude(bool $installed, bool $signedIn): Shell
{
    return new class($installed, $signedIn) extends Shell
    {
        public function __construct(private readonly bool $installed, private readonly bool $signedIn) {}

        public function find(string $program): ?string
        {
            return $this->installed ? '/usr/local/bin/'.$program : null;
        }

        public function run(array $command, string $input, string $cwd, int $timeout): array
        {
            return ['exit' => $this->signedIn ? 0 : 1, 'out' => json_encode(['loggedIn' => $this->signedIn]), 'err' => '', 'timedOut' => false];
        }
    };
}

beforeEach(function (): void {
    $this->env = sys_get_temp_dir().'/studio-env-'.uniqid();
    file_put_contents($this->env, "ARTISAN_STUDIO_URL=\"https://studio.test\"\nARTISAN_STUDIO_TOKEN=\"test-token\"\nARTISAN_STUDIO_PROJECT=\"1\"\n");
    app()->instance(EnvFile::class, new EnvFile($this->env));
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['projects' => [['slug' => '1', 'name' => 'Artisan Studio', 'repo' => 'acme/studio']]]),
        ReportBridgeRequest::class => MockResponse::make('', 204),
    ]);

    $this->machine = function (bool $installed = true, bool $signedIn = true): void {
        app()->instance(Shell::class, aMachineWithClaude($installed, $signedIn));
        app()->forgetInstance(ClaudeAccount::class);
        app()->forgetInstance(BridgeSetup::class);
        app()->forgetInstance(BridgeKeeper::class);
    };
    $this->told = fn (): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn ($request): bool => $request->getRequest() instanceof ReportBridgeRequest)
        ->map(fn ($request): string => $request->body()->all()['state'])
        ->values()
        ->all();

    $this->buffer = new BufferedOutput;
    $this->command = app(StudioCommand::class);
    $this->command->setLaravel(app());
    $this->command->setInput(new ArrayInput([], $this->command->getDefinition()));
    $this->command->setOutput(new OutputStyle(new ArrayInput([]), $this->buffer));
    $this->studio = fn (): ScreenContainer => (fn (): ScreenContainer => $this->getScreen())->call($this->command);
    $this->asks = function (): mixed {
        app(Studio::class)->projects();

        return (fn (): mixed => $this->askAboutTheBridgeOnce())->call($this->command);
    };
    $this->press = fn (string ...$keys): mixed => collect($keys)->each(fn (string $key): mixed => (fn (): mixed => $this->handleScreenAction($this->screenActionFor($key)))->call($this->command));
});

afterEach(function (): void {
    @unlink($this->env);
    @unlink(BridgeConsent::make()->path());
});

it('keeps the developer\'s answer in a file only the user can read, for this checkout only', function (): void {
    $file = BridgeConsent::make()->path();
    $consent = new BridgeConsent($file, '/one/checkout');
    $other = new BridgeConsent($file, '/another/checkout');

    expect($consent->choice())->toBeNull();
    $consent->allow();
    $other->refuse();

    expect($consent->choice())->toBeTrue()
        ->and($consent->isAllowed())->toBeTrue()
        ->and($other->choice())->toBeFalse()
        ->and(decoct(fileperms($file) & 0777))->toBe('600')
        ->and(decoct(fileperms(dirname($file)) & 0777))->toBe('700');
});

it('offers Claude Code and nothing else, yes or no', function (): void {
    expect(array_keys(app(BridgeSetup::class)->choices()))->toBe([BridgeSetup::YES, BridgeSetup::NO]);
});

it('turns the bridge on with a yes and stores it, and tells the studio once the bridge is really running', function (): void {
    ($this->machine)();

    $said = app(BridgeSetup::class)->choose(BridgeSetup::YES);

    expect($said)->toBe(__('studio-cli::bridge.consent.allowed'))
        ->and(BridgeConsent::make()->choice())->toBeTrue()
        ->and(($this->told)())->toBe([]);
});

it('turns the bridge off with a no, tells the studio, and stores it', function (): void {
    ($this->machine)();

    $said = app(BridgeSetup::class)->choose(BridgeSetup::NO);

    expect($said)->toBe(__('studio-cli::bridge.consent.refused'))
        ->and(BridgeConsent::make()->choice())->toBeFalse()
        ->and(($this->told)())->toBe(['off']);
});

it('stores nothing and tells the studio nothing with a yes while Claude Code is not installed or not signed in', function (bool $installed, bool $signedIn): void {
    ($this->machine)($installed, $signedIn);

    $said = app(BridgeSetup::class)->choose(BridgeSetup::YES);

    expect($said)->toBe(__('studio-cli::bridge.consent.not_ready', ['reason' => app(BridgeSetup::class)->notReady()]))
        ->and(BridgeConsent::make()->choice())->toBeNull()
        ->and(($this->told)())->toBe([]);
})->with(['not installed' => [false, false], 'not signed in' => [true, false]]);

it('asks on the setup screen once, when the token is good and Claude Code is installed and signed in', function (): void {
    ($this->machine)();

    ($this->asks)();
    $opened = ($this->studio)()->settingsAreOpen();
    ($this->press)('s');
    ($this->asks)();

    expect($opened)->toBeTrue()
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse();
});

it('does not ask when the developer has already said, either way', function (bool $choice): void {
    ($this->machine)();
    $choice ? BridgeConsent::make()->allow() : BridgeConsent::make()->refuse();

    ($this->asks)();

    expect(($this->studio)()->settingsAreOpen())->toBeFalse();
})->with([true, false]);

it('says what is missing, and that scan and the report still work, when Claude Code is not installed or not signed in, and carries on without the bridge', function (bool $installed, bool $signedIn): void {
    ($this->machine)($installed, $signedIn);

    ($this->asks)();

    $flash = (fn (): ?string => $this->screenFlash)->call($this->command);

    expect(($this->studio)()->settingsAreOpen())->toBeFalse()
        ->and($flash)->toBe(__('studio-cli::bridge.consent.not_ready', ['reason' => app(BridgeSetup::class)->notReady()]))
        ->and(BridgeConsent::make()->choice())->toBeNull();
})->with(['not installed' => [false, false], 'not signed in' => [true, false]]);

it('shows CLI running and where the bridge stands beside it: on, off, or needs attention', function (string $status, string $colour): void {
    app()->instance(BridgeGate::class, new class($status) extends BridgeGate
    {
        public function __construct(private readonly string $is) {}

        public function status(): string
        {
            return $this->is;
        }
    });
    app()->forgetInstance(BridgeSetup::class);

    expect(($this->studio)()->getNotice())->toBe(__('studio-cli::bridge.cli.running').'   ⚡ '.__('studio-cli::bridge.cli.bridge.'.$status))
        ->and(($this->studio)()->getNoticeColour())->toBe($colour);
})->with([
    'on' => [BridgeGate::ON, 'green'],
    'off' => [BridgeGate::OFF, 'soft'],
    'needs attention' => [BridgeGate::ATTENTION, 'amber'],
]);

it('tells the studio where the bridge stands when it changes, and not again until it does', function (): void {
    $status = BridgeGate::OFF;
    app()->instance(BridgeGate::class, new class(function () use (&$status): string {
        return $status;
    }) extends BridgeGate
    {
        public function __construct(private readonly Closure $is) {}

        public function status(): string
        {
            return ($this->is)();
        }
    });
    $report = fn (): mixed => (fn (): mixed => $this->reportTheBridgeWhenItChanges())->call($this->command);
    app(Studio::class)->projects();

    $report();
    $report();
    $status = BridgeGate::ON;
    $report();
    $status = BridgeGate::ATTENTION;
    $report();

    expect(($this->told)())->toBe([BridgeGate::OFF, BridgeGate::ON, BridgeGate::ATTENTION]);
});

it('lets the developer change the answer later in Settings', function (): void {
    ($this->machine)();
    ($this->press)('s');

    $actions = array_map(fn (array $entry): string => $entry['action']->getName(), (fn (): array => $this->settingsActions())->call(($this->studio)()));

    expect($actions)->toContain('bridge');
});
