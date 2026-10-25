<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Illuminate\Support\Facades\Process;

final class Editor
{
    public const string PHPSTORM = 'phpstorm';

    public const string VSCODE = 'vscode';

    public const string NONE = 'none';

    public const array CHOICES = [self::PHPSTORM => 'PhpStorm', self::VSCODE => 'VS Code', self::NONE => 'None, I open the files myself'];

    private const array NAMES = [self::PHPSTORM => 'PhpStorm', self::VSCODE => 'VS Code'];

    private const string EDITORS = '/jetbrains|com\.microsoft\.VSCode/i';

    public function __construct(private readonly string $root) {}

    public function name(): ?string
    {
        return self::NAMES[$this->which() ?? ''] ?? null;
    }

    public function chosen(): ?string
    {
        $chosen = strtolower((string) config('studio-cli.review.editor'));

        return isset(self::CHOICES[$chosen]) ? $chosen : null;
    }

    public function describe(): string
    {
        return match (true) {
            $this->chosen() !== null => self::CHOICES[(string) $this->chosen()],
            $this->name() !== null => $this->name().' (from this terminal)',
            default => Says::named('Not chosen: {name} names the file to open'),
        };
    }

    /**
     * @param  list<string>  $paths
     */
    public function open(array $paths): bool
    {
        $editor = $this->which();

        if ($editor === null || $paths === []) {
            return false;
        }

        $front = $this->frontApp();
        $opened = collect($paths)
            ->map(fn (string $path): bool => Process::run([...$this->opener(), $this->urlFor($editor, $this->root.'/'.ltrim($path, '/'))])->successful())
            ->every(fn (bool $opened): bool => $opened);
        $front === null || $this->keepInFront($front);

        return $opened;
    }

    private function frontApp(): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin' || ! (bool) config('studio-cli.review.keep_front', true)) {
            return null;
        }

        $list = Process::run(['lsappinfo', 'list'])->output();
        $front = preg_match('/\(in front\)[^\n]*\n\s*bundleID="([^"]+)"/', $list, $found) === 1 ? $found[1] : null;

        return $front === null || preg_match(self::EDITORS, $front) === 1 ? null : $front;
    }

    private function keepInFront(string $front): void
    {
        $watch = sprintf(
            'n=0; quiet=0; while [ $n -lt 30 ] && [ $quiet -lt 6 ]; do sleep 0.2; n=$((n + 1)); now=$(lsappinfo list | awk \'/\(in front\)/{f=1} f && /bundleID=/{gsub(/.*bundleID="|".*/, ""); print; exit}\'); case "$now" in %s) open -b %s; quiet=1;; *) [ $quiet -gt 0 ] && quiet=$((quiet + 1));; esac; done',
            '*jetbrains*|*JetBrains*|*com.microsoft.VSCode*',
            escapeshellarg($front),
        );

        Process::run(['/bin/sh', '-c', '('.$watch.') >/dev/null 2>&1 &']);
    }

    private function which(): ?string
    {
        $chosen = $this->chosen();

        return match (true) {
            $chosen === self::NONE => null,
            $chosen !== null => $chosen,
            getenv('TERMINAL_EMULATOR') === 'JetBrains-JediTerm' => self::PHPSTORM,
            getenv('TERM_PROGRAM') === 'vscode' => self::VSCODE,
            default => null,
        };
    }

    private function urlFor(string $editor, string $path): string
    {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return $editor === self::PHPSTORM ? 'phpstorm://open?file='.$encoded : 'vscode://file'.$encoded;
    }

    /**
     * @return list<string>
     */
    private function opener(): array
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => ['open', '-g'],
            'Windows' => ['cmd', '/c', 'start', ''],
            default => ['xdg-open'],
        };
    }
}
