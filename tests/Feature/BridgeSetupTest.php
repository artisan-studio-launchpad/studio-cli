<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\BridgeBeat;
use ArtisanStudio\StudioCli\Bridge\BridgeConsent;
use ArtisanStudio\StudioCli\Bridge\BridgeDetection;
use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Bridge\BridgeKeeper;
use ArtisanStudio\StudioCli\Bridge\BridgeKey;
use ArtisanStudio\StudioCli\Bridge\BridgeSetup;
use ArtisanStudio\StudioCli\Bridge\ClaudeAccount;
use ArtisanStudio\StudioCli\Bridge\Runners;
use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\EnvFile;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ReportBridgeRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowBridgeRequest;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Terminal\Settings;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Lang;
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
        app()->forgetInstance(Runners::class);
        app()->forgetInstance(BridgeDetection::class);
        app()->forgetInstance(BridgeGate::class);
        app()->forgetInstance(BridgeSetup::class);
        @unlink(BridgeKey::path());
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

it('shows the block screen when no AI is there to connect, asks nothing, and carries on without the bridge', function (bool $installed, bool $signedIn): void {
    ($this->machine)($installed, $signedIn);

    ($this->asks)();

    expect(($this->studio)()->showsPanel())->toBeTrue()
        ->and(app(BridgeSetup::class)->isBlocked())->toBeTrue()
        ->and(BridgeConsent::make()->choice())->toBeNull();
})->with(['not installed' => [false, false], 'not signed in' => [true, false]]);

/**
 * The bridge in the state given, for the screen to read.
 */
function theBridgeIs(string $state): void
{
    $detection = new class($state) extends BridgeDetection
    {
        public function __construct(private readonly string $is) {}

        public function state(): string
        {
            return $this->is;
        }
    };

    app()->instance(BridgeDetection::class, $detection);
    app()->instance(BridgeGate::class, new BridgeGate($detection));
    app()->forgetInstance(BridgeSetup::class);
}

it('colours the bridge status by state: green when on or on a key, grey when off, amber when it needs attention', function (string $state, string $colour): void {
    theBridgeIs($state);

    expect(app(BridgeSetup::class)->footer()['colour'])->toBe($colour);
})->with([
    'on' => [BridgeDetection::ON, 'green'],
    'key' => [BridgeDetection::KEY, 'green'],
    'stopped' => [BridgeDetection::STOPPED, 'dim'],
    'consent not given' => [BridgeDetection::CONSENT_NOT_GIVEN, 'dim'],
    'signed out' => [BridgeDetection::SIGNED_OUT, 'amber'],
    'not installed' => [BridgeDetection::NOT_INSTALLED, 'amber'],
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
    theBridgeIs(BridgeDetection::NOT_INSTALLED);

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
    BridgeConsent::make()->refuse();

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

it('says what each bridge state means and what to do, in the bridge section of the settings', function (string $state): void {
    theBridgeIs($state);

    expect(app(BridgeSetup::class)->explain())->toBe(__("studio-cli::bridge.cli.explain.{$state}"))
        ->and(Lang::has("studio-cli::bridge.cli.explain.{$state}"))->toBeTrue();
})->with([
    BridgeDetection::ON,
    BridgeDetection::KEY,
    BridgeDetection::CONSENT_NOT_GIVEN,
    BridgeDetection::STOPPED,
    BridgeDetection::SIGNED_OUT,
    BridgeDetection::NOT_INSTALLED,
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

it('says the no was a no, so a question asked in the app is answered there instead of timing out', function (): void {
    ($this->machine)();

    app(BridgeSetup::class)->choose(BridgeSetup::NO);

    $bodies = collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn ($request): bool => $request->getRequest() instanceof ReportBridgeRequest)
        ->map(fn ($request): array => $request->body()->all());

    expect($bodies->all())->toBe([['state' => BridgeGate::OFF, 'runner' => 'claude', 'answer' => 'declined']]);
});

it('reads the question the app asked from the snapshot', function (): void {
    expect(DashboardSnapshot::fromApi(['bridge' => ['state' => 'off', 'held' => true, 'key' => false, 'asked' => 'question-1']])->bridgeAsked)->toBe('question-1')
        ->and(DashboardSnapshot::fromApi(['bridge' => ['state' => 'off', 'held' => true, 'key' => false]])->bridgeAsked)->toBeNull();
});

/**
 * The studio's answer to the small question the terminal asks every few
 * seconds: which question, if any, the developer asked in the app. Change
 * `asked` on what it returns to ask, or to take the question back.
 */
function theAppAsks(?string $question): stdClass
{
    $app = new stdClass;
    $app->asked = $question;

    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['projects' => [['slug' => '1', 'name' => 'Artisan Studio', 'repo' => 'acme/studio']]]),
        ReportBridgeRequest::class => MockResponse::make('', 204),
        ShowBridgeRequest::class => fn (): MockResponse => MockResponse::make(['asked' => $app->asked]),
    ]);

    return $app;
}

