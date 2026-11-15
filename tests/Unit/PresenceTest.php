<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Presence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| The avatar reacting to a watched build
|--------------------------------------------------------------------------
|
| It mirrors the tab: every appearance is an event the watcher already
| printed, so it never says something the tab did not and never appears when
| nothing happened. All of it optional: no player, no clips, or not a Mac and
| it simply never shows up.
|
*/

beforeEach(function (): void {
    $this->clips = sys_get_temp_dir().'/avatar-clips-'.uniqid();
    mkdir($this->clips);

    /**
     * A stand-in player that records what it was told rather than opening a
     * window: the avatar is driven down a pipe now, so the thing worth
     * asserting is the line that reaches it, not that a process was started.
     */
    $this->said = $this->clips.'/said.txt';
    $this->player = $this->clips.'/player';

    file_put_contents($this->player, "#!/bin/bash\ncat >> ".escapeshellarg($this->said)."\n");
    chmod($this->player, 0755);

    config()->set('studio-cli.presence.enabled', true);
    config()->set('studio-cli.presence.player', $this->player);
    config()->set('studio-cli.presence.clips', $this->clips);
    config()->set('studio-cli.presence.source', 'local');

    $this->avatar = new Presence;
});

afterEach(function (): void {
    array_map('unlink', glob($this->clips.'/*') ?: []);
    @rmdir($this->clips);
});

function aClipFor(string $state): void
{
    touch(test()->clips.'/'.$state.'.mov');
}

/** What the avatar was told to play, once the pipe has been closed. */
function whatItWasTold(): string
{
    test()->avatar->dismiss();

    usleep(200_000);

    return (string) @file_get_contents(test()->said);
}

it('plays the clip a kind of event calls for', function (): void {
    aClipFor('cli-workflow_done');

    test()->avatar->react(['kind' => 'done']);

    expect(whatItWasTold())->toContain('cli-workflow_done.mov');
});

/**
 * ★ IDLE IS THE FALLBACK, NOT AN ERROR. A studio mid-build has clips for some
 * states and not others — that is the normal condition — so a state nobody has
 * filmed yet plays the idle clip rather than failing or skipping the event.
 */
it('falls back to idle when that state has no clip', function (): void {
    aClipFor('cli-workflow_started');

    test()->avatar->react(['kind' => 'done']);

    expect(whatItWasTold())->toContain('cli-workflow_started.mov');
});

it('stays away when there are no clips at all', function (): void {
    test()->avatar->react(['kind' => 'done']);

    expect(whatItWasTold())->toBe('');
});

it('stays away when the avatar is turned off', function (): void {
    aClipFor('cli-workflow_started');
    config()->set('studio-cli.presence.enabled', false);

    test()->avatar->react(['kind' => 'done']);

    expect(whatItWasTold())->toBe('');
});

it('stays away when the player was never built', function (): void {
    aClipFor('cli-workflow_started');
    config()->set('studio-cli.presence.player', '/nowhere/avatardesktop');

    test()->avatar->react(['kind' => 'done']);

    expect(whatItWasTold())->toBe('');
});

/**
 * An unrecognised kind is still an event worth being present for: the idle
 * clip plays rather than the avatar deciding the build is not happening.
 */
it('is present for an event it has no reaction to', function (): void {
    aClipFor('cli-workflow_started');

    test()->avatar->react(['kind' => 'something-new']);

    expect(whatItWasTold())->toContain('cli-workflow_started.mov');
});

/*
|--------------------------------------------------------------------------
| Building the player
|--------------------------------------------------------------------------
|
| ★ COMPILED ON THE MACHINE, NOT SHIPPED. A binary in a Composer package is
| something nobody can read and everybody has to trust — and it would be the
| wrong architecture for half of them anyway.
|
*/

it('refuses to build anywhere but a Mac', function (): void {
    if (PHP_OS_FAMILY === 'Darwin') {
        expect(true)->toBeTrue();

        return;
    }

    $this->artisan('artisan-studio:build-presence')
        ->expectsOutputToContain('macOS only')
        ->assertExitCode(1);
})->skip(PHP_OS_FAMILY === 'Darwin', 'Only meaningful off a Mac.');

it('compiles a player that runs', function (): void {
    $target = sys_get_temp_dir().'/avatardesktop-'.uniqid();
    config()->set('studio-cli.presence.player', $target);

    $this->artisan('artisan-studio:build-presence')->assertExitCode(0);

    expect(is_executable($target))->toBeTrue();

    @unlink($target);
})->skip(PHP_OS_FAMILY !== 'Darwin', 'Needs a Mac and a Swift compiler.');

/**
 * ★ NAMED THE WAY THE STUDIO NAMES CLIPS. States are composite keys —
 * `section_state` — and `AvatarState::fileStem()` turns one into a filename by
 * swapping the `::` variant marker to an underscore and every other underscore
 * to a dash. A clip dropped in by hand has to match what the studio would have
 * called it, or the two halves disagree about the same file.
 */
