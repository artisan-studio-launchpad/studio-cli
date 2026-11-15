<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class AvatarClips
{
    public const string STUDIO = 'studio';

    /**
     * Where the clips for the screens before linking are fetched from when the config says nothing.
     *
     * ★ A PUBLISHED CONFIG HAS NO `public_url`. Laravel merges a package's config one level deep, so an app that
     * published `config/studio-cli.php` has its own `presence` block and none of the keys added since. The
     * address is read with this as the default, so an existing install works without republishing, and
     * setting it to an empty string still switches the download off.
     */
    public const string PUBLIC_URL = 'https://artisan-studio-s3.lon1.cdn.digitaloceanspaces.com/avatars';

    private const string MANIFEST = 'manifest.json';

    /**
     * The clips the screens before linking use, by the name they have both on the public address and in
     * the cache. For the beta it is the idle typing clip only: the welcome and token screens rest in it
     * with their words as the caption. A line for each screen, `cli-workflow_welcome` and
     * `cli-workflow_token`, is added here once it is recorded, and the screens say it with no other change.
     */
    public const array BEFORE_LINKING = ['cli-workflow_started'];

    public function __construct(private Studio $studio) {}

    /**
     * ★ BEFORE LINKING SHE IS STREAMED, NOT STORED. The player is handed the public address of her idle clip and
     * plays it from there, so no clip is kept on a developer's machine for these screens. Setting
     * `presence.stream` to false falls back to downloading it once into the per-user cache folder. Read with
     * a default, because a published config has none of the keys added since it was published.
     */
    public function streams(): bool
    {
        return (bool) config('studio-cli.presence.stream', true);
    }

    /**
     * The public address of her idle clip for the screens before linking, or null when there is none.
     */
    public function idleClipUrl(): ?string
    {
        $base = rtrim((string) config('studio-cli.presence.public_url', self::PUBLIC_URL), '/');

        return $base === '' ? null : "{$base}/".self::BEFORE_LINKING[0].'.mov';
    }

    /**
     * The folder the fallback keeps the clip in: the user's own cache, outside any project, so nothing is
     * written under the project's storage and the system may clear it.
     */
    public function beforeLinkingFolder(): string
    {
        $configured = trim((string) config('studio-cli.presence.fallback_cache', ''));
        $home = (string) (getenv('HOME') ?: sys_get_temp_dir());

        return rtrim($configured !== '' ? $configured : match (PHP_OS_FAMILY) {
            'Darwin' => "{$home}/Library/Caches/artisan-studio/avatar",
            default => ((string) (getenv('XDG_CACHE_HOME') ?: "{$home}/.cache")).'/artisan-studio/avatar',
        }, '/');
    }

    /**
     * The fallback clip, when it has been downloaded.
     */
    public function beforeLinkingClip(): ?string
    {
        $path = $this->beforeLinkingFolder().'/'.self::BEFORE_LINKING[0].'.mov';

        return is_file($path) ? $path : null;
    }

    /**
     * Fallback only (`presence.stream` off): fetches the clips for the screens before linking from the
     * public address into the per-user cache, those it does not have yet. No token is sent, nothing is asked
     * of the studio, and a clip that is not there (404) is simply not there yet; only a real failure is
     * reported. Streaming, which is the default, downloads nothing.
     *
     * @return array{downloaded: list<string>, kept: list<string>, failed: list<string>}
     */
    public function syncBeforeLinking(): array
    {
        $base = rtrim((string) config('studio-cli.presence.public_url', self::PUBLIC_URL), '/');
        $result = ['downloaded' => [], 'kept' => [], 'failed' => []];

        if ($base === '' || $this->streams() || ! $this->fromTheStudio()) {
            return $result;
        }

        File::ensureDirectoryExists($this->beforeLinkingFolder());

        foreach (self::BEFORE_LINKING as $stem) {
            $target = $this->beforeLinkingFolder()."/{$stem}.mov";

            if (is_file($target)) {
                $result['kept'][] = $stem;

                continue;
            }

            $fetched = $this->downloadPublic("{$base}/{$stem}.mov", $target);

            if ($fetched === true) {
                $result['downloaded'][] = $stem;
            } elseif ($fetched === false) {
                $result['failed'][] = $stem;
            }
        }

        return $result;
    }

    /**
     * True when it arrived, null when the address has no such clip, false when it could not be fetched.
     * Spaces answers 403, not 404, for a file that is not there when listing is off, so both mean the same.
     */
    private function downloadPublic(string $url, string $target): ?bool
    {
        $partial = $target.'.part';

        try {
            $response = Http::connectTimeout(5)->timeout(60)->sink($partial)->get($url);
        } catch (Throwable) {
            @unlink($partial);

            return false;
        }

        if (in_array($response->status(), [403, 404], true)) {
            @unlink($partial);

            return null;
        }

        if (! $response->successful()) {
            @unlink($partial);

            return false;
        }

        return @rename($partial, $target);
    }

    public static function stem(string $stateKey): string
    {
        return strtr($stateKey, ['::' => '_', '_' => '-']);
    }

    public function fromTheStudio(): bool
    {
        return config('studio-cli.presence.source', self::STUDIO) === self::STUDIO;
    }

    public function folder(): string
    {
        return rtrim((string) config($this->fromTheStudio() ? 'studio-cli.presence.cache' : 'studio-cli.presence.clips'), '/');
    }

    /**
     * @return array{downloaded: list<string>, kept: list<string>, failed: list<string>}
     */
    public function sync(): array
    {
        if (! $this->fromTheStudio() || ! $this->studio->isLinked()) {
            return ['downloaded' => [], 'kept' => [], 'failed' => []];
        }

        File::ensureDirectoryExists($this->folder());

        $manifest = $this->manifest();
        $wanted = collect($this->wanted());
        $stale = $wanted->filter(fn (array $desktop, string $stem): bool => ! is_file($this->path($stem)) || ($manifest[$stem] ?? null) !== $desktop['updated']);
        $downloaded = $stale->filter(fn (array $desktop, string $stem): bool => $this->download($desktop['url'], $this->path($stem)))->keys();

        $this->remember([...$manifest, ...$wanted->only($downloaded->all())->map(fn (array $desktop): ?int => $desktop['updated'])->all()]);

        return [
            'downloaded' => array_values($downloaded->all()),
            'kept' => array_values($wanted->keys()->diff($stale->keys())->all()),
            'failed' => array_values($stale->keys()->diff($downloaded)->all()),
        ];
    }

    /**
     * @return array<string, array{url: string, updated: int|null}>
     */
    private function wanted(): array
    {
        return collect($this->studio->presenceStates())
            ->mapWithKeys(fn (array $state): array => [self::stem($state['state_key']) => $state['desktop']])
            ->filter()
            ->all();
    }

    private function download(string $url, string $target): bool
    {
        $partial = $target.'.part';

        try {
            $fetched = Http::timeout(300)->sink($partial)->get($url)->successful();
        } catch (Throwable) {
            $fetched = false;
        }

        return $fetched ? rename($partial, $target) : ! @unlink($partial);
    }

    private function path(string $stem): string
    {
        return $this->folder().'/'.$stem.'.mov';
    }

    /**
     * @return array<string, int|null>
     */
    private function manifest(): array
    {
        $path = $this->folder().'/'.self::MANIFEST;

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }

    /**
     * @param  array<string, int|null>  $manifest
     */
    private function remember(array $manifest): void
    {
        file_put_contents($this->folder().'/'.self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
