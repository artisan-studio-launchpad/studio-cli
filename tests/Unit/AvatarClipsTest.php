<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\AvatarClips;
use ArtisanStudio\StudioCli\Saloon\Requests\ListPresenceStatesRequest;
use ArtisanStudio\StudioCli\StudioCliServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| The avatar's clips, from the studio
|--------------------------------------------------------------------------
|
| The studio keeps them on its own storage; a machine keeps a copy, fetched
| once and again only when the studio says a clip has changed.
|
*/

beforeEach(function (): void {
    $this->cache = sys_get_temp_dir().'/avatar-cache-'.uniqid();
    config()->set('studio-cli.presence.source', 'studio');
    config()->set('studio-cli.presence.cache', $this->cache);

    $this->states = fn (int $updated): MockResponse => MockResponse::make(['states' => [
        ['state_key' => 'cli_workflow::done', 'desktop' => ['url' => 'https://bucket.test/avatar/videos/cli.workflow_done.mov', 'updated' => $updated]],
        ['state_key' => 'cli_workflow::started', 'desktop' => ['url' => 'https://bucket.test/avatar/videos/cli.workflow_started.mov', 'updated' => 100]],
        ['state_key' => 'cli_workflow::working', 'desktop' => null],
    ]]);
});

afterEach(function (): void {
    File::deleteDirectory($this->cache);
});

it('downloads what is missing, and leaves alone what has not changed', function (): void {
    Saloon::fake([ListPresenceStatesRequest::class => ($this->states)(100)]);
    Http::fake(['bucket.test/*' => Http::response('a clip')]);

    $first = app(AvatarClips::class)->sync();
    $second = app(AvatarClips::class)->sync();

    expect($first)->toBe(['downloaded' => ['cli-workflow_done', 'cli-workflow_started'], 'kept' => [], 'failed' => []])
        ->and($second)->toBe(['downloaded' => [], 'kept' => ['cli-workflow_done', 'cli-workflow_started'], 'failed' => []])
        ->and(file_get_contents($this->cache.'/cli-workflow_done.mov'))->toBe('a clip')
        ->and(is_file($this->cache.'/cli-workflow_working.mov'))->toBeFalse();

    Http::assertSentCount(2);
});

it('downloads a clip again once the studio says it changed', function (): void {
    $take = 'first take';
    Http::fake(['bucket.test/*' => function () use (&$take) {
        return Http::response($take);
    }]);
    Saloon::fake([ListPresenceStatesRequest::class => ($this->states)(100)]);
    app(AvatarClips::class)->sync();

    $take = 'second take';
    Saloon::fake([ListPresenceStatesRequest::class => ($this->states)(200)]);
    $again = app(AvatarClips::class)->sync();

    expect($again['downloaded'])->toBe(['cli-workflow_done'])
        ->and(file_get_contents($this->cache.'/cli-workflow_done.mov'))->toBe('second take');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'cli.workflow_done.mov'));
});

it('leaves no half a clip behind when a download fails, and tries it again next time', function (): void {
    Saloon::fake([ListPresenceStatesRequest::class => ($this->states)(100)]);
    Http::fake(['bucket.test/*cli.workflow_done.mov' => Http::response('', 500), 'bucket.test/*' => Http::response('a clip')]);

    $synced = app(AvatarClips::class)->sync();

    expect($synced['failed'])->toBe(['cli-workflow_done'])
        ->and(glob($this->cache.'/cli-workflow_done*'))->toBe([]);
});

it('never downloads when the avatar\'s clips come from a folder on this machine', function (): void {
    config()->set('studio-cli.presence.source', 'local');
    config()->set('studio-cli.presence.clips', '/some/folder');
    Http::fake();

    expect(app(AvatarClips::class)->sync())->toBe(['downloaded' => [], 'kept' => [], 'failed' => []])
        ->and(app(AvatarClips::class)->folder())->toBe('/some/folder');

    Http::assertNothingSent();
});

it('names a cached clip the way the player looks for it', function (): void {
    expect(AvatarClips::stem('cli_workflow::done'))->toBe('cli-workflow_done')
        ->and(AvatarClips::stem('cli_workflow::handover'))->toBe('cli-workflow_handover');
});

