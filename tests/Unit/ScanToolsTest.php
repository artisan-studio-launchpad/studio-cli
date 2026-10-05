<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\Load;
use ArtisanStudio\StudioCli\Scan\Tools\ComposerAudit;
use ArtisanStudio\StudioCli\Scan\Tools\DefinedTool;
use ArtisanStudio\StudioCli\Scan\Tools\NodeAudit;
use ArtisanStudio\StudioCli\Scan\Tools\PestArch;
use ArtisanStudio\StudioCli\Scan\Tools\PhpStan;
use ArtisanStudio\StudioCli\Scan\Tools\Pint;
use ArtisanStudio\StudioCli\Scan\Tools\Rector;
use ArtisanStudio\StudioCli\Scan\Tools\Tests;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\Tools\TypeCoverage;
use ArtisanStudio\StudioCli\Studio;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-tools-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
});

it('counts the PHP files PHPStan reads under the project\'s own paths, and how many it found something in', function (): void {
    mkdir($this->root.'/app/Models', 0755, true);
    mkdir($this->root.'/src', 0755, true);
    array_map(fn (string $path): int|false => file_put_contents($this->root.'/'.$path, '<?php'), ['app/A.php', 'app/Models/B.php', 'app/notes.txt', 'src/C.php', 'src/D.php']);
    file_put_contents($this->root.'/phpstan.neon', "parameters:\n    paths:\n        - app\n        - src/C.php\n    level: 5\n");
    $output = (string) json_encode(['totals' => ['errors' => 0, 'file_errors' => 3], 'files' => [
        $this->root.'/app/A.php' => ['messages' => [['line' => 3, 'message' => 'x', 'identifier' => 'property.notFound'], ['line' => 9, 'message' => 'y', 'identifier' => 'return.type']]],
        $this->root.'/app/A.php (in context of class App\B)' => ['messages' => [['line' => 4, 'message' => 'z', 'identifier' => 'method.notFound']]],
    ]]);

    $phpstan = new PhpStan;

    expect($phpstan->findings($output, $this->root))->toHaveCount(3)
        ->and($phpstan->summary())->toBe(['files' => 3, 'flagged' => 1]);
});

it('reads Pint\'s findings as file and rule, in either of its JSON shapes', function (): void {
    $list = json_encode(['result' => 'fail', 'files' => [['path' => $this->root.'/app/Order.php', 'fixers' => ['ordered_imports', 'single_quote']]]]);
    $keyed = json_encode(['files' => ['app/Order.php' => ['appliedFixers' => ['ordered_imports']]]]);

    expect((new Pint)->findings((string) $list, $this->root))->toBe([
        ['where' => 'app/Order.php', 'rule' => 'ordered_imports', 'message' => 'Fails Pint\'s ordered_imports rule.'],
        ['where' => 'app/Order.php', 'rule' => 'single_quote', 'message' => 'Fails Pint\'s single_quote rule.'],
    ])
        ->and((new Pint)->findings((string) $keyed, $this->root))->toHaveCount(1)
        ->and((new Pint)->findings('Something went wrong', $this->root))->toBeNull();
});

it('runs Pint in parallel only when the project\'s Pint offers it', function (string $help, array $command): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    file_put_contents($this->root.'/vendor/bin/pint', "#!/bin/sh\necho '".$help."'\n");
    chmod($this->root.'/vendor/bin/pint', 0755);

    expect((new Pint)->command($this->root))->toBe($command);
})->with([
    'a Pint with parallel runs' => ['  -p, --parallel  Runs the linter in parallel', ['vendor/bin/pint', '--test', '--format=json', '-v', '--parallel']],
    'an older Pint' => ['  --test  Test for code style errors without fixing them', ['vendor/bin/pint', '--test', '--format=json', '-v']],
]);