beforeEach(function (): void {
    $this->tick = function (): void {
        (fn () => $this->bridgePolledAt = 0)->call($this->command);
        (fn () => $this->showTheBridgeWhenAskedInTheApp())->call($this->command);
        (fn () => $this->backToInsightsOnceTheBridgeQuestionIsDone())->call($this->command);
    };
});

it('opens the bridge question the moment the app asks, once for each question, and never asks by itself again after a no', function (): void {
    ($this->machine)();
    BridgeConsent::make()->refuse();
    $app = theAppAsks('question-1');
    app(Studio::class)->projects();
    (fn () => $this->repository = 'acme/studio')->call($this->command);

    ($this->tick)();

    $names = array_map(fn (array $entry): string => $entry['action']->getName(), (fn (): array => $this->settingsActions())->call(($this->studio)()));

    expect(($this->studio)()->settingsAreOpen())->toBeTrue()
        ->and(($this->studio)()->showsPanel())->toBeTrue()
        ->and($names)->toBe(['allow', 'keep-off']);

    ($this->studio)()->backInSettings();
    (fn () => $this->bridgeQuestionOpen = false)->call($this->command);
    ($this->tick)();

    expect(($this->studio)()->settingsAreOpen())->toBeFalse();

    $app->asked = 'question-2';
    ($this->tick)();

    expect(($this->studio)()->settingsAreOpen())->toBeTrue();
});

it('asks the studio every couple of seconds, not on every pass of the screen', function (): void {
    ($this->machine)();
    BridgeConsent::make()->refuse();
    theAppAsks('question-1');
    app(Studio::class)->projects();
    (fn () => $this->repository = 'acme/studio')->call($this->command);
    $asks = fn (): int => collect(Saloon::mockClient()->getRecordedResponses())
        ->filter(fn ($response): bool => $response->getPendingRequest()->getRequest() instanceof ShowBridgeRequest)
        ->count();

    ($this->tick)();
    (fn () => $this->showTheBridgeWhenAskedInTheApp())->call($this->command);
    (fn () => $this->showTheBridgeWhenAskedInTheApp())->call($this->command);

    expect($asks())->toBe(1);
});

it('drops the question and goes back to Insights when the developer presses Not now in the app', function (): void {
    ($this->machine)();
    BridgeConsent::make()->refuse();
    $app = theAppAsks('question-1');
    app(Studio::class)->projects();
    (fn () => $this->repository = 'acme/studio')->call($this->command);

    ($this->tick)();
    ($this->tick)();

    expect(($this->studio)()->showsPanel())->toBeTrue()
        ->and(app(ScreenRequests::class)->take())->toBeNull();

    $app->asked = null;
    ($this->tick)();

    expect(($this->studio)()->settingsAreOpen())->toBeFalse()
        ->and(app(ScreenRequests::class)->take()['tab'] ?? null)->toBe('insights')
        ->and(BridgeConsent::make()->choice())->toBeFalse();
});

it('keeps the question open when the studio cannot be reached for a moment', function (): void {
    ($this->machine)();
    BridgeConsent::make()->refuse();
    theAppAsks('question-1');
    app(Studio::class)->projects();
    (fn () => $this->repository = 'acme/studio')->call($this->command);

    ($this->tick)();

    Saloon::fake([ShowBridgeRequest::class => MockResponse::make('', 500)]);
    ($this->tick)();

    expect(($this->studio)()->showsPanel())->toBeTrue();
});

