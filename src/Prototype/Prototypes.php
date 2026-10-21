<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Prototype;

use Illuminate\Support\Str;

final class Prototypes
{
    public function __construct(private readonly string $file) {}

    public static function at(string $root): self
    {
        return new self(rtrim($root, '/').'/storage/app/studio-cli/prototypes.json');
    }

    public static function view(string $name): string
    {
        return dirname(__DIR__, 2).'/resources/views/prototype/'.$name.'.blade.php';
    }

    public static function served(): bool
    {
        $serve = config('studio-cli.prototypes.serve');

        return ! app()->environment('production') && ($serve === null ? app()->environment('local') : filter_var($serve, FILTER_VALIDATE_BOOL));
    }

    /**
     * @return list<string>
     */
    public static function assets(string $root): array
    {
        $config = collect(['vite.config.ts', 'vite.config.js', 'vite.config.mjs'])
            ->map(fn (string $name): string => $root.'/'.$name)
            ->first(fn (string $path): bool => is_file($path));
        $declared = $config === null || preg_match('/input:\s*\[([^\]]*)\]/', (string) file_get_contents($config), $found) !== 1 ? [] : Str::matchAll('/[\'"]([^\'"]+)[\'"]/', $found[1])->all();
        $own = array_values(array_filter($declared, fn (string $path): bool => str_starts_with(basename($path), 'app.')));

        return array_values(array_filter($own !== [] ? $own : ($declared !== [] ? $declared : ['resources/css/app.css', 'resources/js/app.js']), fn (string $path): bool => is_file($root.'/'.$path)));
    }

    public function remember(string $build, string $component): void
    {
        is_dir(dirname($this->file)) || mkdir(dirname($this->file), 0755, true);
        file_put_contents($this->file, (string) json_encode([...$this->all(), $build => $component], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function componentFor(string $build): ?string
    {
        $component = $this->all()[$build] ?? null;

        return $component !== null && class_exists($component) ? $component : null;
    }

    /**
     * @return array<string, string>
     */
    private function all(): array
    {
        $known = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : [];

        return is_array($known) ? array_filter($known, fn (mixed $component, mixed $build): bool => is_string($component) && is_string($build), ARRAY_FILTER_USE_BOTH) : [];
    }
}
