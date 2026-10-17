<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->visibleText = fn (string $line): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $line);
    $this->snapshot = DashboardSnapshot::sample(Carbon::parse('2026-09-27 09:00:30'));
    $this->app->instance(SnapshotSource::class, Mockery::mock(SnapshotSource::class, ['snapshot' => $this->snapshot]));
    $this->screen = fn (bool $trueColour = true, bool $emoji = true, ?string $background = '000000'): ScreenContainer => app(StudioCommand::class)
        ->screen(ScreenContainer::make()->trueColour($trueColour)->emoji($emoji)->background($background));
    $this->widths = fn (array $lines): array => collect($lines)->map(fn (string $line): int => Canvas::visibleWidth($line))->unique()->values()->all();
});

it('fills the terminal exactly, line for line, at any size and on every tab', function (int $width, int $height, string $tab) {
    $lines = ($this->screen)()->lines($width, $height, $tab);

    expect($lines)->toHaveCount($height)
        ->and(($this->widths)($lines))->toBe([$width]);
})->with([72, 100, 140, 220])->with([24, 40])->with(['dashboard', 'insights', 'build', 'activity']);

it('keeps the same exact fit for help, a message in the footer, and as an artisan dev tab', function (int $width) {
    $screen = ($this->screen)(trueColour: false);

    expect(($this->widths)($screen->lines($width, 30, help: true)))->toBe([$width])
        ->and(($this->widths)($screen->lines($width, 30, flash: 'Refreshed')))->toBe([$width])
        ->and(($this->widths)($screen->lines($width, 30, interactive: false)))->toBe([$width]);
})->with([72, 118, 140]);

it('scrolls a short terminal instead of cutting it off, keeping the header and keys in place', function (string $tab) {
    $screen = ($this->screen)();
    $limit = $screen->scrollLimit(120, 12, $tab);
    $plain = fn (int $scroll): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $screen->render(120, 12, $tab, scroll: $scroll));

    expect($limit)->toBeGreaterThan(0)
        ->and($screen->lines(120, 12, $tab, scroll: $limit))->toHaveCount(12)
        ->and(($this->widths)($screen->lines(120, 12, $tab, scroll: $limit)))->toBe([120])
        ->and($plain($limit))->toContain('Artisan Studio')->toContain('Quit')->toContain('of')
        ->and($plain(9999))->toBe($plain($limit))
        ->and($plain(-5))->toBe($plain(0));
})->with(['dashboard', 'insights']);

it('paints the background the config names, black by default, or leaves the terminal\'s own', function (string $setting, string $expected) {
    config(['studio-cli.theme.dark.background' => $setting]);

    $this->artisan('studio', ['--once' => true, '--width' => 100, '--height' => 30])
        ->expectsOutputToContain($expected)
        ->assertSuccessful();
})->with([
    'black by default' => ['000000', ';48;2;0;0;0m'],
    'any hex, with or without a hash' => ['#0A0E17', ';48;2;10;14;23m'],
    'the terminal\'s own' => ['terminal', ';49m'],
    'black when the setting makes no sense' => ['navy', ';48;2;0;0;0m'],
]);

it('draws on the terminal\'s own background at the same exact fit', function () {
    $terminal = ($this->screen)(background: null);

    expect($terminal->render(120, 40))->not->toContain('48;2;0;0;0m')
        ->and($terminal->takeOver())->toContain(';49m')
        ->and(($this->widths)($terminal->lines(120, 40)))->toBe([120]);
});

it('paints past the frame in the dashboard colour, so a resize never shows the terminal behind it', function () {
    $screen = ($this->screen)();
    $black = '48;2;0;0;0';

    expect($screen->takeOver())->toContain($black)->toContain("\e[2J")
        ->and($screen->eraseToEdge())->toContain($black)->toContain("\e[K")
        ->and($screen->clearBelow())->toContain($black)->toContain("\e[J");
});

