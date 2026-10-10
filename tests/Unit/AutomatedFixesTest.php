<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Scan\Tools\DefinedTool;
use ArtisanStudio\StudioCli\Scan\Tools\Pint;
use ArtisanStudio\StudioCli\Scan\Tools\Rector;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| What fixing does on this machine
|--------------------------------------------------------------------------
|
| The studio's own fixers run in the studio. Here: the local branch and its
| commits, the tools' own fixers, and the project's PHPStan set up to read
| model casts.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-fixes-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Livewire/Concerns', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '^8.4'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $this->fixes = new AutomatedFixes(new Toolbox($this->root), $this->root);
    $this->write = fn (string $path, string $code): int|false => file_put_contents($this->root.'/'.$path, $code);
    $this->read = fn (string $path): string => (string) file_get_contents($this->root.'/'.$path);
});

it('adds the studio\'s own findings to a rule\'s, only at a plain path inside the project', function (): void {
    config(['studio-cli.added' => [
        'phpstan' => [
            ['where' => 'phpstan.neon', 'rule' => 'config', 'message' => 'Read the casts.'],
            ['where' => '../elsewhere.neon', 'rule' => 'config', 'message' => 'Outside.'],
            ['where' => '/etc/hosts', 'rule' => 'config', 'message' => 'Absolute.'],
        ],
    ]]);

    expect(AutomatedFixes::added())->toBe(['phpstan' => [['where' => 'phpstan.neon', 'rule' => 'config', 'message' => 'Read the casts.']]]);
});

it('runs each tool\'s own fixer only when it is set up, Rector and Pint before the checks that read the code', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    array_map(fn (string $bin): bool => touch($this->root.'/vendor/bin/'.$bin), ['rector', 'house-check', 'pint']);
    DefinedTool::keep($this->root, [['key' => 'house-check', 'name' => 'House check', 'bin' => 'house-check', 'needs' => ['folders' => ['app/Admin']], 'args' => ['app/Admin'], 'fix' => ['app/Admin', '--fix']]]);
    $houseCheck = (new Toolbox($this->root))->tool('house-check');

    expect((new Rector)->fixCommand($this->root))->toBeNull()
        ->and($houseCheck->fixCommand($this->root))->toBeNull()
        ->and((new Pint)->canFix($this->root))->toBeTrue();

    touch($this->root.'/rector.php');
    mkdir($this->root.'/app/Admin', 0755, true);

    expect((new Rector)->fixCommand($this->root))->toBe(['vendor/bin/rector', 'process', '--no-progress-bar'])
        ->and($houseCheck->fixCommand($this->root))->toBe(['vendor/bin/house-check', 'app/Admin', '--fix'])
        ->and(AutomatedFixes::tools(new Toolbox($this->root)))->toBe(['house-check', 'rector', 'pint'])
        ->and($this->fixes->fixable(['pint', 'composer-audit', 'phpstan', 'pest-type-coverage', 'rector', 'node-audit']))->toBe(['pest-type-coverage', 'phpstan', 'rector', 'pint']);
});

it('counts an audit by its packages, and everything else one finding each', function (): void {
    expect(AutomatedFixes::counted('node-audit', [
        ['where' => 'package.json:7', 'rule' => 'CVE-1', 'message' => 'picomatch 2.3.1 via vite: x (high)'],
        ['where' => 'package.json:6', 'rule' => 'CVE-2', 'message' => 'picomatch 4.0.3 via laravel-vite-plugin: y (high)'],
        ['where' => 'package.json:7', 'rule' => 'CVE-3', 'message' => 'rollup 4.52.5 via vite: z (high)'],
    ]))->toBe(2)
        ->and(AutomatedFixes::counted('phpstan', [['where' => 'a.php:1', 'rule' => 'r', 'message' => 'm'], ['where' => 'a.php:2', 'rule' => 'r', 'message' => 'm']]))->toBe(2);
});

it('fixes on a new local branch only from a clean tree, one commit per rule, never pushed', function (): void {
    $git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    $git('init', '--quiet', '--initial-branch=develop');
    $git('config', 'user.email', 'dev@example.com');
    $git('config', 'user.name', 'Developer');
    ($this->write)('app/Order.php', "<?php\n");
    $git('add', '-A');
    $git('commit', '--quiet', '-m', 'start');

    $branch = new LocalBranch($this->root);
    ($this->write)('app/Order.php', "<?php\n// mine\n");

    expect($branch->isRepository())->toBeTrue()
        ->and($branch->isClean())->toBeFalse();

    $git('commit', '--quiet', '-am', 'mine');
    ($this->write)('notes.txt', 'untracked');

    $name = $branch->start('2026-10-06-1412');
    ($this->write)('app/Order.php', "<?php\n// fixed\n");
    $sha = $branch->commit('fix(phpstan): fixed 1 PHPStan finding automatically', '- 1 thing');

    expect($name)->toBe('studio/fixes-2026-10-06-1412')
        ->and($branch->current())->toBe('studio/fixes-2026-10-06-1412')
        ->and($sha)->toBe($git('rev-parse', 'HEAD'))
        ->and($git('show', '--name-only', '--format=', 'HEAD'))->toBe('app/Order.php')
        ->and($branch->commit('nothing to commit'))->toBeNull()
        ->and($git('remote'))->toBe('');

    $git('switch', '--quiet', 'develop');

    expect($branch->start('2026-10-06-1412'))->toBe('studio/fixes-2026-10-06-1412-2');
});

it('keeps two fixers on one branch apart: each commits only its own files, and new files only when it names them', function (): void {
    $git = fn (string ...$arguments): string => trim((new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput());
    $git('init', '--quiet', '--initial-branch=develop');
    $git('config', 'user.email', 'dev@example.com');
    $git('config', 'user.name', 'Developer');
    ($this->write)('app/Order.php', "<?php\n");
    ($this->write)('package.json', "{}\n");
    $git('add', '-A');
    $git('commit', '--quiet', '-m', 'start');

    $branch = new LocalBranch($this->root);
    $branch->start('2026-10-06-1500');
    ($this->write)('app/Order.php', "<?php\n// fixed\n");
    ($this->write)('package.json', "{\"pnpm\": {}}\n");
    mkdir($this->root.'/public');
    ($this->write)('public/app.js', 'published');

    expect($branch->changed(except: ['package.json']))->toBe(['app/Order.php'])
        ->and($branch->untracked())->toContain('public/app.js');

    $branch->commit('fix: PHP', except: ['package.json']);

    expect($git('show', '--name-only', '--format=', 'HEAD'))->toBe('app/Order.php');

    $branch->commit('fix(security): npm', only: ['package.json'], new: ['public/app.js']);

    expect(explode("\n", $git('show', '--name-only', '--format=', 'HEAD')))->toBe(['package.json', 'public/app.js'])
        ->and($branch->isClean())->toBeTrue();
});
