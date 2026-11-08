<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\ProjectReads;
use ArtisanStudio\StudioCli\Scan\Secrets;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Keys in the repository, and what is sent when the studio asks to read
|--------------------------------------------------------------------------
|
| Only the kind of problem and where it is ever leaves: never a secret, never
| a config value. When the studio asks to read more of the project, a .env
| and any file holding a secret are never sent.
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

it('sends only the files the studio asks for, never a .env or one holding a secret, and keeps the ones it could not send', function (): void {
    mkdir($this->root.'/app/Livewire', 0755, true);
    file_put_contents($this->root.'/app/Livewire/Invoice.php', "<?php\n\nclass Invoice extends \\Livewire\\Component {}\n");
    file_put_contents($this->root.'/app/Livewire/Keys.php', "<?php\n\n// uses Livewire\n\$key = '{$this->key}';\n");
    file_put_contents($this->root.'/app/Plain.php', "<?php\n\nclass Plain {}\n");
    file_put_contents($this->root.'/.env', "APP_KEY=base64:x\n");

    $reads = new ProjectReads($this->root);
    $sent = $reads->answer(['read' => ['.env', 'composer.lock'], 'search' => [['id' => 'classes', 'pattern' => '/Livewire/', 'in' => ['app'], 'ext' => ['php'], 'read' => true]]], []);

    expect($sent)->toBe(['app/Livewire/Invoice.php'])
        ->and($reads->missing())->toContain('.env')->toContain('composer.lock')
        ->and($reads->readable('app/Livewire/Keys.php'))->toBeNull()
        ->and($reads->readable('.env'))->toBeNull()
        ->and(json_encode([$sent, $reads->found()]))->not->toContain($this->key);
});
