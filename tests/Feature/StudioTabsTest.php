<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Console\WatchCommand;
use ArtisanStudio\StudioCli\Events\StudioReported;
use ArtisanStudio\StudioCli\Presence;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\StudioTabs;
use ArtisanStudio\StudioCli\Terminal\Tab;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| A tab is a command
|--------------------------------------------------------------------------
|
| Anything that ships as a command and provides a tab turns up in
| `php artisan studio` without the studio being told about it.
|
*/

final class ShipsATab extends Command implements ProvidesTab
{
    protected $signature = 'studio:shipped';

    public function handle(): int
    {
        return self::SUCCESS;
    }

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Shipped')->components([Text::make('Everything shipped')]);
    }
}

beforeEach(function (): void {
    $this->plain = fn (string $screen): string => (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
    $this->labels = fn (): array => collect(app(StudioTabs::class)->tabs())->map(fn (Tab $tab): string => $tab->getLabel())->all();
    $this->watch = app(Kernel::class)->all()[WatchCommand::SIGNATURE];
    $this->studio = fn (): ScreenContainer => app(StudioCommand::class)->screen(ScreenContainer::make());
});

it('adds any command that provides a tab, in the order the config asks for, leaving out what it hides', function (): void {
    app(Kernel::class)->registerCommand(new ShipsATab);
    $discovered = ($this->labels)();

    config(['studio-cli.tabs.order' => ['studio:shipped', 'studio:watch']]);
    $ordered = ($this->labels)();

    config(['studio-cli.tabs.hidden' => ['studio:watch']]);
    $hidden = ($this->labels)();

    expect($discovered)->toBe(['Dashboard', 'Scan', 'Insights', 'Build', 'Activity', 'Shipped'])
        ->and($ordered)->toBe(['Shipped', 'Activity', 'Build', 'Dashboard', 'Insights', 'Scan'])
        ->and($hidden)->toBe(['Shipped', 'Build', 'Dashboard', 'Insights', 'Scan'])
        ->and(($this->studio)()->tabKeys())->toBe(['shipped', 'build', 'dashboard', 'insights', 'scan'])
        ->and(($this->plain)(($this->studio)()->render(120, 30, 'shipped')))->toContain('Everything shipped');
});

it('opens the studio on the tab a command provides, rather than a screen of its own', function (string $command, string $expected): void {
    putenv('COLUMNS=120');
    putenv('LINES=40');

    Artisan::call($command);
    $screen = ($this->plain)(Artisan::output());

    putenv('COLUMNS');
    putenv('LINES');

    expect($screen)->toContain($expected)->toContain('Artisan Studio');
})->with([
    'dashboard' => ['studio:dashboard', 'Best practices'],
    'insights' => ['studio:insights', 'Attempting to fix grouped issues'],
    'activity' => ['studio:watch', 'Your scans and SAMI\'s fixes show up here'],
]);

it('runs the watcher in the background once, though it fills both a tab and the rail', function (): void {
    $runners = collect(($this->studio)()->runners())->map(fn (object $runner): string => $runner::class);

    expect($runners->values()->all())->toBe([WatchCommand::class]);
});

it('passes on what the studio reports and lets the avatar react, but never replays history or a quiet change', function (): void {
    Event::fake([StudioReported::class]);
    $avatar = Mockery::mock(Presence::class)->shouldIgnoreMissing();
    app()->instance(Presence::class, $avatar);
    $watch = $this->watch;
    $heard = fn (array $event): mixed => (fn (): mixed => $this->heard($event))->call($watch);

    $heard(['type' => 'blueprint']);
    $heard(['type' => 'tools', 'tools' => ['phpstan'], 'history' => true]);
    $heard(['type' => 'changed', 'kind' => 'project', 'state' => 'abc']);
    $heard(['kind' => 'tick']);

    Event::assertDispatchedTimes(StudioReported::class, 3);
    $avatar->shouldHaveReceived('react')->once();
    $avatar->shouldHaveReceived('settle')->once();
    expect(app(ActivityLog::class)->entries())->toBe([]);
});

it('reads the studio without ever waiting on it, and says so when the connection drops', function (): void {
    Saloon::fake([ListProjectsRequest::class => MockResponse::make(['data' => [['slug' => '1', 'name' => 'Artisan Studio']]])]);
    Process::fake([
        '*curl*' => Process::describe()
            ->output(['data: {"type":"changed","kind":"project","state":"abc"}', ''])
            ->errorOutput('Could not resolve host: studio.test')
            ->exitCode(6)
            ->runsFor(iterations: 3),
    ]);
    app()->instance(Presence::class, Mockery::mock(Presence::class)->shouldIgnoreMissing());
    $watch = $this->watch;
    $screen = fn (): string => ($this->plain)(($this->studio)()->render(120, 30, 'activity'));

    $watch->start();
    $watch->tick();
    $watch->tick();
    $live = $screen();
    collect(range(1, 4))->each(fn (): mixed => $watch->tick());
    $lost = $screen();
    $watch->stop();

    expect($live)->toContain('live')
        ->and($lost)->toContain('lost the studio (Could not resolve host: studio.test), trying again in 5s');
});
