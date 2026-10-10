<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\RectorRules;

/*
|--------------------------------------------------------------------------
| The Rector rules the studio asks for
|--------------------------------------------------------------------------
|
| Each rule is written into a config this machine runs, so only a plain class
| name whose file sits in the project's vendor folder is ever used.
|
*/

it('uses only plain class names whose file is in the vendor folder', function (): void {
    config(['studio-cli.rector' => [
        'phpstan' => [
            'RectorLaravel\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector' => 'vendor/driftingly/rector-laravel/src/Rector/ClassMethod/AddGenericReturnTypeToRelationsRector.php',
            'Evil::class]); system("rm -rf /"); //' => 'vendor/x/y.php',
            'Rector\Outside\Rule' => '../../etc/passwd.php',
            'Rector\Elsewhere\Rule' => 'app/Rule.php',
        ],
        'pest-type-coverage' => ['Rector\Bad Name' => 'vendor/x/y.php'],
        0 => ['Rector\Rule' => 'vendor/x/y.php'],
    ]]);

    expect(RectorRules::rules())->toBe(['phpstan' => ['RectorLaravel\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector' => 'vendor/driftingly/rector-laravel/src/Rector/ClassMethod/AddGenericReturnTypeToRelationsRector.php']])
        ->and(RectorRules::keys())->toBe(['phpstan']);
});

it('has no rules until the studio has sent them', function (): void {
    config(['studio-cli.rector' => null]);

    expect(RectorRules::rules())->toBe([])
        ->and(RectorRules::keys())->toBe([]);
});
