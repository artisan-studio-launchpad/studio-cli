<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\Security\TargetedSecurityUpdates;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;

/*
|--------------------------------------------------------------------------
| Security fixes, targeted
|--------------------------------------------------------------------------
|
| Artisan Studio plans which packages move and how far. What it says is
| written into package.json here, so only a plain range for a package the
| project lists, or an override bound to one version line, is ever written.
|
*/

it('raises a listed dependency and adds an override, keeping any override the project already has', function (): void {
    $manifest = TargetedSecurityUpdates::withChanges(
        ['dependencies' => ['axios' => '^1.7.4', 'vite' => '^7.0.4'], 'devDependencies' => ['typescript' => '^5.9.3'], 'pnpm' => ['overrides' => ['tiny@>=0.4.0 <0.4.2' => '^0.4.3']]],
        [
            'dependencies' => ['axios' => '^1.20.0'],
            'devDependencies' => [],
            'overrides' => ['postcss@>=8.0.0 <8.5.23' => '^8.5.23', 'tiny@>=0.4.0 <0.4.2' => '^0.4.2'],
        ],
    );

    expect($manifest['dependencies'])->toBe(['axios' => '^1.20.0', 'vite' => '^7.0.4'])
        ->and($manifest['devDependencies'])->toBe(['typescript' => '^5.9.3'])
        ->and($manifest['pnpm']['overrides'])->toBe(['tiny@>=0.4.0 <0.4.2' => '^0.4.3', 'postcss@>=8.0.0 <8.5.23' => '^8.5.23']);
});

it('never writes a range that is not a plain version, a package the project does not list, or an override of another shape', function (): void {
    $before = ['dependencies' => ['axios' => '^1.7.4']];

    expect(TargetedSecurityUpdates::withChanges($before, [
        'dependencies' => ['axios' => 'git+https://evil.test/axios.git', 'left-pad' => '^1.0.0'],
        'devDependencies' => ['axios' => 'file:../evil'],
        'overrides' => ['axios' => '^9.9.9', 'postcss@>=8.0.0 <8.5.23' => 'latest', 'tiny; rm -rf /' => '^1.0.0'],
    ]))->toBe($before);
});

it('offers the security audits as fixable when the project has their lockfiles', function (): void {
    $root = sys_get_temp_dir().'/studio-security-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    touch($root.'/composer.lock');

    expect((new AutomatedFixes(new Toolbox($root), $root))->fixable(['node-audit', 'composer-audit']))->toBe(['composer-audit']);

    touch($root.'/pnpm-lock.yaml');

    expect((new AutomatedFixes(new Toolbox($root), $root))->fixable(['node-audit', 'composer-audit']))->toBe(['composer-audit', 'node-audit']);
});
