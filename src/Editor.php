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

        return $editor !== null && $paths !== [] && collect($paths)
            ->map(fn (string $path): bool => Process::run([...$this->opener(), $this->urlFor($editor, $this->root.'/'.ltrim($path, '/'))])->successful())
            ->every(fn (bool $opened): bool => $opened);
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
            'Darwin' => ['open'],
            'Windows' => ['cmd', '/c', 'start', ''],
            default => ['xdg-open'],
        };
    }
}
