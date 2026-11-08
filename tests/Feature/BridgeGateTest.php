<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\BridgeBeat;
use ArtisanStudio\StudioCli\Bridge\BridgeConsent;
use ArtisanStudio\StudioCli\Bridge\BridgeDetection;
use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Bridge\BridgeKey;
use ArtisanStudio\StudioCli\Bridge\BridgeNotConnected;
use ArtisanStudio\StudioCli\Bridge\ClaudeAccount;
use ArtisanStudio\StudioCli\Bridge\Runners;
use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Fix\RectorRules;
use ArtisanStudio\StudioCli\Fix\Security\TargetedSecurityUpdates;
use ArtisanStudio\StudioCli\Fix\StudioFixes;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFixReportRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanWorkRequest;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Nothing changes the developer's code without a connected AI bridge
|--------------------------------------------------------------------------
|
| Connected means: the developer said yes, Claude Code is installed and signed
| in, and the bridge is running. Every place that fixes, writes or builds asks
| the gate first, at the point of writing. Scan, the tools and reading do not.
|
*/

/**
 * A machine whose Claude Code is, or is not, there and signed in.
 */
function aClaudeThatIs(bool $installed, bool $signedIn): ClaudeAccount
{
    return new ClaudeAccount(new class($installed, $signedIn) extends Shell
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
    });
}

/**
 * The real gate, with each of its three conditions set.
 */
function aGate(?bool $said, bool $installed, bool $signedIn, bool $running): BridgeGate
{
    $consent = new BridgeConsent(sys_get_temp_dir().'/studio-gate-'.bin2hex(random_bytes(6)).'.json', '/checkout');
    $said === null || ($said ? $consent->allow() : $consent->refuse());
    @unlink(BridgeBeat::path());
    $running && BridgeBeat::mark();

    @unlink(BridgeKey::path());

    return new BridgeGate(new BridgeDetection($consent, new Runners(new Shell, aClaudeThatIs($installed, $signedIn))));
}

/**
 * A gate that says the bridge is not connected, for the paths that ask it.
 */
function theBridgeIsNotConnected(string $status = BridgeGate::OFF): void
{
    app()->instance(BridgeGate::class, new class($status) extends BridgeGate
    {
        public function __construct(private readonly string $is) {}

        public function status(): string
        {
            return $this->is;
        }
    });
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-gate-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app', 0755, true);
    file_put_contents($this->root.'/app/A.php', "<?php\n\nclass A\n{\n}\n");
    $this->git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    ($this->git)('init', '--quiet', '--initial-branch=develop');
    ($this->git)('config', 'user.email', 'dev@example.com');
    ($this->git)('config', 'user.name', 'Developer');
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'start');
});

afterEach(function (): void {
    @unlink(BridgeBeat::path());
});

it('is connected only when the developer said yes, Claude Code is installed and signed in, and the bridge is running', function (?bool $said, bool $installed, bool $signedIn, bool $running, string $status): void {
    $gate = aGate($said, $installed, $signedIn, $running);

    expect($gate->status())->toBe($status)
        ->and($gate->isConnected())->toBe($status === BridgeGate::ON);
})->with([
    'all three' => [true, true, true, true, BridgeGate::ON],
    'declined' => [false, true, true, true, BridgeGate::OFF],
    'not asked yet' => [null, true, true, true, BridgeGate::OFF],
    'said yes, but the bridge stopped' => [true, true, true, false, BridgeGate::OFF],
    'said yes, Claude Code missing' => [true, false, false, true, BridgeGate::ATTENTION],
    'said yes, signed out' => [true, true, false, true, BridgeGate::ATTENTION],
    'not asked yet, Claude Code missing' => [null, false, false, false, BridgeGate::ATTENTION],
    'declined, Claude Code missing' => [false, false, false, false, BridgeGate::ATTENTION],
]);

it('counts a bridge only while it holds the lock, and lets a second one know it is not needed', function (): void {
    file_put_contents(BridgeBeat::path(), '2147483646');

    expect(BridgeBeat::isRunning())->toBeFalse();

    expect(BridgeBeat::mark())->toBeTrue()
        ->and(BridgeBeat::isRunning())->toBeTrue();

    BridgeBeat::unmark();

    expect(BridgeBeat::isRunning())->toBeFalse();
});

it('forgets a bridge that was killed, because the system lets go of its lock', function (): void {
    $bridge = new Process([PHP_BINARY, '-r', '$f = fopen($argv[1], "c"); flock($f, LOCK_EX); echo "held\n"; sleep(30);', BridgeBeat::path()]);
    $bridge->start();

    $deadline = microtime(true) + 5;

    while (! BridgeBeat::isRunning() && microtime(true) < $deadline) {
        usleep(50_000);
    }

    expect(BridgeBeat::isRunning())->toBeTrue()
        ->and(BridgeBeat::mark())->toBeFalse();

    posix_kill((int) $bridge->getPid(), SIGKILL);

    while ($bridge->isRunning()) {
        usleep(50_000);
    }

    expect(BridgeBeat::isRunning())->toBeFalse()
        ->and(BridgeBeat::mark())->toBeTrue();
});

