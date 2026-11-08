<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\EnvFile;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
|
| Opened with s or the ⚙ beside the tabs. Linking, rotating the token and
| unlinking all happen here, and what they change lands in the .env. A token
| is made for one project, so the project it lists is the one this checkout is.
|
*/

beforeEach(function (): void {
    $this->env = sys_get_temp_dir().'/studio-env-'.uniqid();
    file_put_contents($this->env, "APP_NAME=Test\nARTISAN_STUDIO_URL=\"https://studio.test\"\nARTISAN_STUDIO_TOKEN=\"test-token\"\nARTISAN_STUDIO_PROJECT=\"1\"\n");
    app()->instance(EnvFile::class, new EnvFile($this->env));
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['projects' => [
        ['slug' => '1', 'name' => 'Artisan Studio', 'repo' => 'acme/studio'],
    ]])]);

    $this->plain = fn (string $text): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $text);
    $this->buffer = new BufferedOutput;
    $this->command = app(StudioCommand::class);
    $this->command->setOutput(new OutputStyle(new ArrayInput([]), $this->buffer));
    $this->press = fn (string ...$keys): mixed => collect($keys)->each(fn (string $key): mixed => (fn (): mixed => $this->handleScreenAction($this->screenActionFor($key)))->call($this->command));
    $this->studio = fn (): ScreenContainer => (fn (): ScreenContainer => $this->getScreen())->call($this->command);
    $this->drawn = fn (int $width = 120): string => ($this->plain)(implode("\n", ($this->studio)()->lines($width, 30)));
    $this->flash = fn (): ?string => (fn (): ?string => $this->screenFlash)->call($this->command);
});

afterEach(function (): void {
    @unlink($this->env);
});

it('opens from s or the ⚙ at the far right of the tabs, and closes the same way', function (): void {
    ($this->press)('s');
    $opened = ($this->studio)()->settingsAreOpen();
    ($this->press)('s');
    $closed = ($this->studio)()->settingsAreOpen();

    $tabs = ($this->plain)(($this->studio)()->lines(120, 30)[3]);
    $cog = mb_strpos($tabs, 'Settings') + 1;
    (fn (): int => $this->screenWidthNow = 120)->call($this->command);
    ($this->press)("\e[<0;{$cog};4M");
    $clicked = ($this->studio)()->settingsAreOpen();
    ($this->press)("\e[<0;{$cog};4M");

    expect($opened)->toBeTrue()
        ->and($closed)->toBeFalse()
        ->and($clicked)->toBeTrue()
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse()
        ->and(rtrim($tabs))->toMatch('/[⚙≡] Settings$/u')
        ->and(mb_strpos($tabs, 'Settings'))->toBeGreaterThan(mb_strpos($tabs, 'Activity'));
});

it('shows the project, the studio and the token, never the token itself', function (): void {
    ($this->press)('s');

    expect(($this->drawn)())
        ->toContain('Artisan Studio  (acme/studio)')->toContain('● linked')
        ->toContain('https://studio.test')
        ->toContain('••••••••oken')->toContain('● accepted')
        ->toContain('▸ Link with a new token')->toContain('Unlink this project')
        ->toContain('Choose')->toContain('Close')
        ->not->toContain('Switch project')
        ->not->toContain('test-token');
});

it('rotates the token from inside the screen, keeping the project the token was made for', function (): void {
    ($this->press)('s', "\n", "\e[200~rotated-token\e[201~", "\n");

    expect(file_get_contents($this->env))->toContain('ARTISAN_STUDIO_TOKEN="rotated-token"')->toContain('ARTISAN_STUDIO_PROJECT="1"')
        ->and(config('studio-cli.token'))->toBe('rotated-token')
        ->and(($this->flash)())->toBe('Linked to Artisan Studio.');
});

it('runs an action when its words are clicked, picks a choice the same way, and ignores the space beside them', function (): void {
    $click = function (string $label, int $offset = 0): void {
        $lines = array_map($this->plain, ($this->studio)()->lines(120, 30));
        $row = (int) collect($lines)->search(fn (string $line): bool => str_contains($line, $label)) + 1;
        $column = mb_strpos($lines[$row - 1], $label) + 1 + $offset;
        ($this->press)("\e[<0;{$column};{$row}M");
    };

    ($this->press)('s');
    $click('Choose your editor', 30);
    $besideIt = ($this->studio)()->isTypingInSettings() || str_contains(($this->drawn)(), 'None, I open the files myself');
    $click('Choose your editor');
    $listed = ($this->drawn)();
    $click('Choose your editor');
    $closedAgain = ! str_contains(($this->drawn)(), 'None, I open the files myself');
    $click('Choose your editor');
    $click('None, I open the files myself');
    $chosen = file_get_contents($this->env);
    $click('Link with a new token');

    expect($besideIt)->toBeFalse()
        ->and($listed)->toContain('None, I open the files myself')
        ->and($closedAgain)->toBeTrue()
        ->and($chosen)->toContain('ARTISAN_STUDIO_EDITOR="none"')
        ->and(($this->studio)()->isTypingInSettings())->toBeTrue();
});

