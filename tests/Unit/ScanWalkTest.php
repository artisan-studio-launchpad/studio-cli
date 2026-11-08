<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Scan\ClassFacts;
use ArtisanStudio\StudioCli\Scan\Glob;
use ArtisanStudio\StudioCli\Scan\Walk;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->project = sys_get_temp_dir().'/studio-scan-'.bin2hex(random_bytes(4));
    mkdir($this->project.'/app/Models', 0755, true);
    mkdir($this->project.'/tests', 0755, true);
    (new Process(['git', 'init', '--quiet'], $this->project))->run();

    file_put_contents($this->project.'/app/Models/Invoice.php', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Factories\HasFactory;
        use Illuminate\Database\Eloquent\Model as Eloquent;

        class Invoice extends Eloquent
        {
            use HasFactory;

            protected $fillable = ['total'];
        }
        PHP);
    file_put_contents($this->project.'/app/Models/Team.php', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        class Team extends Model
        {
            public function owner(): string
            {
                return Invoice::class;
            }
        }
        PHP);
    file_put_contents($this->project.'/tests/Pest.php', "<?php\n\npest()->use(RefreshDatabase::class)->in('Feature');\n");
    file_put_contents($this->project.'/README.md', "# readme\n");
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->project]))->run();
});

it('turns a glob into a path pattern, with folders at any depth and choices in braces', function (): void {
    expect(preg_match(Glob::toRegex('app/**/*.php'), 'app/Models/Invoice.php'))->toBe(1)
        ->and(preg_match(Glob::toRegex('app/**/*.php'), 'app/Invoice.php'))->toBe(1)
        ->and(preg_match(Glob::toRegex('app/**/*.php'), 'routes/web.php'))->toBe(0)
        ->and(preg_match(Glob::toRegex('resources/js/**/*.{vue,tsx}'), 'resources/js/pages/Home.tsx'))->toBe(1)
        ->and(preg_match(Glob::toRegex('app/{Models,Http}/**/*.php'), 'app/Livewire/Board.php'))->toBe(0)
        ->and(Glob::matchesAny('tests/Pest.php', ['app/**/*.php', 'tests/Pest.php']))->toBeTrue();
});

it('reads what a class is, extends, implements and uses, with names resolved through the imports', function (): void {
    $facts = ClassFacts::of(<<<'PHP'
        <?php

        namespace App\Livewire;

        use Livewire\Component as Base;
        use App\Concerns\HasTeam;

        #[Layout(Board::class)]
        final class Board extends Base implements \Stringable, Contracts\Shows
        {
            use HasTeam, WithPagination;

            public function render(): string
            {
                return collect([1])->map(function () use ($x) { return new class {}; })->first();
            }
        }
        PHP);

    expect($facts)->toBe([
        'kind' => 'class',
        'name' => 'App\Livewire\Board',
        'extends' => ['Livewire\Component'],
        'implements' => ['Stringable', 'App\Livewire\Contracts\Shows'],
        'traits' => ['App\Concerns\HasTeam', 'App\Livewire\WithPagination'],
    ])
        ->and(ClassFacts::of("<?php\n\nreturn ['a' => 1];\n"))->toBeNull();
});

