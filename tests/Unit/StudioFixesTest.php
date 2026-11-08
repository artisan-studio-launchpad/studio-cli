<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\StudioFixes;
use ArtisanStudio\StudioCli\Saloon\Requests\FixScanFilesRequest;
use ArtisanStudio\StudioCli\Studio;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| The studio's own fixes, made in the studio
|--------------------------------------------------------------------------
|
| The terminal only reads and sends: each flagged file goes up once with its
| findings, what the fixers need is read or searched here, and the changed
| files are written once at the end. Never a vendor file, never a secret.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-fixes-sent-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Concerns', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '^8.4'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $this->write = fn (string $path, string $code): int|false => file_put_contents($this->root.'/'.$path, $code);
    $this->read = fn (string $path): string => (string) file_get_contents($this->root.'/'.$path);
    $this->finding = fn (string $where): array => ['where' => $where, 'rule' => 'property.notFound', 'message' => 'x'];
    $this->sent = [];
    $this->answers = [];
    $this->paths = fn (array $batch): array => array_column($batch['files'], 'path');

    Saloon::fake([FixScanFilesRequest::class => function (PendingRequest $pending): MockResponse {
        $batch = json_decode((string) json_encode($pending->body()?->all()), true);
        $this->sent[] = $batch;
        $answer = array_shift($this->answers);

        return $answer instanceof MockResponse ? $answer : MockResponse::make([
            'fixed' => [],
            'swept' => [],
            'generics' => ['label' => 'generic return types written', 'written' => 0],
            'files' => [],
            'needs' => ['read' => [], 'search' => []],
            ...(is_callable($answer) ? $answer($batch) : (array) $answer),
        ]);
    }]);

    $this->fixes = fn (): StudioFixes => new StudioFixes($this->root, app(Studio::class));
});

it('sends each flagged file once with its findings and how the project autoloads, and writes what comes back once', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    ($this->write)('app/B.php', "<?php\n\nclass B\n{\n}\n");
    $this->answers[] = [
        'fixed' => [['key' => 'phpstan', 'label' => 'computed properties described', 'path' => 'app/A.php', 'finding' => ($this->finding)('app/A.php:3')]],
        'files' => [['path' => 'app/A.php', 'content' => base64_encode("<?php\n\n/** fixed */\nclass A\n{\n}\n"), 'state' => 'S1']],
    ];
    $told = [];

    $made = ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3'), ($this->finding)('app/B.php:3'), ($this->finding)('app/B.php:4')]], onFixed: function (string $key, string $path) use (&$told): void {
        $told[] = $key.' '.$path;
    });

    expect($this->sent)->toHaveCount(1)
        ->and(($this->paths)($this->sent[0]))->toBe(['app/A.php', 'app/B.php'])
        ->and(base64_decode($this->sent[0]['files'][1]['content']))->toBe("<?php\n\nclass B\n{\n}\n")
        ->and($this->sent[0]['findings']['phpstan'])->toHaveCount(3)
        ->and($this->sent[0]['php'])->toBe('^8.4')
        ->and($this->sent[0]['autoload'])->toBe(['App\\' => ['app/']])
        ->and($this->sent[0]['sweep'])->toBeFalse()
        ->and($made['saved'])->toBe(['app/A.php'])
        ->and($made['fixed'])->toHaveCount(1)
        ->and($made['requests'])->toBe(1)
        ->and($told)->toBe(['phpstan app/A.php'])
        ->and(($this->read)('app/A.php'))->toContain('/** fixed */')
        ->and(($this->read)('app/B.php'))->toBe("<?php\n\nclass B\n{\n}\n");
});

it('reads and searches what the fixers need on this machine, then sends the batch again with it', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    ($this->write)('app/Concerns/HasTotal.php', "<?php\n\ntrait HasTotal\n{\n}\n");
    ($this->write)('app/C.php', "<?php\n\nclass C\n{\n    use HasTotal;\n}\n");
    $this->answers[] = ['needs' => [
        'read' => ['app/Concerns/HasTotal.php', 'app/Missing.php'],
        'search' => [['id' => 'users', 'pattern' => '/use\s+HasTotal\b/', 'in' => ['app'], 'ext' => ['php'], 'read' => true]],
    ]];
    $this->answers[] = ['fixed' => [['key' => 'phpstan', 'label' => 'x', 'path' => 'app/A.php', 'finding' => ($this->finding)('app/A.php:3')]]];

    $made = ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3')]]);

    expect($this->sent)->toHaveCount(2)
        ->and(($this->paths)($this->sent[1]))->toBe(['app/A.php', 'app/C.php', 'app/Concerns/HasTotal.php'])
        ->and($this->sent[1]['missing'])->toBe(['app/Missing.php'])
        ->and($this->sent[1]['found'])->toBe(['users' => ['app/C.php']])
        ->and($made['fixed'])->toHaveCount(1);
});