/*
|--------------------------------------------------------------------------
| Before the project is linked
|--------------------------------------------------------------------------
|
| The welcome and token screens show her too, and there is no token yet. Her idle clip, the only clip for the
| beta, is streamed from its public address: the player is handed the address and nothing is kept on the
| machine. With `presence.stream` off it is downloaded once into the user's own cache folder instead, never
| into the project. No call here carries a token.
|
*/

beforeEach(function (): void {
    $this->userCache = sys_get_temp_dir().'/avatar-user-cache-'.uniqid();
});

afterEach(function (): void {
    File::deleteDirectory($this->userCache);
});

it('streams her idle clip by default, from the public address, and downloads nothing', function (): void {
    config()->set('studio-cli.presence.fallback_cache', $this->userCache);
    config()->set('studio-cli.presence.public_url', 'https://public.test/avatar/');
    Http::fake();

    $clips = app(AvatarClips::class);

    expect($clips->streams())->toBeTrue()
        ->and($clips->idleClipUrl())->toBe('https://public.test/avatar/cli-workflow_started.mov')
        ->and($clips->syncBeforeLinking())->toBe(['downloaded' => [], 'kept' => [], 'failed' => []])
        ->and(is_dir($this->userCache))->toBeFalse()
        ->and(is_dir($this->cache))->toBeFalse();

    Http::assertNothingSent();
});

it('has no address to stream from when the public address is switched off', function (): void {
    config()->set('studio-cli.presence.public_url', '');

    expect(app(AvatarClips::class)->idleClipUrl())->toBeNull();
});

it('streams from the default address when a published config has a presence block without one or without a stream setting', function (): void {
    config()->set('studio-cli.presence', ['enabled' => true, 'source' => 'studio', 'cache' => $this->cache]);

    $clips = app(AvatarClips::class);

    expect($clips->streams())->toBeTrue()->and($clips->idleClipUrl())->toBe(AvatarClips::PUBLIC_URL.'/cli-workflow_started.mov');
});