it('looks for a clip under the name the studio would give it', function (): void {
    aClipFor('cli-workflow_handover');

    test()->avatar->react(['kind' => 'inbound']);

    expect(whatItWasTold())->toContain('cli-workflow_handover.mov');
});

/**
 * A variant — `workflow_done::retry` — keeps its marker as the single
 * underscore, which is free once the others have become dashes.
 */
/**
 * ★ SWAPPED SIMULTANEOUSLY, NOT IN SEQUENCE. Replacing `::` and then every `_`
 * turns the underscore just written back into a dash, so `cli_workflow::done`
 * came out `cli-workflow-done` where the studio calls it `cli-workflow_done`.
 */
it('names a variant the way the studio would', function (): void {
    aClipFor('cli-workflow_done');

    $path = (fn (): ?string => $this->clipPath('cli_workflow::done'))->call(test()->avatar);

    expect($path)->not->toBeNull()
        ->and($path)->toContain('cli-workflow_done.mov');
});

/**
 * ★ A SIZE IS A PREFERENCE, NOT A FREE HAND. Too small the avatar is a smudge,
 * too large and it is a person standing in front of the work, and neither is
 * obvious until the tab is already running.
 */
it('keeps the avatar a sensible size whatever the config says', function (): void {
    config()->set('studio-cli.presence.size', 5000);

    expect((fn (): int => $this->containerHeight())->call(test()->avatar))->toBe(900);
});

it('will not shrink the avatar into a smudge', function (): void {
    config()->set('studio-cli.presence.size', 10);

    expect((fn (): int => $this->containerHeight())->call(test()->avatar))->toBe(120);
});

/**
 * ★ ONE WINDOW, MANY CLIPS. A new process for each reaction would leave a
 * moment with no window on screen, which reads as a flicker every time the
 * avatar changes what it is doing. Both clips reaching the same player is what
 * makes a swap a cut.
 */
it('swaps clips without standing the avatar up again', function (): void {
    aClipFor('cli-workflow_started');
    aClipFor('cli-workflow_done');

    test()->avatar->arrive();
    test()->avatar->react(['kind' => 'done']);

    $told = whatItWasTold();

    expect($told)->toContain('cli-workflow_started.mov')
        ->and($told)->toContain('cli-workflow_done.mov');
});

/**
 * Resting loops, because it is what the avatar does between things. A
 * reaction plays once: it is something said and finished.
 */
it('holds the resting clip and plays a reaction once', function (): void {
    aClipFor('cli-workflow_started');
    aClipFor('cli-workflow_done');

    test()->avatar->arrive();
    test()->avatar->react(['kind' => 'done']);

    $lines = array_values(array_filter(explode("\n", whatItWasTold())));

    expect(json_decode($lines[0], true)['loop'])->toBeTrue()
        ->and(json_decode($lines[1], true)['loop'])->toBeFalse();
});

/**
 * ★ THE KIND IS THE STATE. The studio owns the list of states, so this package
 * never writes it down: a `done` event plays `cli_workflow::done`, derived
 * rather than declared, and the studio can add a state without this package
 * changing at all.
 */
it('plays the state an event kind names, without being told the list', function (): void {
    aClipFor('cli-workflow_summary');

    test()->avatar->react(['kind' => 'summary']);

    expect(whatItWasTold())->toContain('cli-workflow_summary.mov');
});

/**
 * Work arriving at an artisan and work leaving one are the same handover seen
 * from either side, so both play one clip rather than the same thing filmed
 * twice.
 */
it('treats both sides of a handover as one moment', function (): void {
    aClipFor('cli-workflow_handover');

    test()->avatar->react(['kind' => 'inbound']);
    test()->avatar->react(['kind' => 'outbound']);

    $told = whatItWasTold();

    expect(substr_count($told, 'cli-workflow_handover.mov'))->toBe(2);
});

/**
 * A kind nobody has filmed for is still a moment worth being present for.
 */
it('rests through a kind that has no state of its own', function (): void {
    aClipFor('cli-workflow_started');

    test()->avatar->react(['kind' => 'something-the-studio-added-later']);

    expect(whatItWasTold())->toContain('cli-workflow_started.mov');
});

/*
|--------------------------------------------------------------------------
| Laravel decides, the player plays
|--------------------------------------------------------------------------
|
| Everything about the avatar's window comes from config and is handed to the player
| as JSON, so a new behaviour is a config key rather than a change in Swift.
|
*/

it('tells the player what to play as JSON, holding the resting clip on a loop', function (): void {
    aClipFor('cli-workflow_started');
    aClipFor('cli-workflow_done');

    test()->avatar->arrive();
    test()->avatar->react(['kind' => 'done']);

    $told = array_values(array_filter(explode("\n", whatItWasTold())));

    expect(json_decode($told[0], true))->toBe(['play' => test()->clips.'/cli-workflow_started.mov', 'loop' => true])
        ->and(json_decode($told[1], true))->toBe(['play' => test()->clips.'/cli-workflow_done.mov', 'loop' => false]);
});

