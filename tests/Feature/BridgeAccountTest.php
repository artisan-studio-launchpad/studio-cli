<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\BridgeConsent;
use ArtisanStudio\StudioCli\Bridge\BridgeSetup;
use ArtisanStudio\StudioCli\Bridge\ClaudeAccount;
use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\Terminal\Action;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Which Claude account the bridge uses
|--------------------------------------------------------------------------
|
| `claude auth status` says who Claude Code is signed in as: the email, the
| organization and the plan. That is shown to the developer on the setup
| screen and the status line, and never sent to the studio. A developer with
| more than one account can point the bridge at one config folder.
|
*/

/**
 * A machine whose `claude auth status` prints this.
 *
 * @param  array<string, mixed>  $status
 */
function aMachineSignedInAs(array $status): Shell
{
    return new class($status) extends Shell
    {
        public function __construct(private readonly array $status) {}

        public function find(string $program): ?string
        {
            return '/usr/local/bin/'.$program;
        }

        public function run(array $command, string $input, string $cwd, int $timeout): array
        {
            return ['exit' => 0, 'out' => json_encode($this->status), 'err' => '', 'timedOut' => false];
        }
    };
}

beforeEach(function (): void {
    $this->as = function (array $status): void {
        app()->instance(Shell::class, aMachineSignedInAs($status));
        app()->forgetInstance(ClaudeAccount::class);
        app()->forgetInstance(BridgeSetup::class);
    };
    $this->signedIn = ['loggedIn' => true, 'authMethod' => 'claude.ai', 'email' => 'dev@example.com', 'orgName' => 'Example Org', 'subscriptionType' => 'pro', 'configDirectory' => '/home/dev/.claude'];
});

afterEach(fn () => @unlink(BridgeConsent::make()->path()));

it('reads who Claude Code is signed in as from what it prints', function (): void {
    ($this->as)($this->signedIn);

    expect(app(ClaudeAccount::class)->status())->toMatchArray(['installed' => true, 'signed_in' => true, 'email' => 'dev@example.com', 'organization' => 'Example Org', 'plan' => 'pro', 'config_directory' => '/home/dev/.claude'])
        ->and(app(ClaudeAccount::class)->who())->toBe('dev@example.com (Example Org, pro)');
});

it('names the organization when there is no email, and nobody when it says nothing about who', function (array $status, ?string $who): void {
    ($this->as)($status);

    expect(app(ClaudeAccount::class)->who())->toBe($who);
})->with([
    'no email' => [['loggedIn' => true, 'orgName' => 'Example Org'], 'Example Org'],
    'only a plan' => [['loggedIn' => true, 'subscriptionType' => 'pro'], null],
    'signed out' => [['loggedIn' => false, 'email' => 'dev@example.com'], null],
]);

it('shows the account on the setup question, the Settings row and the status line', function (): void {
    ($this->as)($this->signedIn);
    $setup = app(BridgeSetup::class);

    expect($setup->question())->toContain('dev@example.com')
        ->and($setup->row()['value'])->toContain('dev@example.com');

    BridgeConsent::make()->allow();
    expect($setup->row()['value'])->toContain('dev@example.com');
});

it('asks the plain question when Claude Code does not say who is signed in', function (): void {
    ($this->as)(['loggedIn' => true]);

    expect(app(BridgeSetup::class)->question())->toBe(__('studio-cli::bridge.consent.question'));
});

it('lets a question be worked out when it is shown', function (): void {
    $asked = 0;
    $action = Action::make('bridge')->question(function () use (&$asked): string {
        $asked++;

        return 'Which one?';
    });

    expect($asked)->toBe(0)
        ->and($action->getQuestion())->toBe('Which one?')
        ->and($asked)->toBe(1)
        ->and(Action::make('plain')->question('Sure?')->getQuestion())->toBe('Sure?');
});

it('leaves the Claude config folder alone unless studio-cli.bridge.claude_config_dir is set', function (): void {
    expect(config('studio-cli.bridge.claude_config_dir'))->toBeNull();

    $folder = sys_get_temp_dir().'/studio-claude-config-'.bin2hex(random_bytes(4));
    $before = Shell::environment()['CLAUDE_CONFIG_DIR'] ?? false;
    config(['studio-cli.bridge.claude_config_dir' => $folder]);

    expect(Shell::environment()['CLAUDE_CONFIG_DIR'])->toBe($folder)
        ->and($before)->not->toBe($folder);
});

it('starts the programs the bridge runs with the Claude config folder it was pointed at', function (): void {
    $folder = sys_get_temp_dir().'/studio-claude-config-'.bin2hex(random_bytes(4));
    mkdir($folder, 0700, true);
    config(['studio-cli.bridge.claude_config_dir' => $folder]);

    $ran = (new Shell)->run([PHP_BINARY, '-r', 'echo getenv("CLAUDE_CONFIG_DIR");'], '', sys_get_temp_dir(), 20);
    File::deleteDirectory($folder);

    expect($ran['out'])->toBe($folder);
});