it('reconnects what runs in the background once a setting changes', function (): void {
    $runner = Mockery::mock(RunsInBackground::class);
    $runner->shouldReceive('stop')->once()->ordered();
    $runner->shouldReceive('start')->once()->ordered();
    (fn (): array => $this->screenRunners = [$runner])->call($this->command);

    ($this->press)('s', "\e[B", "\n", "\n");

    expect(config('studio-cli.review.editor'))->toBe(Editor::PHPSTORM);
});

it('chooses the editor a review opens its files in, and writes it to the .env', function (): void {
    $terminal = getenv('TERMINAL_EMULATOR');
    putenv('TERMINAL_EMULATOR=JetBrains-JediTerm');
    ($this->press)('s');
    $detected = ($this->drawn)();
    ($this->press)("\e[B", "\n");
    $choosing = ($this->drawn)();
    ($this->press)("\e[B", "\n");
    $chosen = ($this->drawn)();
    ($this->press)("\n", "\e[B", "\e[B", "\n");
    $none = app(Editor::class)->name();
    putenv($terminal === false ? 'TERMINAL_EMULATOR' : "TERMINAL_EMULATOR={$terminal}");

    expect($detected)->toContain('Editor')->toContain('PhpStorm (from this terminal)')
        ->and($choosing)->toContain('PhpStorm')->toContain('VS Code')->toContain('None, I open the files myself')
        ->and($chosen)->toContain('VS Code')->not->toContain('from this terminal')
        ->and(file_get_contents($this->env))->toContain('ARTISAN_STUDIO_EDITOR="none"')
        ->and(config('studio-cli.review.editor'))->toBe('none')
        ->and($none)->toBeNull()
        ->and(($this->flash)())->toBe('Reviews list the files for you to open.');
});

it('asks before unlinking, and Cancel leaves the link alone', function (): void {
    ($this->press)('s', "\e[B", "\e[B", "\n");
    $asking = ($this->drawn)();
    ($this->press)("\e[B", "\n");
    $kept = (string) file_get_contents($this->env);
    ($this->press)("\n", "\n");

    expect($asking)->toContain('This takes the token and project out of your .env.')->toContain('▸ Unlink this project')->toContain('Cancel')
        ->and($kept)->toContain('ARTISAN_STUDIO_TOKEN="test-token"')
        ->and(file_get_contents($this->env))->not->toContain('ARTISAN_STUDIO_TOKEN')->not->toContain('ARTISAN_STUDIO_PROJECT')
        ->and(file_get_contents($this->env))->toContain('APP_NAME=Test')->toContain('ARTISAN_STUDIO_URL')
        ->and(config('studio-cli.token'))->toBeNull()
        ->and(($this->flash)())->toBe('Unlinked this project.')
        ->and(($this->drawn)())->toContain('● not linked')->toContain('▸ Link with a token');
});

it('takes a pasted token right there in Settings, masked, and links the project it was made for', function (): void {
    ($this->press)('s', "\n", "\e[200~new-token\e[201~");
    $typing = ($this->drawn)();
    ($this->press)("\n");

    expect($typing)->toContain("Paste this project's token")->toContain('Generate token')->toContain('› •••••••••')->toContain('Done')->toContain('Cancel')
        ->not->toContain('new-token')
        ->and(($this->flash)())->toBe('Linked to Artisan Studio.')
        ->and(file_get_contents($this->env))->toContain('ARTISAN_STUDIO_TOKEN="new-token"')->toContain('ARTISAN_STUDIO_PROJECT="1"')
        ->and(config('studio-cli.token'))->toBe('new-token');
});

it('treats every key as part of the token while it is being typed, and backspace takes the last one off', function (): void {
    ($this->press)('s', "\n", 'q', 's', '?', '2', "\x7f", "\e[A");

    expect((fn (): bool => $this->screenQuit)->call($this->command))->toBeFalse()
        ->and(($this->studio)()->settingsAreOpen())->toBeTrue()
        ->and((fn (): ?string => $this->settingsInput)->call(($this->studio)()))->toBe('qs?');
});

it('leaves the token field with Esc, or an empty Enter, and links nothing', function (): void {
    ($this->press)('s', "\n", "\n");
    $stillAsking = ($this->studio)()->isTypingInSettings();
    ($this->press)('abc', "\e");

    expect($stillAsking)->toBeTrue()
        ->and(($this->studio)()->isTypingInSettings())->toBeFalse()
        ->and(($this->studio)()->settingsAreOpen())->toBeTrue()
        ->and(file_get_contents($this->env))->toContain('ARTISAN_STUDIO_TOKEN="test-token"')
        ->and(($this->flash)())->toBeNull();
});

