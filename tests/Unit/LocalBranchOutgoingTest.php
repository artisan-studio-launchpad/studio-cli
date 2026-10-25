<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\LocalBranch;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-outgoing-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
    $this->git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    ($this->git)('init', '--quiet', '--initial-branch=develop');
    ($this->git)('config', 'user.email', 'dev@example.com');
    ($this->git)('config', 'user.name', 'Developer');
    $this->commit = function (string $file, string $message): string {
        file_put_contents($this->root.'/'.$file, $message."\n");
        ($this->git)('add', '-A');
        ($this->git)('commit', '--quiet', '-m', $message);

        return ($this->git)('rev-parse', 'HEAD');
    };
});

it('starts a pull request from the newest commit GitHub has, and sends the developer\'s own unpushed commits with the branch', function (): void {
    $onGitHub = ($this->commit)('a.txt', 'on GitHub');
    ($this->git)('update-ref', 'refs/remotes/origin/develop', $onGitHub);
    ($this->commit)('b.txt', 'only on the developer\'s machine');
    ($this->git)('checkout', '--quiet', '-b', 'studio/build-coverage');
    ($this->commit)('c.txt', 'built by the studio');

    $git = new LocalBranch($this->root);
    $outgoing = $git->outgoing('studio/build-coverage', 'develop');

    expect($outgoing['base'])->toBe($onGitHub)
        ->and(array_column($outgoing['commits'], 'message'))->toBe(["only on the developer's machine\n", "built by the studio\n"])
        ->and($git->unpushed('develop'))->toBe(1);
});

it('compares with the local branch when there is no remote one, and counts nothing unpushed', function (): void {
    $start = ($this->commit)('a.txt', 'start');
    ($this->git)('checkout', '--quiet', '-b', 'studio/build-coverage');
    ($this->commit)('c.txt', 'built by the studio');

    $git = new LocalBranch($this->root);
    $outgoing = $git->outgoing('studio/build-coverage', 'develop');

    expect($outgoing['base'])->toBe($start)
        ->and(array_column($outgoing['commits'], 'message'))->toBe(["built by the studio\n"])
        ->and($git->unpushed('develop'))->toBe(0);
});

it('names the history under the commit a branch starts from, newest first, and sends from the commit the studio says GitHub has', function (): void {
    $first = ($this->commit)('a.txt', 'one');
    $second = ($this->commit)('b.txt', 'two');
    $third = ($this->commit)('c.txt', 'three');
    ($this->git)('update-ref', 'refs/remotes/origin/develop', $third);
    ($this->git)('checkout', '--quiet', '-b', 'studio/build-coverage');
    ($this->commit)('d.txt', 'built by the studio');

    $git = new LocalBranch($this->root);
    $normal = $git->outgoing('studio/build-coverage', 'develop');
    $fromEarlier = $git->outgoing('studio/build-coverage', 'develop', $first);

    expect($git->ancestors($third))->toBe([$third, $second, $first])
        ->and($normal['base'])->toBe($third)
        ->and(array_column($normal['commits'], 'message'))->toBe(["built by the studio\n"])
        ->and($fromEarlier['base'])->toBe($first)
        ->and(array_column($fromEarlier['commits'], 'message'))->toBe(["two\n", "three\n", "built by the studio\n"]);
});

it('falls back to what it compares with when the commit the studio names is not on this machine', function (): void {
    $start = ($this->commit)('a.txt', 'start');
    ($this->git)('checkout', '--quiet', '-b', 'studio/build-coverage');
    ($this->commit)('c.txt', 'built by the studio');

    $outgoing = (new LocalBranch($this->root))->outgoing('studio/build-coverage', 'develop', str_repeat('f', 40));

    expect($outgoing['base'])->toBe($start);
});