it('walks the project once, counting each side of a convention by file with the line it was found on', function (): void {
    file_put_contents($this->project.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']], 'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]]));
    $facts = (new Walk($this->project, new LocalChanges($this->project)))->facts(['checks' => [
        'mass-assignment' => ['count' => 'files', 'sides' => [
            ['key' => 'fillable', 'label' => '$fillable', 'in' => ['app/Models/**/*.php'], 'match' => 'protected\s+\$fillable\b'],
            ['key' => 'neither', 'label' => 'Neither', 'in' => ['app/Models/**/*.php'], 'match' => '\$fillable\b|\$guarded\b', 'absent' => true],
        ]],
        'db-reset' => ['count' => 'files', 'sides' => [
            ['key' => 'global', 'label' => 'Pest.php', 'in' => ['tests/Pest.php'], 'match' => '->use\([^)]*RefreshDatabase', 'global' => true],
        ]],
        'architecture-map' => ['facts' => 'folders'],
    ]]);

    expect($facts['files'])->toBe(5)
        ->and($facts['read'])->toBe(3)
        ->and($facts['checks']['mass-assignment']['fillable'])->toBe(['files' => 1, 'hits' => 1, 'where' => ['app/Models/Invoice.php:12']])
        ->and($facts['checks']['mass-assignment']['neither'])->toBe(['files' => 1, 'hits' => 1, 'where' => ['app/Models/Team.php']])
        ->and($facts['checks']['db-reset']['global']['where'])->toBe(['tests/Pest.php:3'])
        ->and($facts['classes']['extends'])->toBe(['Illuminate\Database\Eloquent\Model' => ['count' => 2, 'where' => ['app/Models/Invoice.php', 'app/Models/Team.php']]])
        ->and($facts['classes']['traits'])->toHaveKey('Illuminate\Database\Eloquent\Factories\HasFactory')
        ->and($facts['folders'])->toBe(['app/Models' => 2]);
});

it('skips a detector whose pattern does not compile rather than failing the walk', function (): void {
    $facts = (new Walk($this->project, new LocalChanges($this->project)))->facts(['checks' => [
        'broken' => ['sides' => [['key' => 'bad', 'label' => 'Bad', 'in' => ['app/**/*.php'], 'match' => '(unclosed']]],
    ]]);

    expect($facts['checks'])->toBe([]);
});

it('counts which of the classes the studio names a test actually uses, and names the ones none do', function (): void {
    file_put_contents($this->project.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']], 'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]]));
    mkdir($this->project.'/tests/Feature', 0755, true);
    file_put_contents($this->project.'/tests/Feature/InvoiceTest.php', "<?php\n\nuse App\\Models\\Invoice;\n\nit('totals', fn () => expect(Invoice::class)->toBeString());\n");
    file_put_contents($this->project.'/app/Models/Base.php', "<?php\n\nnamespace App\\Models;\n\nabstract class Base {}\n");

    $facts = (new Walk($this->project, new LocalChanges($this->project)))->facts(['checks' => [], 'testable' => ['app/Models/']]);

    expect($facts['tested'])->toBe(['classes' => 2, 'tested' => 1, 'untested' => ['app/Models/Team.php']]);
});

it('counts no tested classes until the studio says which folders hold them', function (): void {
    file_put_contents($this->project.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    $facts = (new Walk($this->project, new LocalChanges($this->project)))->facts(['checks' => []]);

    expect($facts['tested'])->toBe(['classes' => 0, 'tested' => 0, 'untested' => []])
        ->and($facts['folders'])->toBe(['app/Models' => 2]);
});

it('reads the folders the project loads its classes and tests from, not a fixed app and tests', function (): void {
    mkdir($this->project.'/modules/Billing/src', 0755, true);
    mkdir($this->project.'/modules/Billing/tests', 0755, true);
    file_put_contents($this->project.'/modules/Billing/src/Ledger.php', "<?php\n\nnamespace Modules\\Billing;\n\nclass Ledger {}\n");
    file_put_contents($this->project.'/modules/Billing/tests/LedgerTest.php', "<?php\n\nuse Modules\\Billing\\Ledger;\n\nit('adds', fn () => expect(Ledger::class)->toBeString());\n");
    file_put_contents($this->project.'/composer.json', (string) json_encode(['autoload' => ['psr-4' => ['Modules\\Billing\\' => 'modules/Billing/src/']], 'autoload-dev' => ['psr-4' => ['Modules\\Billing\\Tests\\' => 'modules/Billing/tests/']]]));

    $facts = (new Walk($this->project, new LocalChanges($this->project)))->facts(['checks' => [], 'testable' => ['modules/Billing/src/']]);

    expect($facts['folders'])->toBe(['modules/Billing' => 1])
        ->and($facts['tested'])->toBe(['classes' => 1, 'tested' => 1, 'untested' => []]);
});
