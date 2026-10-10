<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Saloon\Requests\FixScanFilesRequest;
use ArtisanStudio\StudioCli\Scan\LastFindings;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-fixes-more-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Concerns', 0755, true);
    file_put_contents($this->root.'/composer.json', (string) json_encode(['require' => ['php' => '^8.4'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    $this->write = fn (string $path, string $code): int|false => file_put_contents($this->root.'/'.$path, $code);
    $this->read = fn (string $path): string => (string) file_get_contents($this->root.'/'.$path);
});

it('fixes at the scan\'s lines first, then lets Rector and Pint, which need no lines, run whole', function (): void {
    mkdir($this->root.'/vendor/bin', 0755, true);
    array_map(fn (string $bin): bool => touch($this->root.'/vendor/bin/'.$bin), ['rector', 'pint']);
    touch($this->root.'/rector.php');

    expect((new AutomatedFixes(new Toolbox($this->root), $this->root))->fixable(['phpstan', 'pest-type-coverage', 'pint', 'rector']))->toBe(['pest-type-coverage', 'phpstan', 'rector', 'pint']);
});

it('counts every rule\'s fixes by kind from what the studio sent back, only in files written here, with what it swept', function (): void {
    ($this->write)('app/Orders.php', "<?php\n\nclass Orders\n{\n    const LIMIT = 10;\n}\n");
    $finding = fn (string $where, string $rule): array => ['where' => $where, 'rule' => $rule, 'message' => 'x'];
    Saloon::fake([FixScanFilesRequest::class => MockResponse::make([
        'fixed' => [
            ['key' => 'pest-type-coverage', 'label' => 'constants typed', 'path' => 'app/Orders.php', 'finding' => $finding('app/Orders.php:5', 'constant')],
            ['key' => 'pest-arch', 'label' => 'debug calls left behind removed', 'path' => 'app/Gone.php', 'finding' => $finding('app/Gone.php:9', 'debug-call')],
        ],
        'swept' => [$finding('app/Gone.php:9', 'debug-call')],
        'generics' => ['label' => 'generic return types written from what the method returns', 'written' => 0],
        'files' => [
            ['path' => 'app/Orders.php', 'content' => base64_encode("<?php\n\nclass Orders\n{\n    const int LIMIT = 10;\n}\n"), 'state' => 'S'],
            ['path' => 'app/Gone.php', 'content' => base64_encode('<?php'), 'state' => 'S'],
        ],
        'needs' => ['read' => [], 'search' => []],
    ])]);

    $fixed = (new AutomatedFixes(new Toolbox($this->root), $this->root, app(Studio::class)))->fileByFile([
        'pest-type-coverage' => [$finding('app/Orders.php:5', 'constant')],
        'pest-arch' => [],
    ]);

    expect($fixed['kinds'])->toBe(['pest-type-coverage' => ['constants typed' => 1]])
        ->and($fixed['files'])->toBe(['pest-type-coverage' => 1])
        ->and($fixed['saved'])->toBe(['app/Orders.php'])
        ->and($fixed['fixed'])->toBe(['pest-type-coverage' => [$finding('app/Orders.php:5', 'constant')]])
        ->and($fixed['swept'])->toBe([$finding('app/Gone.php:9', 'debug-call')])
        ->and(($this->read)('app/Orders.php'))->toContain('const int LIMIT = 10;')
        ->and(is_file($this->root.'/app/Gone.php'))->toBeFalse();
});

it('starts from what the last scan kept for this commit, and checks only what it has nothing for', function (): void {
    $git = fn (string ...$arguments): string => (string) (new Process(['git', ...$arguments], $this->root))->mustRun()->getOutput();
    $git('init', '--quiet');
    $git('-c', 'user.email=avatar@example.com', '-c', 'user.name=Avatar', 'commit', '--quiet', '--allow-empty', '-m', 'start');

    $kept = new LastFindings($this->root);
    $kept->keep('phpstan', [['where' => 'app/A.php:3', 'rule' => 'property.notFound', 'message' => 'x']]);

    $found = (new AutomatedFixes(new Toolbox($this->root), $this->root))->findings(['phpstan', 'pest-type-coverage']);

    expect($found['findings']['phpstan'])->toBe([['where' => 'app/A.php:3', 'rule' => 'property.notFound', 'message' => 'x']])
        ->and($found['checked'])->toBe(['pest-type-coverage'])
        ->and($found['failed'])->toHaveKey('pest-type-coverage');

    $kept->keep('phpstan', [
        ['where' => 'app/A.php:3', 'rule' => 'property.notFound', 'message' => 'x'],
        ['where' => 'app/B.php:7', 'rule' => 'property.notFound', 'message' => 'y'],
    ]);
    ($this->write)('app/A.php', "<?php\n");
    $git('add', 'app/A.php');
    $git('-c', 'user.email=avatar@example.com', '-c', 'user.name=Avatar', 'commit', '--quiet', '-m', 'moved on');

    expect((new LastFindings($this->root))->for('phpstan'))->toBe([['where' => 'app/B.php:7', 'rule' => 'property.notFound', 'message' => 'y']]);

    $git('checkout', '--quiet', '--orphan', 'elsewhere');
    $git('-c', 'user.email=avatar@example.com', '-c', 'user.name=Avatar', 'commit', '--quiet', '-m', 'unrelated');

    expect((new LastFindings($this->root))->for('phpstan'))->toBeNull();

    $kept->forget();
});