it('heads the screen with the project name once, in words, and its repository', function (int $height) {
    $screen = ($this->screen)();
    $header = collect($screen->lines(120, $height))
        ->take(3)
        ->map(fn (string $line): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line))
        ->implode("\n");

    expect(substr_count($header, 'Artisan Studio'))->toBe(1)
        ->and($header)->toContain('artisan-studio-projects/artisan-studio')
        ->and($header)->not->toContain('▀')
        ->and(($this->widths)($screen->lines(120, $height)))->toBe([120]);
})->with([12, 24, 40]);

it('keeps the one-line header and the row of key chips at every height, so resizing never restacks them', function (int $height) {
    $lines = collect(($this->screen)()->lines(120, $height))
        ->map(fn (string $line): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line));
    $footer = $lines->slice(-3)->implode("\n");

    expect($lines->get(1))->toContain('Artisan Studio')->toContain('·')->toContain('artisan-studio-projects/artisan-studio')->toContain('updated')
        ->and($lines->get(2))->toContain('──')
        ->and($lines->get(3))->toContain('Dashboard')
        ->and($footer)->toContain(' q  Quit')->toContain(' ?  Help')
        ->and($footer)->not->toMatch('/[╭╰│]/u');
})->with([16, 40, 60]);

it('leaves a blank row above and below everything, in the dashboard and as an artisan dev tab', function (int $height, bool $interactive) {
    $lines = collect(($this->screen)()->lines(120, $height, interactive: $interactive))
        ->map(fn (string $line): string => trim((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line)));

    expect($lines)->toHaveCount($height)
        ->and($lines->first())->toBe('')
        ->and($lines->last())->toBe('')
        ->and($lines->get(1))->not->toBe('')
        ->and($lines->get($height - 2))->not->toBe('');
})->with([12, 24, 40])->with([true, false]);

it('outlines the cards with everything centred, in emoji or in the font\'s own symbols where emoji drift', function (bool $emoji, array $icons) {
    $screen = ($this->screen)(emoji: $emoji);
    $lines = collect($screen->lines(120, 40))->map(fn (string $line): string => (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line));
    $heading = $lines->search(fn (string $line): bool => str_contains($line, "{$icons[0]} Blueprint"));
    $insideFirstCard = preg_match('/│(.*?)│/u', (string) $lines->get($heading), $card) === 1 ? $card[1] : '';

    expect($heading)->not->toBeFalse()
        ->and($lines->get($heading - 1))->toContain('╭')->toContain('╮')
        ->and($lines->get($heading))->toContain("{$icons[0]} Blueprint")
        ->and(abs((mb_strlen($insideFirstCard) - mb_strlen(ltrim($insideFirstCard))) - (mb_strlen($insideFirstCard) - mb_strlen(rtrim($insideFirstCard)))))->toBeLessThanOrEqual(2)
        ->and(($this->widths)($screen->lines(120, 40)))->toBe([120]);
})->with([
    'emoji' => [true, ['⏳']],
    'symbols' => [false, ['…']],
]);

it('uses the font\'s own symbols in PhpStorm, where emoji push the card borders out of line', function () {
    putenv('TERMINAL_EMULATOR=JetBrains-JediTerm');

    $this->artisan('studio', ['--once' => true, '--width' => 120, '--height' => 40])
        ->expectsOutputToContain('♥')
        ->doesntExpectOutputToContain('⏳')
        ->assertSuccessful();

    putenv('TERMINAL_EMULATOR');
});