it('shows what is being allowed and both answers together, even on a short screen', function (): void {
    ($this->machine)();
    $screen = ($this->studio)();
    $screen->openPanel([app(BridgeSetup::class)->askPanel()]);

    $plain = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $screen->render(100, 16, 'insights'));

    expect($plain)->toContain(__('studio-cli::bridge.consent.question'))
        ->toContain(__('studio-cli::bridge.consent.yes'))
        ->toContain(__('studio-cli::bridge.consent.no'));
});

it('goes back to the Insights tab once the question the app asked is answered, or put away with Esc', function (string $how): void {
    ($this->machine)();
    BridgeConsent::make()->refuse();
    theAppAsks('question-1');
    app(Studio::class)->projects();
    (fn () => $this->repository = 'acme/studio')->call($this->command);

    ($this->tick)();

    expect(($this->studio)()->showsPanel())->toBeTrue()
        ->and(app(ScreenRequests::class)->take())->toBeNull();

    $how === 'esc' || app(BridgeSetup::class)->choose(BridgeSetup::NO);
    ($this->studio)()->closeSettings();
    ($this->tick)();

    expect(app(ScreenRequests::class)->take()['tab'] ?? null)->toBe('insights')
        ->and(app(ScreenRequests::class)->take())->toBeNull();
})->with(['esc' => 'esc', 'a no' => 'no']);

it('lets the developer change the answer later in Settings', function (): void {
    ($this->machine)();
    BridgeConsent::make()->refuse();
    ($this->press)('s');

    $actions = array_map(fn (array $entry): string => $entry['action']->getName(), (fn (): array => $this->settingsActions())->call(($this->studio)()));

    expect($actions)->toContain('bridge');
});

it('asks again when the CLI is linked with a new token, because an answer belongs to the link it was given on', function (): void {
    $file = sys_get_temp_dir().'/studio-link-'.bin2hex(random_bytes(6)).'.json';

    config(['studio-cli.token' => 'first-token']);
    (new BridgeConsent($file, '/checkout'))->allow();

    expect((new BridgeConsent($file, '/checkout'))->choice())->toBeTrue();

    config(['studio-cli.token' => 'a-new-token-after-reset']);

    expect((new BridgeConsent($file, '/checkout'))->choice())->toBeNull();

    config(['studio-cli.token' => 'first-token']);

    expect((new BridgeConsent($file, '/checkout'))->choice())->toBeTrue()
        ->and((string) file_get_contents($file))->not->toContain('first-token');

    @unlink($file);
});

it('treats an answer recorded before it was tied to a link as not given, so nobody is answered for', function (): void {
    $file = sys_get_temp_dir().'/studio-old-'.bin2hex(random_bytes(6)).'.json';

    file_put_contents($file, json_encode([hash('xxh128', '/checkout') => ['allowed' => true, 'at' => now()->toIso8601String(), 'checkout' => '/checkout']]));

    expect((new BridgeConsent($file, '/checkout'))->choice())->toBeNull();

    @unlink($file);
});

it('forgets the bridge on this machine when the studio turns the linked token away, as it does after Reset Scan', function (): void {
    BridgeConsent::make()->allow();
    BridgeKey::remember(true);

    expect(BridgeConsent::make()->choice())->toBeTrue()->and(BridgeKey::isSaved())->toBeTrue();

    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => app(Studio::class)->projects())->toThrow(RuntimeException::class);

    expect(BridgeConsent::make()->choice())->toBeNull()
        ->and(BridgeKey::isSaved())->toBeFalse();
});

it('keeps a yes given under the new token when a request still carrying the previous token is turned away', function (): void {
    config(['studio-cli.token' => 'the-previous-token']);

    Saloon::fake([ListProjectsRequest::class => function () {
        config(['studio-cli.token' => 'the-new-token']);
        BridgeConsent::make()->allow();
        BridgeKey::remember(true);

        return MockResponse::make(['message' => 'Unauthenticated.'], 401);
    }]);

    expect(fn () => app(Studio::class)->projects())->toThrow(RuntimeException::class);

    expect(BridgeConsent::make()->choice())->toBeTrue()
        ->and(BridgeKey::isSaved())->toBeTrue();
});

