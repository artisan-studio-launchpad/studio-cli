<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Console\WatchCommand;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\StudioCliServiceProvider;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\DevCommands;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| The watcher, and the tab in `artisan dev`
|--------------------------------------------------------------------------
|
| `php artisan studio` is where Artisan Studio lives now, with the watcher as
| its Activity tab. The `artisan dev` tab is still there for anybody who wants
| it, but only when asked for.
|
*/

function watchScreen(): string
{
    $watch = app(Kernel::class)->all()[WatchCommand::SIGNATURE];
    $watch->start();
    $screen = app(StudioCommand::class)->screen(ScreenContainer::make())->render(120, 30, 'activity');
    $watch->stop();

    return (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/\e\]8;[^;\e]*;[^\e]*\e\\\\/'], '', $screen);
}

it('leaves artisan dev alone by default, now that php artisan studio is where it lives', function (): void {
    expect(collect(DevCommands::commands())->pluck('name'))->not->toContain('Artisan Studio');
})->skip(fn (): bool => ! class_exists(DevCommands::class), 'artisan dev arrived in Laravel 13');

it('runs the studio on its activity when the dev tab is turned on', function (): void {
    expect(StudioCliServiceProvider::DEV_TAB_COMMAND)->toBe('studio activity --tab');
});

it('registers the commands under their studio names', function (): void {
    $names = array_keys(app(Kernel::class)->all());

    expect($names)->toContain('studio')
        ->and($names)->toContain('studio:dashboard')
        ->and($names)->toContain('studio:insights')
        ->and($names)->toContain('studio:watch')
        ->and($names)->toContain('studio:settings')
        ->and($names)->toContain('studio:link');
});

/**
 * ★ NOT A FAILURE, THOUGH IT IS A WARNING. A tab whose process exits is
 * restarted, so failing here would put the same warning up every second.
 * The Activity tab says what is missing instead, and stays open.
 */
it('says how to link in the Activity tab, without failing at somebody', function (): void {
    config()->set('studio-cli.token', null);

    expect(watchScreen())->toContain("Waiting for this project's token.");

    $this->artisan(WatchCommand::SIGNATURE)->assertExitCode(0);
});

/**
 * ★ A REFUSED TOKEN IS NOT A MISSING ONE. Telling somebody to link when they
 * already have is how a typo becomes half an hour.
 */
it('says when the studio turned the token away', function (): void {
    Saloon::fake([
        ListProjectsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401),
    ]);

    expect(watchScreen())->toContain('Waiting for a fresh token.')
        ->not->toContain("Waiting for this project's token.");
});

/**
 * Asserted on the decision rather than on the registry: the tab is registered
 * during boot, and by the time a test runs the application has already booted
 * with whatever configuration it started with.
 *
 * @param  array<string, mixed>  $config
 */
function wouldShowTheTab(array $config): bool
{
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    return config('studio-cli.dev_tab.enabled', false)
        && (config('studio-cli.dev_tab.until_linked', false) || app(Studio::class)->isLinked());
}

it('stays out of artisan dev unless it is turned on', function (): void {
    expect(wouldShowTheTab([]))->toBeFalse()
        ->and(wouldShowTheTab(['studio-cli.dev_tab.enabled' => true]))->toBeTrue();
});

it('stays out of the dev command until the project is linked', function (): void {
    expect(wouldShowTheTab(['studio-cli.dev_tab.enabled' => true, 'studio-cli.token' => null]))->toBeFalse();
});

it('shows before linking when asked to', function (): void {
    expect(wouldShowTheTab([
        'studio-cli.dev_tab.enabled' => true,
        'studio-cli.token' => null,
        'studio-cli.dev_tab.until_linked' => true,
    ]))->toBeTrue();
});