it('never drops a file Pint fails, even when its JSON names the file but not the rules', function (): void {
    $plain = json_encode(['about' => 'PHP CS Fixer', 'files' => [['name' => 'app/Policies/UserPolicy.php'], ['name' => 'app/Jobs/KeyAvatarVideo.php']]]);
    $verbose = json_encode(['files' => [['name' => 'app/Policies/UserPolicy.php', 'appliedFixers' => ['ordered_imports'], 'diff' => "--- a\n+++ b\n- \$secret = 'sk_live';"]]]);

    expect((new Pint)->findings((string) $plain, $this->root))->toBe([
        ['where' => 'app/Policies/UserPolicy.php', 'rule' => 'style', 'message' => 'Fails Pint\'s style rules.'],
        ['where' => 'app/Jobs/KeyAvatarVideo.php', 'rule' => 'style', 'message' => 'Fails Pint\'s style rules.'],
    ])
        ->and(json_encode((new Pint)->findings((string) $verbose, $this->root)))->not->toContain('sk_live');
});

it('reads the test run\'s JUnit report as counts and failing tests with why, never an email or a key the failure said', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    touch($this->root.'/vendor/bin/pest');
    $tests = new Tests;
    $command = $tests->command($this->root);
    $report = $command[array_search('--log-junit', $command, true) + 1];

    file_put_contents($report, <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="Tests\Feature\OrderTest" tests="3" assertions="5" errors="0" failures="1" skipped="1" time="0.4">
            <testcase name="it lists orders" file="tests/Feature/OrderTest.php::it lists orders" class="Tests\Feature\OrderTest" assertions="2" time="0.1"/>
            <testcase name="it ships an order" file="tests/Feature/OrderTest.php::it ships an order" class="Tests\Feature\OrderTest" assertions="1" time="0.2">
              <failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that 'customer@example.com' is identical to 'AKIAABCDEFGHIJKLMNOP'.
        at tests/Feature/OrderTest.php:42</failure>
            </testcase>
            <testcase name="it refunds" file="tests/Feature/OrderTest.php::it refunds" class="Tests\Feature\OrderTest" assertions="0" time="0.1"><skipped/></testcase>
          </testsuite>
        </testsuites>
        XML);

    $findings = $tests->findings('', $this->root);

    expect($command)->toBe(['vendor/bin/pest', '--parallel', '--processes='.Load::forTests(), '--log-junit', $report, '--log-teamcity', $command[6]])
        ->and($findings)->toBe([['where' => 'tests/Feature/OrderTest.php:42', 'rule' => 'failed', 'message' => 'it ships an order failed.', 'detail' => "Failed asserting that '[an email]' is identical to '[a key]'."]])
        ->and($tests->summary())->toBe(['tests' => 3, 'failed' => 1, 'skipped' => 1])
        ->and(json_encode($findings))->not->toContain('customer@example.com')->not->toContain('AKIAABCDEFGHIJKLMNOP')
        ->and(file_exists($report))->toBeFalse();
});

it('says what a failed comparison got, which the JUnit report leaves out, so the failing entries are named', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    touch($this->root.'/vendor/bin/pest');
    $tests = new Tests;
    $command = $tests->command($this->root);
    $report = $command[array_search('--log-junit', $command, true) + 1];
    $compared = $command[array_search('--log-teamcity', $command, true) + 1];

    file_put_contents($report, <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="Tests\Feature\SeedTest" tests="1" assertions="1" errors="0" failures="1" skipped="0" time="0.4">
            <testcase name="it grounds every definition" file="tests/Feature/SeedTest.php::it grounds every definition" class="Tests\Feature\SeedTest" assertions="1" time="0.1">
              <failure type="PHPUnit\Framework\ExpectationFailedException">It grounds every definitionFailed asserting that two arrays are identical.
        at tests/Feature/SeedTest.php:101</failure>
            </testcase>
          </testsuite>
        </testsuites>
        XML);
    file_put_contents($compared, "##teamcity[testFailed name='__pest_evaluable_it_grounds_every_definition' message='Failed asserting that two arrays are identical.' details='' type='comparisonFailure' actual='Array &0 |[|n    0 => |'conventions.json: convention-check-ai-calls|',|n|]' expected='Array &0 |[|]' flowId='1']\n");

    expect($tests->findings('', $this->root)[0]['detail'] ?? null)->toBe("Failed asserting that two arrays are identical. Expected: [] Got: [ 'conventions.json: convention-check-ai-calls', ]")
        ->and(file_exists($compared))->toBeFalse();
});