it('refuses with one line that says how to turn the bridge on, and lets a connected one through', function (): void {
    $blocked = fn (): mixed => aGate(false, true, true, true)->require();

    expect($blocked)->toThrow(BridgeNotConnected::class, __('studio-cli::bridge.blocked'))
        ->and(fn () => aGate(true, true, true, true)->require())->not->toThrow(BridgeNotConnected::class);
});

it('refuses every git change it makes while the bridge is not connected', function (string $status): void {
    theBridgeIsNotConnected($status);
    $branch = new LocalBranch($this->root);
    file_put_contents($this->root.'/app/A.php', "<?php\n\nclass A\n{\n    public int \$x = 1;\n}\n");

    expect(fn () => $branch->startAt('studio/fixes-1'))->toThrow(BridgeNotConnected::class)
        ->and(fn () => $branch->start('2026-11-08'))->toThrow(BridgeNotConnected::class)
        ->and(fn () => $branch->switchTo('develop'))->toThrow(BridgeNotConnected::class)
        ->and(fn () => $branch->commit('fix: x', '- y'))->toThrow(BridgeNotConnected::class)
        ->and(fn () => $branch->restore(['app/A.php']))->toThrow(BridgeNotConnected::class)
        ->and(($this->git)('branch', '--show-current'))->toBe('develop')
        ->and(file_get_contents($this->root.'/app/A.php'))->toContain('public int $x');
})->with(['off' => BridgeGate::OFF, 'needs attention' => BridgeGate::ATTENTION]);

it('starts a branch and commits when the bridge is connected', function (): void {
    $branch = new LocalBranch($this->root);
    file_put_contents($this->root.'/app/A.php', "<?php\n\nclass A\n{\n    public int \$x = 1;\n}\n");

    $started = $branch->startAt('studio/fixes-1');

    expect($started)->toBe('studio/fixes-1')
        ->and($branch->commit('fix: x', '- y'))->toBeString();
});

it('sends nothing to the studio to be fixed, and writes nothing, while the bridge is not connected', function (): void {
    theBridgeIsNotConnected();
    Saloon::fake([]);
    $fixes = new StudioFixes($this->root, app(Studio::class));

    expect(fn () => $fixes->fix(['phpstan' => [['where' => 'app/A.php:3', 'rule' => 'x', 'message' => 'y']]]))->toThrow(BridgeNotConnected::class);

    Saloon::assertNothingSent();
});

it('runs no Pint or Rector fix, and no npm or composer patch, while the bridge is not connected', function (): void {
    theBridgeIsNotConnected();
    $environment = fn (): array => [];

    expect(fn () => (new Toolbox($this->root))->fix('pint', ['app/A.php']))->toThrow(BridgeNotConnected::class)
        ->and(fn () => (new Toolbox($this->root))->fix('rector'))->toThrow(BridgeNotConnected::class)
        ->and(fn () => (new RectorRules($this->root, $environment))->apply(['phpstan'], ['app/A.php']))->toThrow(BridgeNotConnected::class)
        ->and(fn () => (new TargetedSecurityUpdates($this->root, $environment))->fix('node-audit'))->toThrow(BridgeNotConnected::class)
        ->and(fn () => (new TargetedSecurityUpdates($this->root, $environment))->fix('composer-audit'))->toThrow(BridgeNotConnected::class);
});

it('refuses studio:fix, studio:insights-fix and studio:work with the one line, and tells the studio, while the bridge is not connected', function (): void {
    theBridgeIsNotConnected();
    Saloon::fake([SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202), SubmitScanWorkRequest::class => MockResponse::make(['recorded' => true], 202)]);

    expect(Artisan::call('studio:fix', ['--plain' => true, '--yes' => true]))->not->toBe(0)
        ->and(Artisan::output())->toContain(__('studio-cli::bridge.blocked'));

    expect(Artisan::call('studio:insights-fix', ['--no-interaction' => true]))->not->toBe(0)
        ->and(Artisan::output())->toContain(__('studio-cli::bridge.blocked'));

    $refused = collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): array => $response->getPendingRequest()->body()->all())
        ->first(fn (array $body): bool => isset($body['refused']));

    expect($refused['refused'])->toBe(__('studio-cli::bridge.blocked'))
        ->and(($this->git)('branch', '--list', 'studio/*'))->toBe('');
});
