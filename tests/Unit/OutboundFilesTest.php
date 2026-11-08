<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Scan\ProjectReads;
use ArtisanStudio\StudioCli\Scan\Tools\DefinedTool;
use ArtisanStudio\StudioCli\Tests\Support\SecretLookingFiles;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Files that leave this machine
|--------------------------------------------------------------------------
|
| One filter decides it for the files the app asks to see, the lines a search
| of the app finds, and the files a fix reads: a tracked env file, a private
| key and a file holding a secret are refused, a plain file is sent.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-outbound-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
    $this->git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    ($this->git)('init', '--quiet', '--initial-branch=develop');
    ($this->git)('config', 'user.email', 'dev@example.com');
    ($this->git)('config', 'user.name', 'Developer');
    SecretLookingFiles::plant($this->root);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'start');
});

it('refuses a tracked env file, a key file and a file holding a secret on every read, and sends the plain one', function (): void {
    $reads = new ProjectReads($this->root);

    foreach (SecretLookingFiles::REFUSED as $path) {
        expect($reads->isSendable($path) && $reads->readable($path) !== null)->toBeFalse();
    }

    expect($reads->readable(SecretLookingFiles::FINE))->toBeString()
        ->and($reads->allows(SecretLookingFiles::FINE))->toBeTrue()
        ->and(array_map(fn (string $path): bool => $reads->allows($path), SecretLookingFiles::REFUSED))->toBe([false, false, false]);
});

it('sends the files the app asks to see as missing when they are an env file, a key file or hold a secret', function (): void {
    $wanted = [...SecretLookingFiles::REFUSED, SecretLookingFiles::FINE, '../outside.php', '/etc/hosts'];
    $tracked = (new LocalBranch($this->root))->tracked([...SecretLookingFiles::REFUSED, SecretLookingFiles::FINE]);

    $asked = (new ProjectReads($this->root))->askedFor($wanted, $tracked, 300_000);

    expect(array_column($asked, 'path'))->toBe($wanted)
        ->and(collect($asked)->filter(fn (array $file): bool => $file['content'] !== null)->pluck('path')->all())->toBe([SecretLookingFiles::FINE])
        ->and(SecretLookingFiles::leaks(json_encode($asked, JSON_THROW_ON_ERROR)))->toBeFalse();
});

it('leaves the lines of an env file, a key file and a file holding a secret out of what a search finds', function (): void {
    file_put_contents($this->root.'/app/Notes.php', "<?php\n// token\n");
    file_put_contents($this->root.'/.env', "token=APP_KEY\n");
    file_put_contents($this->root.'/keys/server.pem', "token\n");
    file_put_contents($this->root.'/app/Keys.php', "<?php\n// token AKIAABCDEFGHIJKLMNOP\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'more');
    $reads = new ProjectReads($this->root);

    $found = (new LocalBranch($this->root))->grep('token', null, 50, '~(^|/)\.env~', $reads->allows(...));

    expect(collect($found['matches'])->map(fn (string $line): string => strstr($line, ':', true))->unique()->values()->all())->toBe(['app/Notes.php'])
        ->and(SecretLookingFiles::leaks(implode("\n", $found['matches'])))->toBeFalse();
});

it('leaves the changes to an env file, a key file and a file holding a secret out of the diffs it reports, and keeps them for itself', function (): void {
    $base = ($this->git)('rev-parse', 'HEAD');
    foreach (['.env', 'keys/server.pem', 'app/Keys.php', 'app/Fine.php'] as $path) {
        file_put_contents($this->root.'/'.$path, "\n// changed\n", FILE_APPEND);
    }
    file_put_contents($this->root.'/app/Fine.php', "// a plain change\n", FILE_APPEND);
    ($this->git)('add', '-A');
    ($this->git)('commit', '--quiet', '-m', 'a fix');
    $branch = new LocalBranch($this->root);

    expect(array_column($branch->changesSince($base), 'path'))->toEqualCanonicalizing(['.env', 'keys/server.pem', 'app/Keys.php', 'app/Fine.php'])
        ->and(array_column($branch->changesToSend($base), 'path'))->toBe(['app/Fine.php'])
        ->and(SecretLookingFiles::leaks(json_encode($branch->changesToSend($base), JSON_THROW_ON_ERROR)))->toBeFalse();
});

it('leaves the uncommitted change to an env file, a key file and a file holding a secret out of the patch it shows, file by file', function (): void {
    foreach (['.env', 'keys/server.pem', 'app/Keys.php', 'app/Fine.php'] as $path) {
        file_put_contents($this->root.'/'.$path, "\n// changed\n", FILE_APPEND);
    }
    $branch = new LocalBranch($this->root);

    $patch = $branch->patchOf(['.env', 'keys/server.pem', 'app/Keys.php', 'app/Fine.php']);

    expect($patch)->toContain('app/Fine.php')
        ->and($patch)->not->toContain('app/Keys.php')
        ->and($patch)->not->toContain('server.pem')
        ->and(SecretLookingFiles::leaks($patch))->toBeFalse()
        ->and($branch->patchOf(['.env', 'keys/server.pem', 'app/Keys.php']))->toBe('')
        ->and($branch->patchOf([]))->toBe('');
});

it('sends a described tool\'s config files through the same filter, leaving out a secret by name and one holding a key', function (): void {
    file_put_contents($this->root.'/auth.json', '{"github-oauth":{"github.com":"abc"}}');
    file_put_contents($this->root.'/credentials.json', '{"key":"AKIAABCDEFGHIJKLMNOP"}');
    file_put_contents($this->root.'/phpstan.neon', "parameters:\n    level: 5\n");
    file_put_contents($this->root.'/pint.json', '{"preset":"laravel"}');
    DefinedTool::keep($this->root, [['key' => 'house-check', 'name' => 'House check', 'bin' => 'house-check', 'reads' => ['auth.json', 'credentials.json', 'phpstan.neon', 'pint.json', 'missing.json']]]);
    $tool = DefinedTool::all($this->root)[0];

    $files = (new ReflectionMethod($tool, 'files'))->invoke($tool, $this->root);

    expect(array_keys($files))->toBe(['phpstan.neon', 'pint.json'])
        ->and(SecretLookingFiles::leaks(json_encode($files, JSON_THROW_ON_ERROR)))->toBeFalse();
});