it('keeps the link it had when a new token is turned away, and says why', function (): void {
    ($this->press)('s');
    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);
    ($this->press)("\n", 'made-up', "\n");

    expect(file_get_contents($this->env))->toContain('ARTISAN_STUDIO_TOKEN="test-token"')
        ->and(config('studio-cli.token'))->toBe('test-token')
        ->and(($this->flash)())->toBeNull()
        ->and(($this->drawn)())->toContain('does not recognise that token');
});

it('says why a token was turned away, in Settings, rather than failing', function (): void {
    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    ($this->press)('s');

    expect(($this->drawn)())->toContain('does not recognise that token')->not->toContain('● accepted');
});

it('marks the token refused and the project unchecked, never linked, when the studio turns it away', function (): void {
    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    ($this->press)('s');

    expect(($this->drawn)())->toContain('● refused')->toContain('● not checked')->not->toContain('● linked');
});

it('says the studio is unreachable when nothing answers', function (): void {
    Saloon::fake([ListProjectsRequest::class => MockResponse::make()->throw(new RuntimeException('Connection refused'))]);

    ($this->press)('s');

    expect(($this->drawn)())->toContain('● unreachable')->not->toContain('● linked')->not->toContain('● accepted');
});

it('shows linked and accepted the moment a fresh token replaces a refused one', function (): void {
    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);
    ($this->press)('s');

    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['projects' => [['slug' => '1', 'name' => 'Artisan Studio', 'repo' => 'acme/studio']]])]);
    ($this->press)("\n", "\e[200~fresh-token\e[201~", "\n");

    expect(($this->drawn)())->toContain('● linked')->toContain('● accepted')->not->toContain('● refused');
});

it('goes back a step with Esc, out of a list first and then out of Settings', function (): void {
    ($this->press)('s', "\e[B", "\n", "\e");
    $outOfTheList = ($this->drawn)();
    ($this->press)("\e");

    expect($outOfTheList)->toContain('▸ Choose your editor')->not->toContain('None, I open the files myself')
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse();
});

it('closes Settings when a tab or help is opened', function (): void {
    ($this->press)('s', '2');
    $afterATab = ($this->studio)()->settingsAreOpen();
    ($this->press)('s', '?');

    expect($afterATab)->toBeFalse()
        ->and(($this->studio)()->settingsAreOpen())->toBeFalse();
});

it('fills the terminal exactly with Settings open, choosing or not', function (int $width): void {
    ($this->press)('s');
    $open = ($this->studio)()->lines($width, 24);
    ($this->press)("\n");
    $choosing = ($this->studio)()->lines($width, 24);
    $widths = fn (array $lines): array => collect($lines)->map(fn (string $line): int => Canvas::visibleWidth($line))->unique()->values()->all();

    expect($widths($open))->toBe([$width])
        ->and($widths($choosing))->toBe([$width]);
})->with([72, 120, 140]);

it('opens straight on Settings from studio settings, and from studio:settings', function (): void {
    Artisan::call('studio', ['tab' => 'settings', '--once' => true, '--width' => 120, '--height' => 30]);
    $fromTheStudio = ($this->plain)(Artisan::output());
    putenv('COLUMNS=120');
    Artisan::call('studio:settings');
    putenv('COLUMNS');

    expect($fromTheStudio)->toContain('Unlink this project')
        ->and(($this->plain)(Artisan::output()))->toContain('Unlink this project');
});

it('links from the flags without opening the studio, under its short name too', function (string $name): void {
    $this->artisan($name, ['--token' => 'flag-token'])->assertSuccessful();

    expect(file_get_contents($this->env))->toContain('ARTISAN_STUDIO_TOKEN="flag-token"')->toContain('ARTISAN_STUDIO_PROJECT="1"')
        ->and(config('studio-cli.token'))->toBe('flag-token');
})->with(['studio:settings', 'studio:link']);

it('refuses a token the studio does not recognise, and writes nothing', function (): void {
    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('studio:settings', ['--token' => 'made-up'])
        ->expectsOutputToContain('does not recognise that token')
        ->assertFailed();

    expect(file_get_contents($this->env))->toContain('ARTISAN_STUDIO_TOKEN="test-token"');
});

it('links the project the token was made for, whatever the .env named before', function (): void {
    (new EnvFile($this->env))->write(['ARTISAN_STUDIO_PROJECT' => '9']);
    config()->set('studio-cli.project', '9');

    $this->artisan('studio:settings', ['--token' => 'flag-token'])->assertSuccessful();

    expect(file_get_contents($this->env))->toContain('ARTISAN_STUDIO_PROJECT="1"')
        ->and(config('studio-cli.project'))->toBe('1');
});