it('never sends a vendor file, an env file, a file holding a secret, or anything outside the project', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    ($this->write)('app/Keys.php', "<?php\n\nclass Keys\n{\n    const AWS = 'AKIAABCDEFGHIJKLMNOP';\n}\n");
    mkdir($this->root.'/vendor/acme', 0755, true);
    ($this->write)('vendor/acme/Thing.php', "<?php\n\nclass Thing\n{\n}\n");
    $this->answers[] = ['needs' => [
        'read' => ['vendor/acme/Thing.php', '../outside.php', 'app/Keys.php', '/etc/hosts.php'],
        'search' => [['id' => 'keys', 'pattern' => '/class/', 'in' => ['app', 'vendor', '..'], 'ext' => ['php'], 'read' => true]],
    ]];

    ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3'), ($this->finding)('app/Keys.php:5')]]);

    $everything = collect($this->sent)->flatMap(fn (array $batch): array => ($this->paths)($batch))->unique()->values()->all();

    expect($everything)->toBe(['app/A.php'])
        ->and($this->sent[1]['found'])->toBe(['keys' => ['app/A.php']])
        ->and($this->sent[1]['missing'])->toContain('vendor/acme/Thing.php', '../outside.php', 'app/Keys.php', '/etc/hosts.php');
});

it('carries a file\'s edits from an earlier batch back with it, sweeps only once, and writes it once at the end', function (): void {
    $most = StudioFixes::MOST_FILES;
    $name = fn (int $number): string => sprintf('app/F%03d.php', $number);
    collect(range(1, $most + 1))->each(fn (int $number): int|false => ($this->write)($name($number), "<?php\n\nclass F{$number}\n{\n}\n"));
    $this->answers[] = ['files' => [['path' => $name(1), 'content' => base64_encode('one'), 'state' => 'S1']]];
    $this->answers[] = ['needs' => ['read' => [$name(1)], 'search' => []]];
    $this->answers[] = fn (array $batch): array => ['files' => [['path' => $name(1), 'content' => base64_encode('two'), 'state' => 'S2']]];

    $made = ($this->fixes)()->fix(['pest-arch' => collect(range(1, $most + 1))->map(fn (int $number): array => ($this->finding)($name($number).':3'))->all()], sweep: true);
    $carried = collect($this->sent[2]['files'])->firstWhere('path', $name(1));

    expect($this->sent)->toHaveCount(3)
        ->and($this->sent[0]['files'])->toHaveCount($most)
        ->and(($this->paths)($this->sent[1]))->toBe([$name($most + 1)])
        ->and([$this->sent[0]['sweep'], $this->sent[1]['sweep']])->toBe([true, false])
        ->and($carried['state'])->toBe('S1')
        ->and(base64_decode($carried['content']))->toBe("<?php\n\nclass F1\n{\n}\n")
        ->and($made['saved'])->toBe([$name(1)])
        ->and(($this->read)($name(1)))->toBe('two');
});

it('leaves the files as they were when the studio cannot be reached, and says so', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    array_push($this->answers, ...array_fill(0, 3, MockResponse::make(['message' => 'Server Error'], 500)));

    $made = ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3')]]);

    expect($made)->toMatchArray(['failed' => 1, 'saved' => [], 'fixed' => []])
        ->and($this->sent)->toHaveCount(3)
        ->and(($this->read)('app/A.php'))->toBe("<?php\n\nclass A\n{\n}\n");
});

it('asks again when the studio answers with a server error, such as a deadlock, and carries on when it recovers', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    $this->answers[] = MockResponse::make(['message' => 'SQLSTATE[40001]: Deadlock found'], 500);

    $made = ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3')]]);

    expect($made['failed'])->toBe(0)
        ->and($this->sent)->toHaveCount(2);
});

