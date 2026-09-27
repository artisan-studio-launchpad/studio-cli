<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\AiAccess;
use ArtisanStudio\StudioCli\Scan\Secrets;
use ArtisanStudio\StudioCli\Scan\StudioChecks;
use ArtisanStudio\StudioCli\Scan\Tools\StudioSecurityScan;
use ArtisanStudio\StudioCli\Studio;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| The studio's own checks
|--------------------------------------------------------------------------
|
| Run inside the project through studio:check. Only the kind of problem and
| where it is ever leaves: never a secret, never a config value. The checks
| that read the project's code run in Artisan Studio, on the files it asks
| for.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-checks-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app', 0755, true);
    $this->git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    ($this->git)('init', '--quiet');
    $this->key = 'AKIA'.strtoupper(substr(str_shuffle(str_repeat('ABCDEFGHJKLMNPQRSTUVWZ234567', 2)), 0, 16));
});

it('finds keys committed to tracked files and a committed .env, never sending the secret, and passes over documented examples', function (): void {
    file_put_contents($this->root.'/app/Aws.php', "<?php\n\n\$key = '{$this->key}';\n");
    file_put_contents($this->root.'/app/Docs.php', "<?php\n\n\$example = 'AKIAIOSFODNN7EXAMPLE';\n");
    file_put_contents($this->root.'/.env', "APP_KEY=base64:x\n");
    file_put_contents($this->root.'/.env.example', "APP_KEY=\n");
    file_put_contents($this->root.'/untracked.php', "<?php\n\$key = '{$this->key}';\n");
    ($this->git)('add', 'app', '.env', '.env.example');

    $findings = (new Secrets($this->root))->findings();

    expect(collect($findings)->pluck('where')->sort()->values()->all())->toBe(['.env:1', 'app/Aws.php:3'])
        ->and(collect($findings)->pluck('rule')->sort()->values()->all())->toBe(['aws-access-key', 'committed-env'])
        ->and(json_encode($findings))->not->toContain($this->key);
});

it('says when an AI agent the project uses can read its .env, and stops once it is denied', function (): void {
    file_put_contents($this->root.'/CLAUDE.md', '# Project');
    mkdir($this->root.'/.cursor');

    expect(collect((new AiAccess($this->root))->findings())->pluck('rule')->all())->toBe(['ai-reads-env:claude-code', 'ai-reads-env:cursor']);

    mkdir($this->root.'/.claude');
    file_put_contents($this->root.'/.claude/settings.json', (string) json_encode(['permissions' => ['deny' => ['Read(./.env)', 'Read(./.env.*)']]]));
    file_put_contents($this->root.'/.cursorignore', ".env\n.env.*\n");

    expect((new AiAccess($this->root))->findings())->toBe([]);
});

it('reads studio:check\'s JSON as findings, and runs only when the project has what the check needs', function (): void {
    $output = (string) json_encode(['findings' => [['where' => 'app/Aws.php:3', 'rule' => 'aws-access-key', 'message' => 'Looks like an AWS access key.']]]);

    expect((new StudioSecurityScan)->findings($output, $this->root))->toBe([['where' => 'app/Aws.php:3', 'rule' => 'aws-access-key', 'message' => 'Looks like an AWS access key.']])
        ->and((new StudioSecurityScan)->findings('{"error":"x"}', $this->root))->toBeNull()
        ->and((new StudioSecurityScan)->command($this->root))->toBeNull();

    file_put_contents($this->root.'/artisan', '<?php');

    expect((new StudioSecurityScan)->command($this->root))->toContain('studio:check')->toContain('secrets');
});

it('sends a check in the studio only the files it asks for, never a .env or one holding a secret, and takes back what it found', function (): void {
    mkdir($this->root.'/app/Livewire', 0755, true);
    file_put_contents($this->root.'/app/Livewire/Invoice.php', "<?php\n\nclass Invoice extends \\Livewire\\Component {}\n");
    file_put_contents($this->root.'/app/Livewire/Keys.php', "<?php\n\n// uses Livewire\n\$key = '{$this->key}';\n");
    file_put_contents($this->root.'/app/Plain.php', "<?php\n\nclass Plain {}\n");
    file_put_contents($this->root.'/.env', "APP_KEY=base64:x\n");
    $rounds = [];
    $studio = Mockery::mock(Studio::class);
    $studio->shouldReceive('runScanCheck')->andReturnUsing(function (string $check, array $sent) use (&$rounds): array {
        $rounds[] = $sent;

        return count($rounds) === 1
            ? ['needs' => ['read' => ['.env', 'composer.lock'], 'search' => [['id' => 'classes', 'pattern' => '/Livewire/', 'in' => ['app'], 'ext' => ['php'], 'read' => true]]]]
            : ['findings' => [['where' => 'app/Livewire/Invoice.php:3', 'rule' => 'livewire-locked', 'message' => 'Add #[Locked].']], 'unread' => []];
    });

    $report = (new StudioChecks($this->root, $studio))->run('livewire');

    expect($report)->toBe(['findings' => [['where' => 'app/Livewire/Invoice.php:3', 'rule' => 'livewire-locked', 'message' => 'Add #[Locked].']], 'unread' => []])
        ->and($rounds[0]['files'])->toBe([])
        ->and(array_column($rounds[1]['files'], 'path'))->toBe(['app/Livewire/Invoice.php'])
        ->and($rounds[1]['missing'])->toContain('.env')->toContain('composer.lock')
        ->and(json_encode($rounds))->not->toContain($this->key);
});