it('puts a real View insights link on the health line, and keeps the rest of the tab plain', function () {
    $lines = collect(($this->screen)()->lines(120, 60, 'insights'));
    $summary = (string) $lines->first(fn (string $line): bool => str_contains($line, 'View insights'));
    $text = ($this->visibleText)($summary);
    $from = mb_strpos($text, 'View insights') + 1;
    $to = mb_strpos($text, '↗') + 1;
    $linked = $lines->filter(fn (string $line): bool => str_contains($line, "\e]8;;") || str_contains($line, '↗'));

    expect($text)->toContain('Health 68%')
        ->and(Canvas::linkAt($summary, $from))->toBe('https://studio.test/insights')
        ->and(Canvas::linkAt($summary, $to))->toBe('https://studio.test/insights')
        ->and(Canvas::linkAt($summary, $from - 1))->toBeNull()
        ->and(Canvas::linkAt($summary, 5))->toBeNull()
        ->and($linked)->toHaveCount(1)
        ->and(($this->widths)($lines->all()))->toBe([120]);
});

it('opens the page when the ↗ is clicked, the same way the o key opens the app, and nothing when the rest of the row is', function () {
    Process::fake();
    $command = app(StudioCommand::class);
    $lines = ($this->screen)()->lines(120, 60, 'insights');
    $row = collect($lines)->search(fn (string $line): bool => str_contains($line, 'View insights')) + 1;
    $icon = mb_strpos(($this->visibleText)($lines[$row - 1]), '↗') + 1;
    $name = mb_strpos(($this->visibleText)($lines[$row - 1]), 'Health') + 1;
    $call = fn (string $method, mixed ...$arguments): mixed => (fn (): mixed => $this->{$method}(...$arguments))->call($command);
    (fn (): array => $this->screenLines = $lines)->call($command);

    $click = $call('screenActionFor', "\e[<0;{$icon};{$row}M");
    $call('handleScreenAction', $click);
    $call('handleScreenAction', $call('screenActionFor', "\e[<0;{$name};{$row}M"));
    $call('handleScreenAction', $call('screenActionFor', "\e[<0;{$icon};2M"));

    expect($click)->toBe("click:{$icon}:{$row}")
        ->and($call('screenActionFor', "\e[<0;{$icon};{$row}m"))->toBeNull()
        ->and($call('screenActionFor', "\e[<65;{$icon};{$row}M"))->toBe('scroll:3');

    Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('https://studio.test/insights', (array) $process->command, true), 1);
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 1);
});

it('switches tab when a tab is clicked, and leaves it when the click lands between or below the tabs', function () {
    Process::fake();
    $command = app(StudioCommand::class);
    $lines = ($this->screen)()->lines(120, 40);
    $row = collect($lines)->search(fn (string $line): bool => str_contains(($this->visibleText)($line), ' Dashboard ')) + 1;
    $column = fn (string $label): int => mb_strpos(($this->visibleText)($lines[$row - 1]), $label) + 1;
    $call = fn (string $method, mixed ...$arguments): mixed => (fn (): mixed => $this->{$method}(...$arguments))->call($command);
    $tab = fn (): ?string => (fn (): ?string => $this->screenTab)->call($command);
    (fn (): int => $this->screenWidthNow = 120)->call($command);
    (fn (): array => $this->screenLines = $lines)->call($command);

    $call('handleScreenAction', $call('screenActionFor', "\e[<0;{$column('Insights')};{$row}M"));
    $afterInsights = $tab();
    $call('handleScreenAction', $call('screenActionFor', "\e[<0;".($column('Activity') + 4).";{$row}M"));
    $afterActivity = $tab();
    $call('handleScreenAction', $call('screenActionFor', "\e[<0;".($column('Activity') + 12).";{$row}M"));
    $call('handleScreenAction', $call('screenActionFor', "\e[<0;{$column('Dashboard')};".($row + 1).'M'));

    expect($row)->toBe(4)
        ->and($afterInsights)->toBe('insights')
        ->and($afterActivity)->toBe('activity')
        ->and($tab())->toBe('activity')
        ->and(($this->screen)()->tabAt(120, $column('Dashboard') - 1, $row))->toBe('dashboard')
        ->and(($this->screen)()->tabAt(120, $column('Dashboard') - 2, $row))->toBeNull();

    Process::assertNothingRan();
});

