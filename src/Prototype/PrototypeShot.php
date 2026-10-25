<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Prototype;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class PrototypeShot
{
    private const array BROWSERS = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
    ];

    private const array COMMANDS = ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome'];

    public static function browser(): ?string
    {
        $chosen = config('studio-cli.prototypes.browser');

        if (is_string($chosen) && $chosen !== '') {
            return is_file($chosen) ? $chosen : null;
        }

        return collect(self::BROWSERS)->first(fn (string $path): bool => is_file($path))
            ?? collect(self::COMMANDS)->map(fn (string $name): ?string => (new ExecutableFinder)->find($name))->first(fn (?string $path): bool => $path !== null);
    }

    /**
     * The page as a browser shows it at this size, once it has settled, as
     * base64 PNG, or null when there is no browser or it could not take it.
     */
    /**
     * @return list<string>|null
     */
    public function audit(string $url, int $width, int $height): ?array
    {
        $dom = $this->dom($url, $width, $height);

        if ($dom === null) {
            return null;
        }

        $found = preg_match('#<pre id="studio-audit"[^>]*>(.*?)</pre>#s', $dom, $matches) === 1 ? json_decode(html_entity_decode($matches[1]), true) : null;

        return is_array($found) ? array_values(array_filter($found, is_string(...))) : null;
    }

    /**
     * The page as the browser has it once it has settled, as HTML, or null
     * when there is no browser or it could not load it.
     */
    public function dom(string $url, int $width, int $height): ?string
    {
        $browser = self::browser();

        if ($browser === null) {
            return null;
        }

        $dir = sys_get_temp_dir().'/studio-cli-audit-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        try {
            $process = new Process([
                $browser,
                '--headless=new',
                '--disable-gpu',
                '--hide-scrollbars',
                '--ignore-certificate-errors',
                '--force-device-scale-factor=1',
                '--no-first-run',
                '--no-default-browser-check',
                '--user-data-dir='.$dir.'/profile',
                '--window-size='.$width.','.$height,
                '--virtual-time-budget=9000',
                '--dump-dom',
                $url.(str_contains($url, '?') ? '&' : '?').'studio-still=1',
            ], timeout: 60);
            $process->run();

            return $process->getOutput() === '' ? null : $process->getOutput();
        } catch (Throwable) {
            return null;
        } finally {
            (new Filesystem)->deleteDirectory($dir);
        }
    }

    public function take(string $url, int $width, int $height): ?string
    {
        $browser = self::browser();

        if ($browser === null) {
            return null;
        }

        $dir = sys_get_temp_dir().'/studio-cli-shot-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $file = $dir.'/prototype.png';

        try {
            $process = new Process([
                $browser,
                '--headless=new',
                '--disable-gpu',
                '--hide-scrollbars',
                '--ignore-certificate-errors',
                '--force-device-scale-factor=1',
                '--force-prefers-reduced-motion',
                '--no-first-run',
                '--no-default-browser-check',
                '--user-data-dir='.$dir.'/profile',
                '--window-size='.$width.','.$height,
                '--virtual-time-budget=9000',
                '--screenshot='.$file,
                $url.(str_contains($url, '?') ? '&' : '?').'studio-still=1',
            ], timeout: 60);
            $process->run();

            return is_file($file) && filesize($file) > 0 ? base64_encode((string) file_get_contents($file)) : null;
        } catch (Throwable) {
            return null;
        } finally {
            (new Filesystem)->deleteDirectory($dir);
        }
    }
}
