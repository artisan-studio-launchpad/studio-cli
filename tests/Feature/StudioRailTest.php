<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Console\WatchCommand;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Work\WorkLauncher;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| The activity down the side
|--------------------------------------------------------------------------
|
| Wide enough, the activity stays in view whichever tab is open, and leaves
| the row of tabs while it is there. Narrower, it is a tab like the rest.
|
*/

beforeEach(function (): void {
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    $this->plain = fn (string $screen): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
    $this->watch = app(Kernel::class)->all()[WatchCommand::SIGNATURE];
    $this->log = fn (string $detail, string $kind = 'tools'): mixed => app(ActivityLog::class)->add(['agent' => 'studio', 'label' => 'Done', 'detail' => $detail, 'colour' => 'green', 'kind' => $kind]);
    $this->studio = app(StudioCommand::class)->screen(ScreenContainer::make());
});

it('shows each section its own activity: milestones, insights, or what is built', function (): void {
    ($this->log)('Scanned 120 files', 'scan');
    ($this->log)('Mapped 12 models', 'blueprint');
    ($this->log)('Wrote app/Models/Invoice.php', WorkLauncher::TASK);
    $dashboard = ($this->plain)($this->studio->render(140, 30, 'dashboard'));
    $insights = ($this->plain)($this->studio->render(140, 30, 'insights'));
    $build = ($this->plain)($this->studio->render(140, 30, 'build'));

    expect($dashboard)->toContain('│ Activity')->toContain('Mapped 12 models')->not->toContain('Scanned 120 files')->not->toContain('Invoice.php')
        ->and($insights)->toContain('│ Insights')->toContain('Scanned 120 files')->toContain('Mapped 12 models')->not->toContain('Invoice.php')
        ->and($build)->toContain('│ Build')->toContain('Invoice.php')->not->toContain('Mapped 12 models');
});

it('keeps the activity down the right of every tab once the terminal is wide enough, and out of the row of tabs', function (string $tab, string $heading): void {
    ($this->log)('Mapped 12 models');
    $lines = collect($this->studio->lines(140, 30, $tab));
    $plain = $lines->map(fn (string $line): string => ($this->plain)($line));

    expect($this->studio->tabKeys())->toBe(['dashboard', 'scan', 'insights', 'build'])
        ->and($plain->get(3))->not->toContain('Activity')
        ->and($plain->implode("\n"))->toContain("│ {$heading}")
        ->and($lines->map(fn (string $line): int => Canvas::visibleWidth($line))->unique()->all())->toBe([140]);
})->with([
    'dashboard' => ['dashboard', 'Activity'],
    'insights' => ['insights', 'Insights'],
    'build' => ['build', 'Build'],
]);

it('makes the activity a tab again when the terminal is too narrow for both', function (): void {
    ($this->log)('Mapped 12 models');
    $narrow = ($this->plain)($this->studio->render(120, 30, 'activity'));

    expect($this->studio->tabKeys())->toBe(['dashboard', 'scan', 'insights', 'build', 'activity'])
        ->and($narrow)->toContain('What happened')
        ->and($narrow)->toContain('Mapped 12 models');
});

it('wraps a long line of activity onto as many lines as it needs, rather than cutting it off', function (): void {
    ($this->log)('Mapping your models — file paths, relations and casts for every model the project has.');
    $lines = collect($this->studio->lines(140, 30))->map(fn (string $line): string => ($this->plain)($line));

    expect($lines->implode(' '))->toContain('Mapping')->toContain('models')->toContain('relations')->toContain('casts')->toContain('every')->toContain('has.')
        ->and($lines->implode(''))->not->toContain('has…')->not->toContain('casts…')
        ->and($lines->map(fn (string $line): int => mb_strwidth($line))->unique()->all())->toBe([140]);
});

it('scrolls the activity on its own when the wheel turns over it, keeping its heading, and the page when it turns anywhere else', function (): void {
    collect(range(1, 12))->each(fn (int $n): mixed => ($this->log)("Step {$n} finished"));
    $command = app(StudioCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    (fn (): int => $this->screenWidthNow = 140)->call($command);
    $studio = (fn (): ScreenContainer => $this->getScreen())->call($command);
    $rail = fn (): string => ($this->plain)(implode("\n", $studio->lines(140, 20, 'dashboard')));
    $wheel = fn (int $column, int $button): mixed => (fn (): mixed => $this->handleScreenAction($this->screenActionFor("\e[<{$button};{$column};10M")))->call($command);

    $top = $rail();
    collect(range(1, 20))->each(fn (): mixed => $wheel(130, 65));
    $scrolled = $rail();
    $wheel(10, 65);

    expect($top)->toContain('Step 12 finished')->toContain('↓ scroll for earlier')->not->toContain('Step 1 finished')
        ->and($scrolled)->toContain('│ Activity')->toContain('Step 1 finished')->not->toContain('Step 12 finished')
        ->and((fn (): int => $this->screenScroll)->call($command))->toBeGreaterThan(0)
        ->and(collect($studio->lines(140, 20, 'dashboard'))->map(fn (string $line): int => Canvas::visibleWidth($line))->unique()->all())->toBe([140]);
});

it('keeps the newest activity in view while the tab beside it scrolls', function (): void {
    ($this->log)('Mapped 12 models');
    $limit = $this->studio->scrollLimit(140, 14, 'dashboard');
    $top = ($this->plain)($this->studio->render(140, 14, 'dashboard'));
    $bottom = ($this->plain)($this->studio->render(140, 14, 'dashboard', scroll: $limit));

    expect($limit)->toBeGreaterThan(0)
        ->and($top)->toContain('Mapped 12 models')
        ->and($bottom)->toContain('Mapped 12 models')->not->toBe($top);
});

it('wraps why it cannot watch rather than cutting it off', function (): void {
    config()->set('studio-cli.token', null);
    $this->watch->start();

    $rail = ($this->plain)($this->studio->render(140, 30));

    expect($rail)->toContain("Waiting for this project's token.")->toContain('shows up in this feed.');
});

it('says in the rail when the studio is lost, and that it is trying again', function (): void {
    (fn (): array => [$this->studioConnection, $this->studioRetryAt] = ['lost the studio (Could not resolve host: studio.test), trying again in 5s', time() + 5])->call($this->watch);

    $lines = collect($this->studio->lines(140, 30));
    $rail = ($this->plain)($lines->implode("\n"));

    expect($rail)->toContain('Lost the studio')->toContain('studio.test)')->toContain('trying again in')
        ->and($lines->map(fn (string $line): int => Canvas::visibleWidth($line))->unique()->all())->toBe([140]);
});

it('stays a tab at any width when the config turns the rail off', function (): void {
    config()->set('studio-cli.rail.command', null);
    $studio = app(StudioCommand::class)->screen(ScreenContainer::make());
    $studio->lines(160, 30);

    expect($studio->getRail())->toBeNull()
        ->and($studio->tabKeys())->toBe(['dashboard', 'scan', 'insights', 'build', 'activity']);
});