it('tells how the run goes: the batches planned, each round, a retry for what the fixers asked, and the batch done', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    ($this->write)('app/B.php', "<?php\n\nclass B\n{\n}\n");
    $this->answers[] = ['needs' => ['read' => ['app/B.php'], 'search' => []]];
    $events = [];

    ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3')]], onEvent: function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect(array_column($events, 'type'))->toBe(['planned', 'round', 'retry', 'round', 'batch'])
        ->and($events[0])->toMatchArray(['batches' => 1, 'files' => 1])
        ->and($events[2])->toMatchArray(['batch' => 1, 'round' => 1, 'reads' => 1, 'searches' => 0, 'sent' => 1])
        ->and($events[4])->toMatchArray(['batch' => 1, 'ok' => true]);
});

it('says why a batch got no answer', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    array_push($this->answers, ...array_fill(0, 3, MockResponse::make('The server fell over', 500)));
    $events = [];

    $made = ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3')]], onEvent: function (array $event) use (&$events): void {
        $events[] = $event;
    });

    $failed = collect($events)->firstWhere('type', 'failed');

    expect($made['failed'])->toBe(1)
        ->and($failed['reason'])->toContain('HTTP 500')->toContain('The server fell over')
        ->and(collect($events)->last())->toMatchArray(['type' => 'batch', 'ok' => false]);
});

it('keeps a batch whose last round asks for nothing more', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    array_map(fn (string $name): int|false => ($this->write)('app/'.$name.'.php', "<?php\n\nclass ".$name."\n{\n}\n"), ['B', 'C', 'D']);
    $ask = fn (string $name): array => ['needs' => ['read' => ['app/'.$name.'.php'], 'search' => []]];
    $this->answers = [$ask('B'), $ask('C'), $ask('D'), ['fixed' => [['key' => 'phpstan', 'label' => 'x', 'path' => 'app/A.php', 'finding' => ($this->finding)('app/A.php:3')]]]];

    $made = ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3')]]);

    expect($made['failed'])->toBe(0)
        ->and($made['requests'])->toBe(4)
        ->and($made['fixed'])->toHaveCount(1);
});

it('sends again only the findings that waited, keeps what the first round fixed, and tells the studio what is done', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    ($this->write)('app/B.php', "<?php\n\nclass B\n{\n}\n");
    ($this->write)('app/C.php', "<?php\n\nclass C\n{\n}\n");
    $a = ($this->finding)('app/A.php:3');
    $b = ($this->finding)('app/B.php:3');
    $this->answers[] = [
        'fixed' => [['key' => 'phpstan', 'label' => 'x', 'path' => 'app/A.php', 'finding' => $a]],
        'files' => [['path' => 'app/A.php', 'content' => base64_encode('fixed A'), 'state' => 'SA']],
        'unresolved' => [['key' => 'phpstan', ...$b]],
        'needs' => ['read' => ['app/C.php'], 'search' => []],
        'timing' => ['engine_ms' => 40, 'parsing_ms' => 25, 'phases' => ['fix' => 30], 'rules' => [['key' => 'phpstan', 'rule' => 'property.notFound', 'ms' => 30, 'findings' => 2, 'fixed' => 1, 'asked' => 1]], 'fixers' => [['fixer' => 'ExampleFixer', 'ms' => 30, 'calls' => 2, 'fixed' => 1]]],
    ];
    $this->answers[] = [
        'fixed' => [['key' => 'phpstan', 'label' => 'y', 'path' => 'app/B.php', 'finding' => $b]],
        'files' => [['path' => 'app/B.php', 'content' => base64_encode('fixed B'), 'state' => 'SB']],
        'unresolved' => [],
        'timing' => ['engine_ms' => 10, 'parsing_ms' => 5, 'phases' => ['fix' => 8], 'rules' => [['key' => 'phpstan', 'rule' => 'property.notFound', 'ms' => 8, 'findings' => 1, 'fixed' => 1, 'asked' => 0]], 'fixers' => [['fixer' => 'ExampleFixer', 'ms' => 8, 'calls' => 1, 'fixed' => 1]]],
    ];
    $events = [];

    $made = ($this->fixes)()->fix(['phpstan' => [$a, $b]], onEvent: function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect($this->sent)->toHaveCount(2)
        ->and(($this->paths)($this->sent[0]))->toBe(['app/A.php', 'app/B.php'])
        ->and(($this->paths)($this->sent[1]))->toBe(['app/B.php', 'app/C.php'])
        ->and($this->sent[1]['findings']['phpstan'])->toBe([['where' => 'app/B.php:3', 'rule' => 'property.notFound', 'message' => 'x']])
        ->and($this->sent[1]['done'])->toBe([['key' => 'phpstan', 'rule' => 'property.notFound', 'where' => 'app/A.php:3']])
        ->and($made['fixed'])->toHaveCount(2)
        ->and($made['saved'])->toEqualCanonicalizing(['app/A.php', 'app/B.php'])
        ->and(($this->read)('app/A.php'))->toBe('fixed A')
        ->and(($this->read)('app/B.php'))->toBe('fixed B')
        ->and(collect($events)->firstWhere('type', 'retry'))->toMatchArray(['reads' => 1, 'findings' => 1])
        ->and($made['timings'])->toMatchArray(['parsing_ms' => 30, 'phases' => ['fix' => 38]])
        ->and($made['timings']['rules'])->toHaveCount(1)
        ->and($made['timings']['rules'][0])->toMatchArray(['rule' => 'property.notFound', 'ms' => 38, 'findings' => 2, 'fixed' => 2, 'asked' => 1])
        ->and($made['timings']['fixers'][0])->toMatchArray(['fixer' => 'ExampleFixer', 'ms' => 38, 'calls' => 3, 'fixed' => 2]);
});