it('still forgets a yes that was given under the very token the studio turns away', function (): void {
    config(['studio-cli.token' => 'the-linked-token']);
    BridgeConsent::make()->allow();

    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => app(Studio::class)->projects())->toThrow(RuntimeException::class);

    expect(BridgeConsent::make()->choice())->toBeNull();
});

it('leaves the bridge alone when a token only being tried in Settings is the one turned away', function (): void {
    BridgeConsent::make()->allow();

    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => app(Studio::class)->withToken('a-token-being-tried')->projects())->toThrow(RuntimeException::class);

    expect(BridgeConsent::make()->choice())->toBeTrue();
});

it('tells the studio while the bridge question is open, and again once it has been answered, so it waits for the answer', function (): void {
    ($this->machine)();
    app(Studio::class)->projects();
    (fn () => $this->askedAboutTheBridge = true)->call($this->command);

    $report = fn (): mixed => (fn (): mixed => $this->reportTheBridgeWhenItChanges())->call($this->command);
    $answers = fn (): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn ($request): bool => $request->getRequest() instanceof ReportBridgeRequest)
        ->map(fn ($request): ?string => $request->body()->all()['answer'] ?? null)
        ->values()
        ->all();

    $report();
    $report();

    expect($answers())->toBe(['asking']);

    app(BridgeSetup::class)->choose(BridgeSetup::NO);
    $report();

    expect($answers())->toBe(['asking', 'declined', null]);
});

it('does not say it is asking when nothing is asked: already answered, or no AI tool to ask about', function (): void {
    ($this->machine)();
    app(Studio::class)->projects();
    (fn () => $this->askedAboutTheBridge = true)->call($this->command);
    BridgeConsent::make()->allow();

    $report = fn (): mixed => (fn (): mixed => $this->reportTheBridgeWhenItChanges())->call($this->command);
    $answers = fn (): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn ($request): bool => $request->getRequest() instanceof ReportBridgeRequest)
        ->map(fn ($request): ?string => $request->body()->all()['answer'] ?? null)
        ->values()
        ->all();

    $report();

    expect($answers())->not->toContain('asking');

    BridgeConsent::make()->forget();
    ($this->machine)(false, false);
    $report();

    expect($answers())->not->toContain('asking');
});