it('reads Pest\'s type coverage report as the untyped declarations, by file and line', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    mkdir($this->root.'/'.TypeCoverage::PLUGIN, 0755, true);
    touch($this->root.'/vendor/bin/pest');
    $coverage = new TypeCoverage;
    $command = $coverage->command($this->root);
    $report = substr((string) end($command), strlen('--type-coverage-json='));

    file_put_contents($report, (string) json_encode(['format' => 'pest', 'coverage-min' => 0, 'result' => [
        ['file' => 'app/Models/Order.php', 'uncoveredLines' => ['pa12', 'rt30'], 'uncoveredLinesIgnored' => [], 'percentage' => 90.5],
        ['file' => 'app/Models/User.php', 'uncoveredLines' => [], 'uncoveredLinesIgnored' => [], 'percentage' => 100],
    ], 'total' => 95.25]));

    expect($coverage->findings('', $this->root))->toBe([
        ['where' => 'app/Models/Order.php:12', 'rule' => 'parameter', 'message' => 'No type declared on this parameter.'],
        ['where' => 'app/Models/Order.php:30', 'rule' => 'return', 'message' => 'No type declared on this return.'],
    ])
        ->and($coverage->summary())->toBe(['coverage' => 95])
        ->and((new TypeCoverage)->command(sys_get_temp_dir()))->toBeNull();
});

it('runs Pest\'s arch presets from a git-ignored test file it removes after, as one finding per broken preset', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    mkdir($this->root.'/'.PestArch::PLUGIN, 0755, true);
    mkdir($this->root.'/storage/framework/testing', 0755, true);
    touch($this->root.'/vendor/bin/pest');
    $arch = new PestArch;

    expect($arch->isSetUp($this->root))->toBeTrue()
        ->and(is_dir($this->root.'/'.PestArch::FOLDER))->toBeFalse();

    $command = $arch->command($this->root);
    $report = $command[array_search('--log-junit', $command, true) + 1];

    expect(file_get_contents($this->root.'/'.PestArch::FOLDER.'/ArchTest.php'))->toContain("arch('php')->preset()->php();")->toContain("arch('laravel')->preset()->laravel();")->toContain("arch('security')->preset()->security();");

    file_put_contents($report, <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="ArchTest" tests="3" failures="2">
            <testcase name="php" file="storage/framework/testing/studio-arch/ArchTest.php::php"/>
            <testcase name="laravel" file="storage/framework/testing/studio-arch/ArchTest.php::laravel">
              <failure type="Pest\Arch\Exceptions\ArchExpectationFailedException">laravelExpecting 'exit' not to be used on 'App\Console\Commands\ReportCommand'.
        at app/Console/Commands/ReportCommand.php:76
        at vendor/pestphp/pest-plugin-arch/src/Blueprint.php:207</failure>
            </testcase>
            <testcase name="security" file="storage/framework/testing/studio-arch/ArchTest.php::security">
              <failure type="Pest\Arch\Exceptions\ArchExpectationFailedException">securityExpecting 'md5' not to be used on 'App\Support\Motion\Cycle'.
        at app/Support/Motion/Cycle.php:31</failure>
            </testcase>
          </testsuite>
        </testsuites>
        XML);

    expect($arch->findings('', $this->root))->toBe([
        ['where' => 'app/Console/Commands/ReportCommand.php:76', 'rule' => 'arch-laravel', 'message' => "Expecting 'exit' not to be used on 'App\\Console\\Commands\\ReportCommand'."],
        ['where' => 'app/Support/Motion/Cycle.php:31', 'rule' => 'arch-security', 'message' => "Expecting 'md5' not to be used on 'App\\Support\\Motion\\Cycle'."],
    ])
        ->and(is_dir($this->root.'/'.PestArch::FOLDER))->toBeFalse()
        ->and(file_exists($report))->toBeFalse();
});

