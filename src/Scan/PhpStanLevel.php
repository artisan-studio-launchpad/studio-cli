<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

/**
 * What a project's own PHPStan config says, so the studio's scan can differ
 * from it only where the config says nothing, and its own files are left as
 * they are.
 *
 * - The level: read as the project writes it, to report it. The scan itself
 *   always runs at the highest level.
 * - Whether Larastan reads `casts()` on the models: a project that says so,
 *   either way, is left as it is; one that says nothing has it read, since
 *   without it every date reads as a string and every enum cast is ignored.
 */
final class PhpStanLevel
{
    private const int DEEPEST_INCLUDE = 3;

    /**
     * @param  list<string>  $configs  The config files PHPStan reads, in the order of preference.
     */
    public function __construct(
        private readonly string $root,
        private readonly array $configs,
    ) {}

    /**
     * The level as the project's config writes it, such as 5 or max, or null
     * when no config the studio can read says one.
     */
    public function label(): ?string
    {
        return $this->setting('level', '(max|\d+)');
    }

    /**
     * What the project's config says about Larastan reading `casts()`: true,
     * false, or null when it says nothing.
     */
    public function readsCasts(): ?bool
    {
        $value = $this->setting('parseModelCastsMethod', '(true|false|yes|no|on|off)');

        return $value === null ? null : in_array($value, ['true', 'yes', 'on'], true);
    }

    /**
     * Whether Larastan is part of this project's PHPStan: its config or one it
     * includes names it, or the extension installer has registered it.
     */
    public function usesLarastan(): bool
    {
        $installed = (string) @file_get_contents($this->root.'/vendor/phpstan/extension-installer/src/GeneratedConfig.php');
        $config = $this->first();

        return str_contains($installed, 'larastan/larastan') || ($config !== null && $this->mentions($config, 'larastan', 0));
    }

    private function first(): ?string
    {
        return collect($this->configs)->map(fn (string $name): string => $this->root.'/'.$name)->first(fn (string $path): bool => is_file($path));
    }

    private function setting(string $name, string $value): ?string
    {
        $config = $this->first();

        return $config === null ? null : $this->in($config, 0, $name, $value);
    }

    /**
     * What a config file says, then what the files it includes say: a setting
     * written in the file itself wins over one it includes, and of the files it
     * includes the last one wins, as PHPStan merges them.
     */
    private function in(string $file, int $depth, string $name, string $value): ?string
    {
        $neon = (string) @file_get_contents($file);

        if (preg_match('/^[ \t]+'.preg_quote($name, '/').':[ \t]*[\'"]?'.$value.'[\'"]?[ \t]*(?:#.*)?$/mi', $neon, $own) === 1) {
            return strtolower($own[1]);
        }

        return collect($this->included($file, $neon, $depth))
            ->map(fn (string $path): ?string => $this->in($path, $depth + 1, $name, $value))
            ->filter()
            ->last();
    }

    private function mentions(string $file, string $word, int $depth): bool
    {
        $neon = (string) @file_get_contents($file);

        return str_contains($neon, $word) || collect($this->included($file, $neon, $depth))->contains(fn (string $path): bool => $this->mentions($path, $word, $depth + 1));
    }

    /**
     * The project's own files a config includes, not the packages'.
     *
     * @return list<string>
     */
    private function included(string $file, string $neon, int $depth): array
    {
        if ($depth >= self::DEEPEST_INCLUDE || preg_match('/^includes:[ \t]*\n((?:[ \t]+-[^\n]*\n?)+)/m', $neon, $block) !== 1) {
            return [];
        }

        return collect(explode("\n", $block[1]))
            ->map(fn (string $line): string => trim((string) preg_replace('/^[ \t]*-[ \t]*/', '', preg_replace('/[ \t]+#.*$/', '', $line)), " \t'\""))
            ->filter(fn (string $path): bool => $path !== '' && ! str_contains($path, '%') && ! str_contains($path, 'vendor/'))
            ->map(fn (string $path): string => str_starts_with($path, '/') ? $path : dirname($file).'/'.$path)
            ->filter(fn (string $path): bool => is_file($path))
            ->values()
            ->all();
    }
}