describe('the bridge question at the top of the settings', function (): void {
    beforeEach(function (): void {
        $this->plain = fn (string $line): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $line);
        $this->rows = fn (int $width = 80, int $height = 24): array => array_map($this->plain, ($this->studio)()->lines($width, $height));
        $this->at = fn (string $needle, array $rows): int|false => collect($rows)->search(fn (string $row): bool => str_contains($row, $needle));
        $this->open = function (): void {
            ($this->machine)();
            app(Studio::class)->projects();
            ($this->press)('s');
        };
    });

    it('shows the question first, the two answers right under it, and the menu after, all inside a 24 row terminal', function (): void {
        ($this->open)();

        $rows = ($this->rows)();
        $question = ($this->at)(mb_substr(__('studio-cli::bridge.consent.question'), 0, 30), $rows);
        $yes = ($this->at)(__('studio-cli::bridge.consent.yes'), $rows);
        $no = ($this->at)(__('studio-cli::bridge.consent.no'), $rows);
        $menu = ($this->at)('Link with a new token', $rows);
        $token = ($this->at)('Token', $rows);

        expect(count($rows))->toBeLessThanOrEqual(24)
            ->and($question)->not->toBeFalse()
            ->and($yes)->toBeGreaterThan($question)
            ->and($no)->toBe($yes + 1)
            ->and($menu)->toBeGreaterThan($no)
            ->and($menu)->toBeLessThan(24)
            ->and($token)->toBeGreaterThan($menu);
    });

    it('keeps the account line in the block and the words of the question as they were', function (): void {
        ($this->open)();

        $text = implode("\n", ($this->rows)(120));
        $who = app(ClaudeAccount::class)->who();

        expect($text)->toContain(__('studio-cli::bridge.consent.question'))
            ->and(app(BridgeSetup::class)->questionLines()['account'])->toBe($who === null ? null : __('studio-cli::bridge.consent.account', ['who' => $who]));

        $who === null || expect($text)->toContain(__('studio-cli::bridge.consent.account', ['who' => $who]));
    });

    it('answers from the block, and the screen goes back to how it was', function (): void {
        ($this->open)();

        ($this->press)("\r");

        $rows = ($this->rows)();

        expect(BridgeConsent::make()->isAllowed())->toBeTrue()
            ->and(($this->at)(mb_substr(__('studio-cli::bridge.consent.question'), 0, 30), $rows))->toBeFalse()
            ->and(($this->at)('Token', $rows))->toBeLessThan(($this->at)('Link with a new token', $rows))
            ->and(collect((fn (): array => $this->settingsActions())->call(($this->studio)()))->map(fn (array $entry): string => $entry['action']->getName())->all())->toContain('bridge')->not->toContain('allow');
    });

    it('is not shown once the developer has said no, and the screen is as it was', function (): void {
        ($this->machine)();
        BridgeConsent::make()->refuse();
        app(Studio::class)->projects();
        ($this->press)('s');

        $rows = ($this->rows)();

        expect(($this->at)(mb_substr(__('studio-cli::bridge.consent.question'), 0, 30), $rows))->toBeFalse()
            ->and(($this->at)('Token', $rows))->toBeLessThan(($this->at)('Link with a new token', $rows));
    });

    it('opens on the first answer without answering for the developer, and the status key goes there too', function (): void {
        ($this->machine)();
        ($this->asks)();

        $screen = ($this->studio)();

        expect($screen->settingsAreOpen())->toBeTrue()
            ->and((fn (): string => $this->selectedSetting()['action']->getName())->call($screen))->toBe('allow')
            ->and(BridgeConsent::make()->choice())->toBeNull();
    });

    it('stays inside the terminal at the narrow widths, with the answers on screen', function (int $width): void {
        ($this->open)();

        $rows = ($this->rows)($width, 24);

        expect(($this->at)(__('studio-cli::bridge.consent.yes'), $rows))->not->toBeFalse()
            ->and(collect($rows)->every(fn (string $row): bool => mb_strwidth(rtrim($row)) <= $width))->toBeTrue();
    })->with([72, 80, 100]);
});