it('measures code coverage in the same test run when asked, and lists the files tests barely reach', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    touch($this->root.'/vendor/bin/pest');
    $tests = (new class extends Tests
    {
        public function canCover(): bool
        {
            return true;
        }
    })->withCoverage();
    $command = $tests->command($this->root);
    $clover = $command[array_search('--coverage-clover', $command, true) + 1];

    file_put_contents($clover, <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage>
          <project>
            <file name="{$this->root}/app/Models/Order.php"><metrics statements="40" coveredstatements="36"/></file>
            <file name="{$this->root}/app/Jobs/Ship.php"><metrics statements="20" coveredstatements="5"/></file>
            <file name="{$this->root}/app/Mail/Receipt.php"><metrics statements="10" coveredstatements="0"/></file>
            <metrics statements="70" coveredstatements="41"/>
          </project>
        </coverage>
        XML);

    expect($tests->coverage($this->root))->toBe([
        'ran' => true,
        'findings' => [
            ['where' => 'app/Jobs/Ship.php', 'rule' => 'low-coverage', 'message' => 'Tests run 25% of this file.'],
            ['where' => 'app/Mail/Receipt.php', 'rule' => 'uncovered', 'message' => 'No test runs this file.'],
        ],
        'summary' => ['coverage' => 58],
    ])
        ->and((new Tests)->command($this->root))->not->toContain('--coverage-clover');
});

it('knows what each missing tool needs before it offers to install it', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    touch($this->root.'/vendor/bin/pint');

    $missing = (new Toolbox($this->root))->missing(['pint', 'phpstan', 'rector', 'composer-audit', 'tests']);

    expect(array_keys($missing))->toBe(['phpstan', 'rector'])
        ->and($missing['phpstan']['packages'])->toBe(['larastan/larastan'])
        ->and($missing['phpstan']['files']['phpstan.neon'])->toContain('vendor/larastan/larastan/extension.neon')
        ->and($missing['rector']['files'])->toHaveKey('rector.php');
});

it('reads PHPStan\'s messages as file, line, identifier and one plain line', function (): void {
    $output = json_encode(['totals' => ['errors' => 0, 'file_errors' => 1], 'files' => [
        $this->root.'/app/Order.php' => ['errors' => 1, 'messages' => [['message' => "Method Order::total() should return int\nbut returns string.", 'line' => 12, 'identifier' => 'return.type']]],
        $this->root.'/app/Concerns/HasTotals.php (in context of class App\Order)' => ['errors' => 1, 'messages' => [['message' => 'Undefined variable: $tax', 'line' => 7, 'identifier' => 'variable.undefined']]],
    ], 'errors' => []]);

    expect((new PhpStan)->findings((string) $output, $this->root))->toBe([
        ['where' => 'app/Order.php:12', 'rule' => 'return.type', 'message' => 'Method Order::total() should return int but returns string.'],
        ['where' => 'app/Concerns/HasTotals.php:7', 'rule' => 'variable.undefined', 'message' => 'Undefined variable: $tax'],
    ]);
});

it('reads Rector\'s changes by file and rector name, and never sends its diff', function (): void {
    $output = json_encode(['totals' => ['changed_files' => 1, 'errors' => 0], 'file_diffs' => [
        ['file' => 'app/Order.php', 'diff' => "- \$secret = 'sk_live';\n+ \$secret = env('KEY');", 'applied_rectors' => ['Rector\\Php80\\Rector\\Class_\\ClassPropertyAssignToConstructorPromotionRector']],
    ]]);

    $findings = (new Rector)->findings((string) $output, $this->root);

    expect($findings)->toBe([['where' => 'app/Order.php', 'rule' => 'ClassPropertyAssignToConstructorPromotionRector', 'message' => 'Rector would change this file with ClassPropertyAssignToConstructorPromotionRector.']])
        ->and(json_encode($findings))->not->toContain('sk_live');
});

