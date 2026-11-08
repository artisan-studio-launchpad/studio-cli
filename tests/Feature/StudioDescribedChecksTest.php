<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanChecksRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanToolsRequest;
use ArtisanStudio\StudioCli\Scan\StudioChecks;
use ArtisanStudio\StudioCli\Scan\Tools\StudioRuleset;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\StateFile;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| The checks Artisan Studio describes, run on this machine's files
|--------------------------------------------------------------------------
|
| The CLI holds no check. It asks the studio which checks there are, sends the
| files a check asks for through the one filter, and passes on where the
| studio found something. A .env, or a file holding a secret, is never sent.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-described-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app/Livewire', 0755, true);
    file_put_contents($this->root.'/app/Livewire/Invoice.php', "<?php\n\nclass Invoice {}\n");
    file_put_contents($this->root.'/app/Livewire/Keys.php', "<?php\n\n\$key = 'AKIA".'ABCDEFGHJKLMNPQR'."';\n");
    file_put_contents($this->root.'/.env', "APP_KEY=base64:x\n");
    $this->described = [
        ['key' => 'livewire', 'name' => 'Livewire', 'about' => 'About it.', 'rulesets' => ['studio-security', 'studio-performance']],
        ['key' => 'broken name!', 'name' => 'Nope', 'about' => 'Dropped.', 'rulesets' => ['studio-security']],
    ];
    $this->calls = [];
    $this->studio = fn (Closure $answer) => app()->instance(Studio::class, Mockery::mock(Studio::class, function ($studio) use ($answer): void {
        $studio->shouldReceive('runScanCheck')->andReturnUsing(function (string $check, array $sent) use ($answer): ?array {
            $this->calls[] = ['check' => $check, ...$sent];

            return $answer($check, $sent, count($this->calls));
        });
    }));
});

it('keeps only the checks that are described properly', function (): void {
    StudioChecks::keep($this->root, $this->described);

    expect(array_column(StudioChecks::described($this->root), 'key'))->toBe(['livewire'])
        ->and(StudioChecks::rulesets($this->root))->toBe(['studio-security', 'studio-performance']);
});

it('sends what the check asks for in rounds, and never a .env or a file holding a secret', function (): void {
    ($this->studio)(fn (string $check, array $sent, int $round): array => $round === 1
        ? ['needs' => ['read' => ['.env', 'app/Livewire/Keys.php', 'app/Livewire/Invoice.php'], 'search' => []]]
        : ['findings' => [['where' => 'app/Livewire/Invoice.php:3', 'rule' => 'livewire-locked', 'message' => 'One line.', 'ruleset' => 'studio-security', 'code' => 'class Invoice {}']], 'unread' => []]);

    $report = (new StudioChecks($this->root))->run('livewire');

    expect(array_column($this->calls[1]['files'], 'path'))->toBe(['app/Livewire/Invoice.php'])
        ->and($this->calls[1]['missing'])->toContain('.env')->toContain('app/Livewire/Keys.php')
        ->and($report['findings'])->toBe([['where' => 'app/Livewire/Invoice.php:3', 'rule' => 'livewire-locked', 'message' => 'One line.', 'ruleset' => 'studio-security']])
        ->and(json_encode($this->calls))->not->toContain('AKIA')->not->toContain(base64_encode('APP_KEY'));
});

it('tells the studio to check only the files named, and drops a path outside the project', function (): void {
    ($this->studio)(fn (): array => ['findings' => [], 'unread' => []]);

    (new StudioChecks($this->root))->run('livewire', ['app/Livewire/Invoice.php', '../outside.php', '/etc/passwd']);
    (new StudioChecks($this->root))->run('livewire');

    expect($this->calls[0]['only'])->toBe(['app/Livewire/Invoice.php'])
        ->and($this->calls[1])->not->toHaveKey('only');
});

it('gives up when the studio cannot be reached, or keeps asking for the same things', function (): void {
    ($this->studio)(fn (): ?array => null);

    expect((new StudioChecks($this->root))->run('livewire'))->toBeNull();

    $this->calls = [];
    ($this->studio)(fn (): array => ['needs' => ['read' => ['app/Livewire/Invoice.php'], 'search' => []]]);

    expect((new StudioChecks($this->root))->run('livewire'))->toBeNull()
        ->and($this->calls)->toHaveCount(2);
});

