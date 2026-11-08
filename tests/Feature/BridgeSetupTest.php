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
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\Settings;
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

/**
 * The bridge in the state given, for the screen to read.
 */
function theBridgeIs(string $status): void
{
    app()->instance(BridgeGate::class, new class($status) extends BridgeGate
    {
        public function __construct(private readonly string $is) {}

        public function status(): string
        {
            return $this->is;
        }
    });
    app()->forgetInstance(BridgeSetup::class);
}

it('colours the bridge status by state: green when on or on a key, grey when off, amber when it needs attention', function (string $status, string $colour): void {
    theBridgeIs($status);

    expect(app(BridgeSetup::class)->footer()['colour'])->toBe($colour);
})->with([
    'on' => [BridgeGate::ON, 'green'],
    'key' => [BridgeSetup::KEY, 'green'],
    'off' => [BridgeGate::OFF, 'dim'],
    'needs attention' => [BridgeGate::ATTENTION, 'amber'],
]);

it('marks the status with one plain character that is one column wide in any monospace font, never an emoji', function (): void {
    $marker = mb_substr(app(BridgeSetup::class)->footer()['text'], 0, 1);

    expect(mb_check_encoding($marker, 'UTF-8'))->toBeTrue()
        ->and(mb_strwidth($marker))->toBe(1)
        ->and(preg_match('/\p{Extended_Pictographic}/u', $marker))->toBe(0)
        ->and(ord($marker) > 127 || ctype_print($marker))->toBeTrue()
        ->and($marker)->not->toBe("\u{FFFD}");
});

it('has no status row under the heading, so the tabs sit right under it', function (): void {
    expect(($this->studio)()->getNotice())->toBe('');
});

it('puts the bridge status at the right end of the key hints on the same line, and hides it rather than crowd the keys', function (): void {
    theBridgeIs(BridgeGate::ATTENTION);

    $footer = fn (int $width): string => collect(($this->studio)()->lines($width, 30))->slice(-2, 1)->first();
    $plain = fn (string $line): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $line);
    $wide = $footer(120);
    $shown = collect(range(ScreenContainer::MIN_WIDTH, 120))->mapWithKeys(fn (int $width): array => [$width => str_contains($footer($width), Canvas::ACTION.'bridge')]);

    expect(Canvas::linkAt($wide, mb_strwidth(rtrim($plain($wide)))))->toBe(Canvas::ACTION.'bridge')
        ->and(Canvas::linkAt($wide, 4))->toBeNull()
        ->and($shown[ScreenContainer::MIN_WIDTH])->toBeFalse()
        ->and($shown[120])->toBeTrue()
        ->and($shown->keys()->every(fn (int $width): bool => mb_strwidth($plain($footer($width))) <= $width))->toBeTrue();
});

it('opens the settings on the bridge row when the status is clicked, from every screen that shows the key hints', function (string $from): void {
    match ($from) {
        'a tab' => null,
        'another tab' => ($this->press)('2'),
        'the settings' => ($this->press)('s'),
        'the help' => ($this->press)('?'),
        'a panel' => ($this->studio)()->openPanel([Settings::make('Panel')->actions([Action::make('go')->label('Go')->action(fn (): ?string => null)])]),
    };

    (fn (): int => $this->screenWidthNow = 120)->call($this->command);
    (fn (): string => $this->screenFrame($this->getScreen(), 30, 121))->call($this->command);
    $lines = (fn (): array => $this->screenLines)->call($this->command);
    $row = count($lines) - 1;
    $plain = (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $lines[$row - 1]);

    ($this->press)("\e[<0;".mb_strwidth(rtrim($plain)).";{$row}M");

    $screen = ($this->studio)();

    expect(str_contains($lines[$row - 1], Canvas::ACTION.'bridge'))->toBeTrue()
        ->and($screen->settingsAreOpen())->toBeTrue()
        ->and($screen->showsPanel())->toBeFalse()
        ->and((fn (): string => $this->selectedSetting()['action']->getName())->call($screen))->toBe('bridge');
})->with(['a tab', 'another tab', 'the settings', 'the help', 'a panel']);

it('takes no key for the bridge: s still opens the settings as before', function (): void {
    ($this->press)('b');

    expect(($this->studio)()->settingsAreOpen())->toBeFalse();

    ($this->press)('s');

    expect(($this->studio)()->settingsAreOpen())->toBeTrue();
});

it('says what each bridge state means and what to do, in the bridge section of the settings', function (string $status, ?bool $consent, string $says): void {
    $consent === null || BridgeConsent::make()->{$consent ? 'allow' : 'refuse'}();
    theBridgeIs($status);

    expect(app(BridgeSetup::class)->explain())->toBe(__("studio-cli::bridge.cli.explain.{$says}"));
})->with([
    'on' => [BridgeGate::ON, true, 'on'],
    'on a key' => [BridgeSetup::KEY, null, 'key'],
    'off, consent not given' => [BridgeGate::OFF, false, 'off'],
    'off, not asked yet' => [BridgeGate::OFF, null, 'off'],
    'off, allowed but stopped' => [BridgeGate::OFF, true, 'stopped'],
    'needs attention' => [BridgeGate::ATTENTION, true, 'attention'],
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
