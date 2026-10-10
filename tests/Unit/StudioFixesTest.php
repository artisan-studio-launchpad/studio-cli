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
    collect(range(1, 151))->each(fn (int $number): int|false => ($this->write)("app/F{$number}.php", "<?php\n\nclass F{$number}\n{\n}\n"));
    $this->answers[] = ['files' => [['path' => 'app/F1.php', 'content' => base64_encode('one'), 'state' => 'S1']]];
    $this->answers[] = ['needs' => ['read' => ['app/F1.php'], 'search' => []]];
    $this->answers[] = fn (array $batch): array => ['files' => [['path' => 'app/F1.php', 'content' => base64_encode('two'), 'state' => 'S2']]];

    $made = ($this->fixes)()->fix(['pest-arch' => collect(range(1, 151))->map(fn (int $number): array => ($this->finding)("app/F{$number}.php:3"))->all()], sweep: true);
    $carried = collect($this->sent[2]['files'])->firstWhere('path', 'app/F1.php');

    expect($this->sent)->toHaveCount(3)
        ->and($this->sent[0]['files'])->toHaveCount(150)
        ->and(($this->paths)($this->sent[1]))->toBe(['app/F151.php'])
        ->and([$this->sent[0]['sweep'], $this->sent[1]['sweep']])->toBe([true, false])
        ->and($carried['state'])->toBe('S1')
        ->and(base64_decode($carried['content']))->toBe("<?php\n\nclass F1\n{\n}\n")
        ->and($made['saved'])->toBe(['app/F1.php'])
        ->and(($this->read)('app/F1.php'))->toBe('two');
});

it('leaves the files as they were when the studio cannot be reached, and says so', function (): void {
    ($this->write)('app/A.php', "<?php\n\nclass A\n{\n}\n");
    $this->answers[] = MockResponse::make(['message' => 'Server Error'], 500);

    $made = ($this->fixes)()->fix(['phpstan' => [($this->finding)('app/A.php:3')]]);

    expect($made)->toMatchArray(['failed' => 1, 'saved' => [], 'fixed' => []])
        ->and(($this->read)('app/A.php'))->toBe("<?php\n\nclass A\n{\n}\n");
});
