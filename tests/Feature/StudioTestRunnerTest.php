<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\StudioChecks;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Work\StudioTestRunner;

/*
|--------------------------------------------------------------------------
| The tests of a project, for a work or build run
|--------------------------------------------------------------------------
|
| The studio says which tests cover some files and which test to follow; this
| machine only sends the files it asks for and runs what it names.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-test-runner-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/tests/Feature', 0755, true);
    mkdir($this->root.'/vendor/bin', 0755, true);
    file_put_contents($this->root.'/tests/Feature/BillingTest.php', "<?php\nit('adds up', fn () => expect(1)->toBe(1));\n");
    file_put_contents($this->root.'/tests/Feature/HugeTest.php', "<?php\n// ".str_repeat('x', 30_000)."\n");
    $this->asked = [];
    $this->runner = function (array $answers) {
        app()->instance(Studio::class, Mockery::mock(Studio::class, function ($studio) use ($answers): void {
            $studio->shouldReceive('askScanQuestion')->andReturnUsing(function (string $question, array $sent) use ($answers): ?array {
                $this->asked[] = [$question, $sent];

                return $answers[$question] ?? null;
            });
        }));

        return new StudioTestRunner($this->root, new StudioChecks($this->root, app(Studio::class)), new Toolbox($this->root));
    };
});

it('asks the studio which tests cover the files, keeps only well-formed paths, and asks once for the same files', function (): void {
    $runner = ($this->runner)(['covering-tests' => ['tests' => ['tests/Feature/BillingTest.php', '../outside/Test.php', '/etc/passwd', 'tests/notes.txt', 42]]]);

    expect($runner->for(['app/Billing.php']))->toBe(['tests/Feature/BillingTest.php'])
        ->and($runner->for(['app/Billing.php']))->toBe(['tests/Feature/BillingTest.php'])
        ->and($this->asked)->toHaveCount(1)
        ->and($this->asked[0][0])->toBe('covering-tests')
        ->and($this->asked[0][1]['about'])->toBe(['app/Billing.php']);
});

it('covers nothing, and does not remember it, when the studio cannot be reached', function (): void {
    $runner = ($this->runner)([]);

    expect($runner->for(['app/Billing.php']))->toBe([])
        ->and($runner->for(['app/Billing.php']))->toBe([])
        ->and($this->asked)->toHaveCount(2);
});

it('takes the first test the studio names that it may send and that is small enough to follow', function (): void {
    $runner = ($this->runner)(['test-example' => ['examples' => ['tests/Feature/HugeTest.php', 'tests/Feature/MissingTest.php', 'tests/Feature/BillingTest.php']]]);

    expect($runner->example(['app/Billing.php']))->toBe(['path' => 'tests/Feature/BillingTest.php', 'content' => "<?php\nit('adds up', fn () => expect(1)->toBe(1));\n"]);
});

it('follows no test when the studio names none', function (): void {
    expect(($this->runner)(['test-example' => ['examples' => []]])->example(['app/Billing.php']))->toBeNull();
});

it('cannot run the tests when the project has no Pest or PHPUnit, and names none to run', function (): void {
    $runner = ($this->runner)([]);

    expect($runner->failing(['tests/Feature/BillingTest.php']))->toBeNull()
        ->and($runner->failures([]))->toBeNull()
        ->and(($runner->failingLater(['tests/Feature/BillingTest.php']))())->toBeNull();
});

it('reads which tests failed, with what they said, from the run it started', function (): void {
    $report = <<<'XML'
        <?xml version="1.0"?>
        <testsuites><testsuite name="studio">
            <testcase name="adds up" file="ROOT/tests/Feature/BillingTest.php::adds up"/>
            <testcase name="rounds" file="ROOT/tests/Feature/BillingTest.php::rounds"><failure>Expected 2, got 3.</failure></testcase>
        </testsuite></testsuites>
        XML;
    file_put_contents($this->root.'/report.xml', str_replace('ROOT', $this->root, $report));
    file_put_contents($this->root.'/vendor/bin/pest', "#!/bin/sh\nwhile [ \"\$1\" != \"--log-junit\" ]; do shift; done\ncp ".$this->root."/report.xml \"\$2\"\nexit 1\n");
    chmod($this->root.'/vendor/bin/pest', 0755);

    $runner = ($this->runner)([]);

    expect($runner->failures(['tests/Feature/BillingTest.php']))->toBe(['tests/Feature/BillingTest.php › rounds' => 'Expected 2, got 3.'])
        ->and($runner->failing(['tests/Feature/BillingTest.php']))->toBe(['tests/Feature/BillingTest.php › rounds'])
        ->and(($runner->failingLater(['tests/Feature/BillingTest.php']))())->toBe(['tests/Feature/BillingTest.php › rounds']);
});