it('takes any colour from the config, keeps the default for the rest, and ignores what is not a hex', function () {
    config(['studio-cli.theme.dark.colours' => ['cyan' => '#FF0000', 'amber' => 'not a colour']]);

    Artisan::call('studio', ['--once' => true, '--width' => 120, '--height' => 40]);
    $output = Artisan::output();

    expect($output)->toContain('38;2;255;0;0')
        ->and($output)->toContain('38;2;245;180;84')
        ->and($output)->not->toContain('38;2;29;236;237m');
});

it('draws a light theme on light slate when the config asks for it', function () {
    config(['studio-cli.theme.mode' => 'light']);

    Artisan::call('studio', ['--once' => true, '--width' => 120, '--height' => 40]);
    $output = Artisan::output();

    expect($output)->toContain('38;2;15;23;42;48;2;241;245;249m')
        ->and($output)->toContain('38;2;8;145;178')
        ->and($output)->not->toContain('48;2;0;0;0m')
        ->and($output)->not->toContain('38;2;238;241;253');
});

it('wears the Artisan Studio colours, cyan into blue, and no pink or violet', function () {
    $screen = ($this->screen)()->render(120, 40);
    $cyan = '38;2;29;236;237';
    $blue = '38;2;59;130;246';

    expect($screen)->toContain("1;{$cyan};48;2;0;0;0mA")
        ->and($screen)->toContain($blue)
        ->and($screen)->toContain('38;2;0;255;240')
        ->and($screen)->not->toContain('230;254;255')
        ->and($screen)->not->toContain('240;139;245')
        ->and($screen)->not->toContain('232;121;249')
        ->and($screen)->not->toContain('139;92;246')
        ->and($screen)->not->toContain('185;173;255');
});

it('asks for a wider terminal rather than drawing a broken one', function () {
    $lines = ($this->screen)()->lines(60, 30);

    expect($lines)->toHaveCount(1)
        ->and((string) preg_replace('/\e\[[0-9;]*m/', '', $lines[0]))->toContain('at least 72 columns');
});

it('shows the numbers the web header shows, in the terminal', function () {
    $text = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', ($this->screen)()->render(120, 40));

    expect($text)
        ->toContain('artisan-studio-projects/artisan-studio')
        ->toContain('♥ 68% Fair');
});

it('moves on its own, so a refresh visibly changes the scan', function () {
    $later = DashboardSnapshot::sample(Carbon::parse('2026-09-27 09:01:15'));

    expect($later->scanFilesDone)->toBeGreaterThan($this->snapshot->scanFilesDone)
        ->and($later->fingerprint())->not->toBe($this->snapshot->fingerprint());
});

it('draws one frame and exits when asked, or when nothing can take the terminal over', function () {
    $this->artisan('studio', ['--once' => true, '--width' => 100, '--height' => 30])
        ->expectsOutputToContain('artisan-studio-projects/artisan-studio')
        ->assertSuccessful();

    $this->artisan('studio')->assertSuccessful();
});

it('shows what a developer sees on a brand new project, not linked and nothing scanned yet', function (): void {
    Artisan::call('studio', ['--fresh' => true, '--once' => true, '--width' => 120, '--height' => 40]);
    $dashboard = ($this->visibleText)(Artisan::output());

    expect($dashboard)->toContain('Welcome to Artisan Studio')->toContain('Press s to paste your token.')->not->toContain('Not scanned yet')
        ->and(app(Studio::class)->isLinked())->toBeFalse();
});

it('opens on the tab it is given, and on the first when it does not know the one asked for', function (string $tab, string $expected): void {
    Artisan::call('studio', ['tab' => $tab, '--once' => true, '--width' => 120, '--height' => 40]);

    expect(($this->visibleText)(Artisan::output()))->toContain($expected);
})->with([
    'insights' => ['insights', 'Attempting to fix grouped issues'],
    'activity' => ['activity', 'Your scans and SAMI\'s fixes show up here'],
    'one it does not know' => ['somewhere', 'Best practices'],
]);
