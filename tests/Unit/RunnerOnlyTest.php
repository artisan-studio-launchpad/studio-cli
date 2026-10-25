<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| This package is a runner
|--------------------------------------------------------------------------
|
| It runs standalone tools, sends the files Artisan Studio asks for, applies
| the edits that come back, and draws the terminal. What counts as a problem,
| and how to fix it, is held in Artisan Studio and arrives through its API:
| no ruleset, detector, pattern list or fixer is kept here.
|
| Adding a file to a folder below fails this test on purpose. Before adding it
| to the list, ask: is this a rule or a fix? If it is, it belongs in Artisan
| Studio, not here.
|
*/

dataset('runner folders', [
    'scan' => ['src/Scan', ['ClassFacts', 'Glob', 'LastFindings', 'Load', 'ManifestLines', 'PackageNamespaces', 'ProjectReads', 'ScanProgress', 'ScanRules', 'SourceFile', 'ToolStatus', 'Walk'], ['Secrets']],
    'scan tools' => ['src/Scan/Tools', ['ComposerAudit', 'DefinedTool', 'NodeAudit', 'PestArch', 'PhpStan', 'Pint', 'Rector', 'TestSuite', 'Tests', 'Tool', 'Toolbox', 'TypeCoverage'], []],
    'fix' => ['src/Fix', ['AutomatedFixes', 'FixLauncher', 'FixPanel', 'FixProgress', 'LocalBranch', 'RectorRules', 'StudioFixes'], []],
    'fix security' => ['src/Fix/Security', ['TargetedSecurityUpdates'], []],
    'work' => ['src/Work', ['BuildRun', 'EditApplier', 'PhpCheck', 'PrototypeCopy', 'StepChecks', 'TestRunner', 'Timeline', 'WorkLauncher', 'WorkPanel', 'WorkProgress', 'WorkRun'], []],
    'work concerns' => ['src/Work/Concerns', ['AsksTheDeveloper', 'ChecksTheMachine', 'ReportsToTheStudio', 'StreamsFromTheStudio', 'TimesTheWork'], []],
]);

it('keeps only the files a runner needs in each folder', function (string $folder, array $allowed, array $exceptions): void {
    $found = collect(glob(dirname(__DIR__, 2).'/'.$folder.'/*.php'))
        ->map(fn (string $file): string => basename($file, '.php'))
        ->sort()
        ->values()
        ->all();

    $this->assertSame([], array_values(array_diff($found, $allowed, $exceptions)), "A file in {$folder} is not on the runner allowlist. Is it a rule or a fix? Then it belongs in Artisan Studio.");
    $this->assertSame([], array_values(array_diff([...$allowed, ...$exceptions], $found)), "The allowlist names a file {$folder} no longer has. Take it off the list.");
})->with('runner folders');

it('adds no folder beside the runner ones', function (): void {
    $folders = collect(glob(dirname(__DIR__, 2).'/src/{Scan,Fix,Work}/*', GLOB_ONLYDIR | GLOB_BRACE))
        ->map(fn (string $folder): string => str_replace(dirname(__DIR__, 2).'/', '', $folder))
        ->sort()
        ->values()
        ->all();

    expect($folders)->toBe(['src/Fix/Security', 'src/Scan/Tools', 'src/Work/Concerns']);
});

it('names the secret patterns as the one exception, to move to data the studio serves', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Scan/Secrets.php');

    expect($source)->toContain('public const array KINDS');
});
