<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\InstallToolsCommand;
use ArtisanStudio\StudioCli\Scan\Tools\DefinedTool;

/*
|--------------------------------------------------------------------------
| What a tool may ask to have installed
|--------------------------------------------------------------------------
|
| Only plain Composer packages, installed with no scripts and no plugins, and
| with the packages after `--` so that none can be read as an option.
|
*/

it('offers to install only plain vendor/name packages', function (): void {
    $root = sys_get_temp_dir().'/studio-install-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    $install = function (array $packages) use ($root): ?array {
        DefinedTool::keep($root, [['key' => 'house-check', 'name' => 'House check', 'bin' => 'house-check', 'install' => ['packages' => $packages, 'files' => [], 'about' => 'x']]]);

        return DefinedTool::all($root)[0]->install();
    };

    expect($install(['acme/house-check', 'acme/other.pkg'])['packages'])->toBe(['acme/house-check', 'acme/other.pkg'])
        ->and($install(['--repository=https://example.com']))->toBeNull()
        ->and($install(['-d']))->toBeNull()
        ->and($install(['acme/house-check', 'no-vendor']))->toBeNull()
        ->and($install(['acme/house-check:dev-main']))->toBeNull()
        ->and($install(['Acme/House']))->toBeNull()
        ->and($install(['acme/a/b']))->toBeNull()
        ->and($install([]))->toBe(['packages' => [], 'files' => [], 'about' => 'x']);
});

it('installs with no scripts, no plugins, and nothing after the dashes read as an option', function (): void {
    $command = InstallToolsCommand::composerCommand(['acme/house-check', 'acme/other']);

    expect($command)->toBe(['composer', 'require', '--dev', '--no-interaction', '--no-scripts', '--no-plugins', '--', 'acme/house-check', 'acme/other'])
        ->and(InstallToolsCommand::composerCommand(['--working-dir=/tmp']))->toBeNull()
        ->and(InstallToolsCommand::composerCommand(['acme/house-check', '-n']))->toBeNull()
        ->and(InstallToolsCommand::composerCommand([]))->toBeNull();
});