describe('the bridge line on the settings screen follows the live state', function (): void {
    afterEach(function (): void {
        BridgeBeat::unmark();
    });

    it('says the bridge is not running while it is allowed and not running, and stops saying it the moment it runs, without a restart', function (): void {
        ($this->machine)();
        app(Studio::class)->projects();
        BridgeConsent::make()->allow();

        ($this->press)('s');

        $drawn = fn (): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', implode("\n", ($this->studio)()->lines(160, 30)));
        $stopped = mb_substr(__('studio-cli::bridge.cli.explain.stopped'), 0, 40);
        $running = mb_substr(__('studio-cli::bridge.cli.explain.on'), 0, 30);

        expect(preg_replace('/\s+/', ' ', $drawn()))->toContain(preg_replace('/\s+/', ' ', $stopped));

        expect(BridgeBeat::mark())->toBeTrue();

        ($this->studio)()->refreshState();

        $after = preg_replace('/\s+/', ' ', $drawn());

        expect($after)->not->toContain(preg_replace('/\s+/', ' ', $stopped))
            ->and($after)->toContain(preg_replace('/\s+/', ' ', $running));
    });

    it('does not say it when the bridge is allowed and already running', function (): void {
        ($this->machine)();
        app(Studio::class)->projects();
        BridgeConsent::make()->allow();
        BridgeBeat::mark();

        ($this->press)('s');

        $text = preg_replace('/\s+/', ' ', (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', implode("\n", ($this->studio)()->lines(160, 30))));

        expect($text)->not->toContain(mb_substr(__('studio-cli::bridge.cli.explain.stopped'), 0, 40));
    });
});

describe('moving on to the Scan tab once the setup is done', function (): void {
    beforeEach(function (): void {
        $this->ready = function (): void {
            ($this->machine)();
            app(Studio::class)->projects();
            BridgeConsent::make()->allow();
            BridgeBeat::mark();
        };
        $this->moveOn = fn () => (fn () => $this->moveOnToTheScanOnce())->call($this->command);
        $this->tabNow = fn (): string => (fn (): string => $this->getScreen()->tab($this->screenTab)->getKey())->call($this->command);
        $this->act = fn (string $action) => (fn () => $this->handleScreenAction($action))->call($this->command);
        $this->goBackToTheDashboard = fn () => (fn () => $this->showScreenTab('dashboard'))->call($this->command);
    });

    afterEach(function (): void {
        BridgeBeat::unmark();
        $this->travelBack();
    });

    it('stays on the dashboard for three seconds from when it is first ready, then switches to Scan, once', function (): void {
        ($this->ready)();

        ($this->moveOn)();
        $this->travel(2)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('dashboard');

        $this->travel(2)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('scan');

        ($this->goBackToTheDashboard)();
        $this->travel(30)->seconds();
        ($this->moveOn)();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('dashboard');
    });

    it('counts the three seconds from when everything is ready, not from when the CLI started', function (): void {
        ($this->machine)();
        app(Studio::class)->projects();

        ($this->moveOn)();
        $this->travel(60)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('dashboard');

        BridgeConsent::make()->allow();
        BridgeBeat::mark();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('dashboard');

        $this->travel(3)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('scan');
    });

    it('does not switch when the person does anything after it is ready, a key, a click or a scroll', function (string $action): void {
        ($this->ready)();
        ($this->moveOn)();

        ($this->act)($action);
        $this->travel(10)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('dashboard');
    })->with(['a scroll' => 'scroll:1', 'a click' => 'click:5:5', 'the help key' => 'help']);

    it('does not count what the person did before it was ready, such as answering the bridge question', function (): void {
        ($this->machine)();
        app(Studio::class)->projects();
        ($this->act)('settings:down');
        ($this->act)('settings:select');
        BridgeConsent::make()->allow();
        BridgeBeat::mark();
        ($this->studio)()->closeSettings();

        ($this->moveOn)();
        $this->travel(4)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('scan');
    });

    it('does not switch while a menu, a prompt or the settings are open, and waits until they are away', function (): void {
        ($this->ready)();
        ($this->studio)()->openSettings();

        ($this->moveOn)();
        $this->travel(10)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('dashboard');

        ($this->studio)()->closeSettings();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('dashboard');

        $this->travel(3)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('scan');
    });

    it('does not switch when something is undecided or failing', function (string $case): void {
        match ($case) {
            'the bridge question is waiting' => (function (): void {
                ($this->machine)();
                app(Studio::class)->projects();
            })(),
            'the bridge was refused' => (function (): void {
                ($this->machine)();
                app(Studio::class)->projects();
                BridgeConsent::make()->refuse();
            })(),
            'allowed but not running' => (function (): void {
                ($this->machine)();
                app(Studio::class)->projects();
                BridgeConsent::make()->allow();
            })(),
            'no AI to connect' => (function (): void {
                ($this->machine)(false, false);
                app(Studio::class)->projects();
            })(),
            'the token is turned away' => (function (): void {
                ($this->machine)();
                Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);
                BridgeConsent::make()->allow();
                BridgeBeat::mark();
                expect(fn () => app(Studio::class)->projects())->toThrow(RuntimeException::class);
            })(),
            'the bridge question is open on screen' => (function (): void {
                ($this->ready)();
                (fn () => $this->bridgeQuestionOpen = true)->call($this->command);
            })(),
        };

        ($this->moveOn)();
        $this->travel(30)->seconds();
        ($this->moveOn)();

        expect(($this->tabNow)())->toBe('dashboard');
    })->with(['the bridge question is waiting', 'the bridge was refused', 'allowed but not running', 'no AI to connect', 'the token is turned away', 'the bridge question is open on screen']);
});