it('reads composer audit\'s advisories and abandoned packages against the composer.json line that brings them in', function (): void {
    file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '^8.4', 'guzzlehttp/guzzle' => '^7.8', 'acme/app' => '^1.0']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($this->root.'/composer.lock', (string) json_encode(['packages' => [['name' => 'acme/app', 'require' => ['old/package' => '^1.0']], ['name' => 'old/package'], ['name' => 'guzzlehttp/guzzle']]]));
    $output = json_encode(['advisories' => ['guzzlehttp/guzzle' => [['advisoryId' => 'PKSA-1', 'cve' => 'CVE-2026-1234', 'title' => 'Cookie leakage', 'affectedVersions' => '<7.8.2', 'severity' => 'high']]], 'abandoned' => ['old/package' => 'new/package']]);

    expect((new ComposerAudit)->findings((string) $output, $this->root))->toBe([
        ['where' => 'composer.json:4', 'rule' => 'CVE-2026-1234', 'message' => 'guzzlehttp/guzzle <7.8.2: Cookie leakage (high)'],
        ['where' => 'composer.json:5', 'rule' => 'abandoned', 'message' => 'old/package is abandoned (via acme/app); use new/package instead.'],
    ]);
});

it('reads npm\'s and pnpm\'s audit shapes against the package.json line that brings each package in', function (): void {
    file_put_contents($this->root.'/pnpm-lock.yaml', '');
    file_put_contents($this->root.'/package.json', (string) json_encode(['scripts' => ['dev' => 'vite'], 'devDependencies' => ['laravel-vite-plugin' => '^2.0', 'vite' => '^7.0']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $pnpm = json_encode([
        'actions' => [['module' => 'rollup', 'resolves' => [['id' => 2002, 'path' => '.>vite>rollup']]]],
        'advisories' => [
            '1001' => ['module_name' => 'vite', 'vulnerable_versions' => '<5.4.6', 'title' => 'Path traversal', 'severity' => 'moderate', 'cves' => ['CVE-2026-9']],
            '2002' => ['module_name' => 'rollup', 'vulnerable_versions' => '<4.59.0', 'patched_versions' => '>=4.59.0', 'title' => 'File write', 'severity' => 'high', 'cves' => ['CVE-2026-10'], 'findings' => [['version' => '4.52.5', 'paths' => []]]],
        ],
    ]);
    $npm = json_encode(['vulnerabilities' => [
        'rollup' => ['severity' => 'high', 'isDirect' => false, 'range' => '<4.59.0', 'via' => [['source' => 2002, 'title' => 'File write', 'severity' => 'high']], 'effects' => ['vite']],
        'vite' => ['severity' => 'high', 'isDirect' => true, 'range' => '<7.0.0', 'via' => ['rollup'], 'effects' => []],
    ]]);

    expect((new NodeAudit)->findings((string) $pnpm, $this->root))->toBe([
        ['where' => 'package.json:7', 'rule' => 'CVE-2026-9', 'message' => 'vite <5.4.6: Path traversal (moderate)', 'patch' => ['package' => 'vite', 'patched' => '', 'versions' => []]],
        ['where' => 'package.json:7', 'rule' => 'CVE-2026-10', 'message' => 'rollup 4.52.5 via vite: File write (high)', 'patch' => ['package' => 'rollup', 'patched' => '>=4.59.0', 'versions' => ['4.52.5']]],
    ])
        ->and((new NodeAudit)->findings((string) $npm, $this->root))->toBe([
            ['where' => 'package.json:7', 'rule' => '2002', 'message' => 'rollup <4.59.0 via vite: File write (high)'],
        ]);
});

it('runs a tool the studio describes only from the project\'s own vendor/bin, once it is set up, with plain arguments', function (): void {
    $definition = ['key' => 'house-check', 'name' => 'House check', 'bin' => 'house-check', 'needs' => ['anyFile' => ['house.xml']], 'args' => ['--format=json', '--threads={workers}'], 'fix' => ['--fix'], 'pathPrefix' => '--path='];
    DefinedTool::keep($this->root, [
        $definition,
        [...$definition, 'key' => 'escapes', 'bin' => '../../bin/sh'],
        [...$definition, 'key' => 'reaches-out', 'args' => ['--config=../../elsewhere']],
        [...$definition, 'key' => 'any-command', 'bin' => 'artisan', 'args' => ['migrate:fresh', '--force']],
    ]);
    $toolbox = new Toolbox($this->root);
    $tool = $toolbox->tool('house-check');

    expect(array_map(fn (DefinedTool $tool): string => $tool->key(), $toolbox->defined()))->toBe(['house-check', 'any-command'])
        ->and($tool->command($this->root))->toBeNull()
        ->and($toolbox->tool('any-command')->command($this->root))->toBeNull();

    mkdir($this->root.'/vendor/bin', 0755, true);
    touch($this->root.'/vendor/bin/house-check');
    touch($this->root.'/house.xml');
    file_put_contents($this->root.'/artisan', '<?php');

    expect($tool->command($this->root))->toBe(['vendor/bin/house-check', '--format=json', '--threads='.Load::workers()])
        ->and($tool->fixCommand($this->root))->toBe(['vendor/bin/house-check', '--fix'])
        ->and($tool->pathArguments(['app/A.php']))->toBe(['--path=app/A.php'])
        ->and($toolbox->tool('any-command')->command($this->root))->toBeNull();
});

it('sends the studio only the keys or lines it reads, without the project\'s path, and takes back what it read as findings', function (): void {
    DefinedTool::keep($this->root, [
        ['key' => 'json-check', 'name' => 'JSON check', 'bin' => 'json-check', 'output' => ['kind' => 'json', 'keep' => ['file_name', 'line_from', 'message']]],
        ['key' => 'line-check', 'name' => 'Line check', 'bin' => 'line-check', 'output' => ['kind' => 'lines', 'lines' => '/^Issue /']],
    ]);
    $toolbox = new Toolbox($this->root);
    $sent = [];
    app()->instance(Studio::class, Mockery::mock(Studio::class, function ($studio) use (&$sent): void {
        $studio->shouldReceive('readToolOutput')->andReturnUsing(function (string $key, array $read) use (&$sent): array {
            $sent[$key] = $read['output'];

            return ['findings' => [['where' => 'app/Orders.php:12', 'rule' => 'UndefinedMethod', 'message' => 'Method total does not exist']], 'summary' => ['files' => 4, 'flagged' => 1]];
        });
    }));

    $found = $toolbox->tool('json-check')->findings('Found 1'."\n".json_encode([['file_name' => $this->root.'/app/Orders.php', 'line_from' => 12, 'message' => 'Method total does not exist', 'snippet' => '$order->total()']]), $this->root);
    $toolbox->tool('line-check')->findings("Issue in {$this->root}/app/A.php\n  12 | \$secret = 'x';\nIssue in app/B.php", $this->root);

    expect($found)->toBe([['where' => 'app/Orders.php:12', 'rule' => 'UndefinedMethod', 'message' => 'Method total does not exist']])
        ->and($toolbox->tool('json-check')->summary())->toBe(['files' => 4, 'flagged' => 1])
        ->and($sent['json-check'])->toBe('[{"file_name":"app/Orders.php","line_from":12,"message":"Method total does not exist"}]')
        ->and($sent['line-check'])->toBe("Issue in app/A.php\nIssue in app/B.php");
});

it('sends what the studio needs to tell whether a pnpm advisory needs a major update, and marks one npm says does', function (): void {
    file_put_contents($this->root.'/pnpm-lock.yaml', '');
    $pnpm = json_encode(['advisories' => [
        '1' => ['module_name' => 'postcss-selector-parser', 'vulnerable_versions' => '<7.1.6', 'patched_versions' => '>=7.1.6', 'title' => 'ReDoS', 'severity' => 'high', 'cves' => ['CVE-1'], 'findings' => [['version' => '6.0.10']]],
        '2' => ['module_name' => 'axios', 'vulnerable_versions' => '<1.15.0', 'patched_versions' => '>=1.15.0', 'title' => 'SSRF', 'severity' => 'high', 'cves' => ['CVE-2'], 'findings' => [['version' => '1.12.2']]],
    ]]);
    $npm = json_encode(['vulnerabilities' => ['vite' => ['severity' => 'moderate', 'range' => '<6.0.0', 'via' => [['source' => 1, 'title' => 'x']], 'fixAvailable' => ['name' => 'vite', 'version' => '6.0.0', 'isSemVerMajor' => true]]]]);

    $found = (new NodeAudit)->findings((string) $pnpm, $this->root);

    expect($found[0])->toMatchArray(['rule' => 'CVE-1', 'patch' => ['package' => 'postcss-selector-parser', 'patched' => '>=7.1.6', 'versions' => ['6.0.10']]])
        ->and($found[1])->toMatchArray(['rule' => 'CVE-2', 'patch' => ['package' => 'axios', 'patched' => '>=1.15.0', 'versions' => ['1.12.2']]])
        ->and($found[0])->not->toHaveKey('major')
        ->and((new NodeAudit)->findings((string) $npm, $this->root)[0])->toMatchArray(['major' => true]);
});

it('leaves PHPStan out until the project says what to analyse in a phpstan.neon', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    touch($this->root.'/vendor/bin/phpstan');

    expect((new PhpStan)->command($this->root))->toBeNull()
        ->and((new Toolbox($this->root))->run(['phpstan'])['phpstan'])->toBe(['ran' => false, 'reason' => 'Not set up in this project: it needs PHPStan or Larastan and a phpstan.neon.']);

    touch($this->root.'/phpstan.neon.dist');

    expect((new PhpStan)->command($this->root))->toBe(['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress', '--no-interaction', '--memory-limit=2G']);
});

it('says when each tool starts and when it is done, one at a time, so the studio can follow them', function (): void {
    $seen = [];

    (new Toolbox($this->root))->run(
        ['pint', 'composer-audit'],
        function (string $key) use (&$seen): void {
            $seen[] = 'done '.$key;
        },
        function (string $key) use (&$seen): void {
            $seen[] = 'start '.$key;
        },
    );

    expect($seen)->toBe(['start pint', 'done pint', 'start composer-audit', 'done composer-audit']);
});

it('says why a tool was not checked rather than leaving a gap that looks like a pass', function (): void {
    DefinedTool::keep($this->root, [['key' => 'spelling', 'name' => 'Spelling', 'bin' => 'spelling', 'needs' => ['machine' => ['no-such-speller']], 'args' => [], 'missing' => 'Not set up in this project.', 'machineMissing' => 'It needs no-such-speller on this machine.']]);
    $results = (new Toolbox($this->root))->run(['pint', 'composer-audit', 'spelling', 'not-a-tool']);

    expect($results)->toMatchArray([
        'pint' => ['ran' => false, 'reason' => 'Not installed in this project.'],
        'composer-audit' => ['ran' => false, 'reason' => 'This project has no composer.lock.'],
        'spelling' => ['ran' => false, 'reason' => 'It needs no-such-speller on this machine.'],
        'not-a-tool' => ['ran' => false, 'reason' => 'This version of the studio CLI cannot run it yet.'],
    ]);
});

it('runs every tool in plain output, never in an AI agent\'s compact format', function (): void {
    putenv('CLAUDECODE=1');

    $environment = (new Toolbox($this->root))->environment();

    putenv('CLAUDECODE');

    expect($environment)->toMatchArray(['CLAUDECODE' => false, 'PAO_DISABLE' => '1']);
});

it('never hands the project\'s own .env to a tool, so its tests run on their phpunit.xml settings, not the real database, queues or keys', function (): void {
    file_put_contents($this->root.'/.env', "APP_ENV=local\nDB_CONNECTION=mysql\n# a comment\nexport QUEUE_CONNECTION=redis\nSTRIPE_SECRET=\n");
    putenv('DB_CONNECTION=mysql');

    $environment = (new Toolbox($this->root))->environment();

    putenv('DB_CONNECTION');

    expect($environment)->toMatchArray(['APP_ENV' => false, 'DB_CONNECTION' => false, 'QUEUE_CONNECTION' => false, 'STRIPE_SECRET' => false, 'XDEBUG_MODE' => 'off']);
});

it('sends the packages in composer.lock as names and versions only', function (): void {
    file_put_contents($this->root.'/composer.lock', (string) json_encode([
        'packages' => [['name' => 'laravel/framework', 'version' => 'v12.30.0', 'dist' => ['url' => 'https://example.test/secret-token']]],
        'packages-dev' => [['name' => 'laravel/pint', 'version' => 'v1.24.0']],
    ]));

    expect((new Toolbox($this->root))->packages())->toBe(['laravel/framework' => 'v12.30.0', 'laravel/pint' => 'v1.24.0']);
});
