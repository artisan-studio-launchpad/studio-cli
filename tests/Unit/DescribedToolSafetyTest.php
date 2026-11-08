<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\Tools\DefinedTool;

/*
|--------------------------------------------------------------------------
| What a tool the studio describes may be told to do
|--------------------------------------------------------------------------
|
| A described tool runs a binary from vendor/bin. It may not be told to load
| a PHP file of the project's, which the same studio could have written.
|
*/

function describedTool(array $changes = []): array
{
    return ['key' => 'house-check', 'name' => 'House check', 'bin' => 'house-check', 'args' => ['app/Admin'], ...$changes];
}

dataset('arguments that load a file', [
    'a bootstrap' => '--bootstrap=app/Boot.php',
    'a bootstrap without a value' => '--bootstrap',
    'a configuration' => '--configuration=phpunit.xml',
    'a short configuration' => '-c',
    'a short configuration with a value' => '-cphpunit.xml',
    'an autoload file' => '--autoload-file=app/Load.php',
    'a PHP file in the project' => 'app/Boot.php',
    'a PHP file given to another option' => '--config=rector.php',
    'a phar' => 'tools/house.phar',
]);

dataset('arguments that are fine', [
    'a folder' => 'app/Admin',
    'a flag' => '--fix',
    'a level' => '--level=5',
    'a version number' => '--min=1.5',
    'a format' => '--format=json',
    'the cores placeholder' => '--processes={workers}',
    'a config file inside vendor' => '--config=vendor/acme/house/config.neon',
]);

it('refuses an argument that would load a file of the project\'s', function (string $argument): void {
    expect(DefinedTool::isSafeArgument($argument))->toBeFalse()
        ->and(DefinedTool::isValid(describedTool(['args' => [$argument]])))->toBeFalse()
        ->and(DefinedTool::isValid(describedTool(['fix' => [$argument]])))->toBeFalse()
        ->and(DefinedTool::isValid(describedTool(['pathPrefix' => $argument])))->toBeFalse();
})->with('arguments that load a file');

it('keeps an argument that names a folder, a flag or a file inside vendor', function (string $argument): void {
    expect(DefinedTool::isSafeArgument($argument))->toBeTrue()
        ->and(DefinedTool::isValid(describedTool(['args' => [$argument], 'fix' => [$argument]])))->toBeTrue();
})->with('arguments that are fine');