it('starts the avatar where the config says, center unless told otherwise', function (?string $position, float $expected): void {
    config()->set('studio-cli.presence.window.position', $position);
    Cache::forget('studio-cli.presence.position');

    expect((fn (): float => $this->containerPosition())->call(test()->avatar))->toBe($expected);
})->with([
    'the default' => [null, 0.5],
    'center' => ['center', 0.5],
    'left' => ['left', 0.0],
    'right' => ['right', 1.0],
    'a percentage of the way across' => ['75%', 0.75],
    'a percentage past the edge' => ['140%', 1.0],
    'something it does not know' => ['somewhere', 0.5],
]);

it('remembers where the avatar was dragged and starts it there next time, unless told to forget', function (): void {
    config()->set('studio-cli.presence.window.position', 'left');
    $heard = fn (string $line): array => (fn (): array => $this->containerHeardThat($line))->call(test()->avatar);
    $position = fn (): float => (fn (): float => $this->containerPosition())->call(test()->avatar);

    $heard('{"moved":0.42}');
    $remembered = $position();

    config()->set('studio-cli.presence.window.remember_where_dragged', false);
    $heard('{"moved":0.9}');

    expect($remembered)->toBe(0.42)
        ->and($position())->toBe(0.0)
        ->and(Cache::get('studio-cli.presence.position'))->toBe(0.42);
});

it('hands the player every setting it needs in one go, including the clips to have ready', function (): void {
    aClipFor('cli-workflow_started');
    aClipFor('cli-workflow_done');
    config()->set('studio-cli.presence.window.draggable', false);
    config()->set('studio-cli.presence.playback.swap_seconds', 0.2);
    Cache::forget('studio-cli.presence.position');

    $settings = (fn (): array => $this->containerSettings())->call(test()->avatar);

    expect($settings)->toMatchArray(['position' => 1.0, 'margin' => 24, 'draggable' => false, 'follow_terminal' => true, 'wait_for_loop' => true, 'swap_seconds' => 0.2])
        ->and($settings['preload'])->toEqualCanonicalizing([test()->clips.'/cli-workflow_started.mov', test()->clips.'/cli-workflow_done.mov']);
});

/*
|--------------------------------------------------------------------------
| The screens before a project is linked
|--------------------------------------------------------------------------
|
| Her idle clip is streamed: the player is handed its public address, nothing is downloaded and nothing is
| stored. With streaming off the clip is downloaded once into the user's own cache and played from there.
|
*/

it('is ready for the screens before linking as soon as there is a window and an address to stream from', function (): void {
    Http::fake();
    config()->set('studio-cli.presence.public_url', 'https://public.test/avatar');

    expect(test()->avatar->isReady())->toBeTrue();

    config()->set('studio-cli.presence.public_url', '');

    expect(test()->avatar->isReady())->toBeFalse();

    config()->set('studio-cli.presence.public_url', 'https://public.test/avatar');
    config()->set('studio-cli.presence.enabled', false);

    expect(test()->avatar->isReady())->toBeFalse();

    Http::assertNothingSent();
});

/**
 * ★ UNTIL A SCREEN'S LINE EXISTS SHE IS STILL THERE. The clip for a screen is made later than the idle one, so
 * a screen without its own rests in the idle clip, going round, and its words are the caption. The idle clip is
 * handed to the player as its public address, to stream.
 */
it('hands the player the address of her idle clip, going round, until a screen has a clip of its own', function (): void {
    Http::fake();
    config()->set('studio-cli.presence.public_url', 'https://public.test/avatar');

    test()->avatar->speak('token');

    expect(whatItWasTold())->toContain('"play":"https://public.test/avatar/cli-workflow_started.mov"')->toContain('"loop":true');

    Http::assertNothingSent();
});

it('says the line of a screen from its own clip, once, when it has one, and streams nothing', function (): void {
    Http::fake();
    aClipFor('cli-workflow_welcome');

    test()->avatar->speak('welcome');

    expect(whatItWasTold())->toContain('cli-workflow_welcome.mov')->toContain('"loop":false')->not->toContain('https://');

    Http::assertNothingSent();
});

it('plays the downloaded copy from the per-user cache when streaming is off, and is ready only once it is there', function (): void {
    $cache = test()->clips.'/user-cache';
    mkdir($cache);
    config()->set(['studio-cli.presence.stream' => false, 'studio-cli.presence.fallback_cache' => $cache]);

    expect(test()->avatar->isReady())->toBeFalse();

    touch($cache.'/cli-workflow_started.mov');

    expect(test()->avatar->isReady())->toBeTrue();

    test()->avatar->speak('welcome');

    expect(whatItWasTold())->toContain($cache.'/cli-workflow_started.mov')->toContain('"loop":true')->not->toContain('https://');

    unlink($cache.'/cli-workflow_started.mov');
    rmdir($cache);
});

it('says nothing for a screen when she cannot be shown', function (): void {
    config()->set('studio-cli.presence.enabled', false);

    test()->avatar->speak('welcome');

    expect(whatItWasTold())->toBe('');
});