it('keeps what the earlier rounds fixed and says so when findings still wait after the last round', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    ($this->write)('app/B.php', "<?php\n\nclass B\n{\n}\n");
    array_map(fn (string $name): int|false => ($this->write)('app/'.$name.'.php', "<?php\n\nclass ".$name."\n{\n}\n"), ['C', 'D', 'E']);
    $a = ($this->finding)('app/A.php:3');
    $b = ($this->finding)('app/B.php:3');
    $waiting = fn (string $next): array => ['unresolved' => [['key' => 'phpstan', ...$b]], 'needs' => ['read' => ['app/'.$next.'.php'], 'search' => []]];
    $this->answers = [
        [...$waiting('C'), 'fixed' => [['key' => 'phpstan', 'label' => 'x', 'path' => 'app/A.php', 'finding' => $a]], 'files' => [['path' => 'app/A.php', 'content' => base64_encode('fixed A'), 'state' => 'SA']]],
        $waiting('D'),
        $waiting('E'),
        $waiting('F'),
    ];
    $events = [];

    $made = ($this->fixes)()->fix(['phpstan' => [$a, $b]], onEvent: function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect($made['requests'])->toBe(4)
        ->and($made['failed'])->toBe(0)
        ->and($made['fixed'])->toHaveCount(1)
        ->and($made['saved'])->toBe(['app/A.php'])
        ->and(collect($events)->firstWhere('type', 'unfinished'))->toMatchArray(['batch' => 1, 'round' => 4, 'findings' => 1])
        ->and(collect($events)->last())->toMatchArray(['type' => 'batch', 'fixes' => 1, 'ok' => true]);
});

it('keeps the files of a failed later round out of the way: what an earlier round fixed is still written', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    ($this->write)('app/B.php', "<?php\n\nclass B\n{\n}\n");
    ($this->write)('app/C.php', "<?php\n\nclass C\n{\n}\n");
    $a = ($this->finding)('app/A.php:3');
    $b = ($this->finding)('app/B.php:3');
    $this->answers[] = [
        'fixed' => [['key' => 'phpstan', 'label' => 'x', 'path' => 'app/A.php', 'finding' => $a]],
        'files' => [['path' => 'app/A.php', 'content' => base64_encode('fixed A'), 'state' => 'SA']],
        'unresolved' => [['key' => 'phpstan', ...$b]],
        'needs' => ['read' => ['app/C.php'], 'search' => []],
    ];
    array_push($this->answers, ...array_fill(0, 3, MockResponse::make('The server fell over', 500)));

    $made = ($this->fixes)()->fix(['phpstan' => [$a, $b]]);

    expect($made['failed'])->toBe(0)
        ->and($made['saved'])->toBe(['app/A.php'])
        ->and(($this->read)('app/A.php'))->toBe('fixed A');
});

it('sends the autoload of every module a modular project includes, with the folder each sits in', function (): void {
    mkdir($this->root.'/plugins/System/Core/app', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'extra' => ['merge-plugin' => ['include' => ['plugins/*/*/composer.json']]],
    ]));
    file_put_contents($this->root.'/plugins/System/Core/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['Plugins\\Core\\' => 'app/', 'Plugins\\Core\\Database\\Seeders\\' => 'database/seeders/']]]));
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");

    ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3')]]);

    expect($this->sent[0]['autoload'])->toBe([
        'App\\' => ['app/'],
        'Plugins\\Core\\' => ['plugins/System/Core/app/'],
        'Plugins\\Core\\Database\\Seeders\\' => ['plugins/System/Core/database/seeders/'],
    ]);
});