it('runs each ruleset as a tool that has the findings of its own ruleset, asking the studio once for the check', function (): void {
    StudioChecks::keep($this->root, $this->described);
    ($this->studio)(fn (): array => ['findings' => [
        ['where' => 'app/Livewire/Invoice.php:3', 'rule' => 'livewire-locked', 'message' => 'Locked.', 'ruleset' => 'studio-security'],
        ['where' => 'resources/views/livewire/posts.blade.php:4', 'rule' => 'livewire-missing-key', 'message' => 'Key.', 'ruleset' => 'studio-performance'],
    ], 'unread' => [['where' => 'app/Livewire/Invoice.php', 'rule' => 'livewire-locked']]]);

    $toolbox = new Toolbox($this->root);
    $results = $toolbox->runTogether(['studio-security', 'studio-performance']);

    expect($toolbox->tool('studio-security'))->toBeInstanceOf(StudioRuleset::class)
        ->and($results['studio-security']['findings'])->toBe([['where' => 'app/Livewire/Invoice.php:3', 'rule' => 'livewire-locked', 'message' => 'Locked.']])
        ->and($results['studio-security']['unread'])->toBe([['where' => 'app/Livewire/Invoice.php', 'rule' => 'livewire-locked']])
        ->and(array_column($results['studio-performance']['findings'], 'rule'))->toBe(['livewire-missing-key'])
        ->and($this->calls)->toHaveCount(1);
});

it('checks only the files it is given when a ruleset is checked again after a fix', function (): void {
    StudioChecks::keep($this->root, $this->described);
    ($this->studio)(fn (): array => ['findings' => [], 'unread' => []]);

    $result = (new Toolbox($this->root))->check('studio-security', ['app/Livewire/Invoice.php']);

    expect($result['ran'])->toBeTrue()
        ->and($this->calls[0]['only'])->toBe(['app/Livewire/Invoice.php']);
});

it('says the ruleset could not be read when the studio gives no answer', function (): void {
    StudioChecks::keep($this->root, $this->described);
    ($this->studio)(fn (): ?array => null);

    expect((new Toolbox($this->root))->check('studio-security')['ran'])->toBeFalse();
});

it('runs a described check from the command and lists the checks', function (): void {
    StudioChecks::keep(base_path(), $this->described);
    app()->instance(Studio::class, Mockery::mock(Studio::class, function ($studio): void {
        $studio->shouldReceive('scanChecks')->andReturn([['key' => 'livewire', 'name' => 'Livewire', 'about' => 'About it.', 'rulesets' => ['studio-security']]]);
        $studio->shouldReceive('runScanCheck')->andReturn(['findings' => [['where' => 'app/A.php:1', 'rule' => 'livewire-locked', 'message' => 'Locked.', 'ruleset' => 'studio-security']], 'unread' => []]);
    }));

    Artisan::call('studio:check', ['--list' => true]);
    $listed = json_decode(Artisan::output(), true);
    Artisan::call('studio:check', ['check' => 'livewire']);
    $ran = json_decode(Artisan::output(), true);
    $unknown = Artisan::call('studio:check', ['check' => 'nothing']);

    expect(array_column($listed['checks'], 'key'))->toBe(['livewire'])
        ->and($ran['findings'][0]['rule'])->toBe('livewire-locked')
        ->and($unknown)->toBe(1);
});

it('keeps the checks and the groups the studio describes when it is asked which tools to run', function (): void {
    Saloon::fake([
        ShowScanToolsRequest::class => MockResponse::make(['tools' => ['studio-security'], 'groups' => [['name' => 'Studio', 'tools' => ['studio-security']]]]),
        ShowScanChecksRequest::class => MockResponse::make(['checks' => $this->described]),
    ]);

    app(Studio::class)->scanTools();

    expect(array_column(StudioChecks::described(base_path()), 'key'))->toBe(['livewire'])
        ->and(json_decode((string) file_get_contents(StateFile::for('scan-groups', base_path())), true))->toBe([['name' => 'Studio', 'tools' => ['studio-security']]]);

    @unlink(StateFile::for('scan-groups', base_path()));
});