describe('with streaming off, the fallback', function (): void {
    beforeEach(function (): void {
        config()->set(['studio-cli.presence.stream' => false, 'studio-cli.presence.fallback_cache' => $this->userCache]);
    });

    it('downloads only her idle clip from the public address, with no token, once, into the per-user cache', function (): void {
        config()->set('studio-cli.presence.public_url', 'https://public.test/avatar/');
        Http::fake(['public.test/avatar/cli-workflow_started.mov' => Http::response('idle')]);

        $first = app(AvatarClips::class)->syncBeforeLinking();
        $second = app(AvatarClips::class)->syncBeforeLinking();

        expect($first)->toBe(['downloaded' => ['cli-workflow_started'], 'kept' => [], 'failed' => []])
            ->and($second)->toBe(['downloaded' => [], 'kept' => ['cli-workflow_started'], 'failed' => []])
            ->and(app(AvatarClips::class)->beforeLinkingClip())->toBe($this->userCache.'/cli-workflow_started.mov')
            ->and(file_get_contents($this->userCache.'/cli-workflow_started.mov'))->toBe('idle')
            ->and(glob($this->userCache.'/*'))->toBe([$this->userCache.'/cli-workflow_started.mov'])
            ->and(is_dir($this->cache))->toBeFalse();

        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization') || ! str_ends_with($request->url(), 'cli-workflow_started.mov'));
        Http::assertSentCount(1);
    });

    it('keeps the clip in a folder of the user\'s own, outside the project, when none is configured', function (): void {
        config()->set('studio-cli.presence.fallback_cache', '');
        $home = getenv('HOME');
        putenv('HOME=/home/someone');

        $folder = app(AvatarClips::class)->beforeLinkingFolder();

        putenv($home === false ? 'HOME' : "HOME={$home}");

        expect($folder)->toBe(PHP_OS_FAMILY === 'Darwin' ? '/home/someone/Library/Caches/artisan-studio/avatar' : '/home/someone/.cache/artisan-studio/avatar')
            ->and(str_starts_with($folder, storage_path()))->toBeFalse()
            ->and(str_starts_with($folder, base_path()))->toBeFalse();
    });

    it('treats a clip the address does not have, 404 or the 403 Spaces gives, as not there yet', function (int $status): void {
        config()->set('studio-cli.presence.public_url', 'https://public.test/avatar');
        Http::fake(['public.test/avatar/*' => Http::response('', $status)]);

        expect(app(AvatarClips::class)->syncBeforeLinking())->toBe(['downloaded' => [], 'kept' => [], 'failed' => []])
            ->and(glob($this->userCache.'/*'))->toBe([]);
    })->with([404, 403]);

    it('reports a clip that could not be fetched, leaves nothing half written, and does not throw', function (): void {
        config()->set('studio-cli.presence.public_url', 'https://public.test/avatar');
        Http::fake(['public.test/avatar/cli-workflow_started.mov' => Http::response('', 500)]);

        $failed = app(AvatarClips::class)->syncBeforeLinking();

        Http::fake(['public.test/avatar/cli-workflow_started.mov' => fn () => throw new RuntimeException('connection refused')]);

        $refused = app(AvatarClips::class)->syncBeforeLinking();

        expect($failed['failed'])->toBe(['cli-workflow_started'])->and($refused['failed'])->toBe(['cli-workflow_started'])
            ->and($failed['downloaded'])->toBe([])->and(glob($this->userCache.'/*'))->toBe([])->and(app(AvatarClips::class)->beforeLinkingClip())->toBeNull();
    });

    it('fetches nothing when there is no public address, or when the clips come from a folder of your own', function (): void {
        Http::fake();
        config()->set('studio-cli.presence.public_url', '');

        expect(app(AvatarClips::class)->syncBeforeLinking())->toBe(['downloaded' => [], 'kept' => [], 'failed' => []]);

        config()->set('studio-cli.presence.public_url', 'https://public.test/avatar');
        config()->set('studio-cli.presence.source', 'local');

        expect(app(AvatarClips::class)->syncBeforeLinking())->toBe(['downloaded' => [], 'kept' => [], 'failed' => []]);

        Http::assertNothingSent();
    });

    /**
     * ★ AN APP WITH A PUBLISHED CONFIG HAS NO `public_url`. Its own `presence` block replaces the package's, so the
     * address has to come from a default in the code, or nothing is fetched and she never appears.
     */
    it('still fetches her idle clip when a published config has a presence block without a public address', function (): void {
        config()->set('studio-cli.presence', ['enabled' => true, 'source' => 'studio', 'cache' => $this->cache, 'stream' => false, 'fallback_cache' => $this->userCache]);
        Http::fake([AvatarClips::PUBLIC_URL.'/cli-workflow_started.mov' => Http::response('idle')]);

        expect(app(AvatarClips::class)->syncBeforeLinking()['downloaded'])->toBe(['cli-workflow_started'])
            ->and(is_file($this->userCache.'/cli-workflow_started.mov'))->toBeTrue();
    });
});

/**
 * ★ A PUBLISHED CONFIG HIDES THE KEYS ADDED SINCE IT WAS PUBLISHED, and so the environment variables they read.
 * The provider fills them in from the package's own `presence` block; what the app set always wins.
 */
it('fills the keys a published presence block lacks from the package, and keeps what the app set', function (): void {
    config()->set('studio-cli.presence', ['enabled' => false, 'size' => 123, 'window' => ['margin' => 7]]);

    (fn () => $this->fillInWhatAPublishedPresenceBlockLacks())->call(app()->getProvider(StudioCliServiceProvider::class));

    expect(config('studio-cli.presence.enabled'))->toBeFalse()
        ->and(config('studio-cli.presence.size'))->toBe(123)
        ->and(config('studio-cli.presence.window.margin'))->toBe(7)
        ->and(config('studio-cli.presence.window.draggable'))->toBeTrue()
        ->and(config('studio-cli.presence'))->toHaveKeys(['public_url', 'stream', 'fallback_cache', 'cache', 'source'])
        ->and(config('studio-cli.presence.stream'))->toBeTrue()
        ->and(config('studio-cli.presence.public_url'))->toBe(AvatarClips::PUBLIC_URL);
});
