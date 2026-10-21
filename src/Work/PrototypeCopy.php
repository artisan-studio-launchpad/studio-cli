<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use Illuminate\Support\Str;

final class PrototypeCopy
{
    /**
     * @param  array<string, string>  $copies
     */
    public static function of(string $content, array $copies): string
    {
        return strtr($content, self::renames($copies));
    }

    /**
     * @param  array<string, string>  $copies
     * @return array{routes: string, added: list<string>, unplaced: list<string>}
     */
    public static function routed(string $routes, array $copies): array
    {
        $added = [];
        $unplaced = [];

        foreach ($copies as $prototype => $copy) {
            $route = str_starts_with($prototype, 'app/') && str_ends_with($prototype, '.php') ? self::routeFor($routes, self::classOf($prototype), self::classOf($copy)) : null;
            $placed = $route !== null && $route['after'] !== null;
            $routes = $placed ? self::inserted($routes, $route['after'], $route['line']) : $routes;
            $added = $placed ? [...$added, $route['path']] : $added;
            $unplaced = $route !== null && ! $placed ? [...$unplaced, $route['path']] : $unplaced;
        }

        return ['routes' => $routes, 'added' => $added, 'unplaced' => $unplaced];
    }

    /**
     * @param  array<string, string>  $copies
     * @return array<string, string>
     */
    private static function renames(array $copies): array
    {
        return collect($copies)
            ->flatMap(fn (string $copy, string $prototype): array => [...self::classNames($prototype, $copy), ...self::viewNames($prototype, $copy)])
            ->filter(fn (string $new, string $old): bool => $old !== $new)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function classNames(string $prototype, string $copy): array
    {
        if (! str_starts_with($prototype, 'app/') || ! str_ends_with($prototype, '.php')) {
            return [];
        }

        $old = self::classOf($prototype);
        $new = self::classOf($copy);

        return [
            'namespace '.Str::beforeLast($old, '\\').';' => 'namespace '.Str::beforeLast($new, '\\').';',
            $old => $new,
            ...str_starts_with($prototype, 'app/Livewire/') ? [
                'livewire:'.self::livewireOf($old) => 'livewire:'.self::livewireOf($new),
                "@livewire('".self::livewireOf($old)."'" => "@livewire('".self::livewireOf($new)."'",
            ] : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function viewNames(string $prototype, string $copy): array
    {
        if (! str_starts_with($prototype, 'resources/views/') || ! str_ends_with($prototype, '.blade.php')) {
            return [];
        }

        $old = self::viewOf($prototype);
        $new = self::viewOf($copy);

        return [
            "'".$old."'" => "'".$new."'",
            '"'.$old.'"' => '"'.$new.'"',
            ...str_starts_with($old, 'components.') ? ['x-'.Str::after($old, 'components.') => 'x-'.Str::after($new, 'components.')] : [],
            ...str_starts_with($prototype, 'resources/views/pages/slides/') ? [
                "'".self::deckOf($prototype)."'" => "'".self::deckOf($copy)."'",
                '"'.self::deckOf($prototype).'"' => '"'.self::deckOf($copy).'"',
            ] : [],
        ];
    }

    /**
     * @return array{path: string, line: string, after: ?int}|null
     */
    private static function routeFor(string $routes, string $prototype, string $copy): ?array
    {
        $lines = explode("\n", $routes);
        $alias = preg_match('/^use\s+\\\\?'.preg_quote($prototype, '/').'(?:\s+as\s+(\w+))?\s*;/m', $routes, $used) === 1 ? ($used[1] ?? class_basename($prototype)) : null;
        $at = collect($lines)->search(fn (string $line): bool => str_contains($line, 'Route::') && (str_contains($line, $prototype.'::class') || ($alias !== null && preg_match('/\b'.preg_quote($alias, '/').'::class/', $line) === 1)));

        if (str_contains($routes, $copy) || $at === false || preg_match('/Route::(\w+)\(\s*[\'"]([^\'"]+)[\'"]/', $lines[$at], $found) !== 1) {
            return null;
        }

        $path = (string) preg_replace('#/prototype(/|$)#', '/', $found[2], 1);
        $name = preg_match('/->name\(\s*[\'"]prototype\.([^\'"]+)[\'"]\s*\)/', $lines[$at], $named) === 1 ? $named[1] : null;

        if (str_contains($routes, "'".$path."'") || ($name !== null && str_contains($routes, "'".$name."'"))) {
            return null;
        }

        return [
            'path' => $path,
            'line' => 'Route::'.$found[1]."('".$path."', \\".$copy.'::class)'.($name === null ? '' : "->name('".$name."')").';',
            'after' => self::neighbourOf($lines, $path, $at),
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    private static function neighbourOf(array $lines, string $path, int $prototype): ?int
    {
        $wanted = array_values(array_filter(explode('/', $path)));
        $shared = fn (string $other): int => collect(array_values(array_filter(explode('/', $other))))
            ->takeWhile(fn (string $segment, int $at): bool => ($wanted[$at] ?? null) === $segment)
            ->count();

        $best = collect($lines)
            ->map(fn (string $line, int $at): array => ['at' => $at, 'shared' => $at !== $prototype && str_ends_with(rtrim($line), ';') && preg_match('/Route::\w+\(\s*[\'"]([^\'"]+)[\'"]/', $line, $found) === 1 ? $shared($found[1]) : 0])
            ->filter(fn (array $line): bool => $line['shared'] > 0)
            ->sortBy([['shared', 'desc'], ['at', 'desc']])
            ->first();

        return $best === null ? null : $best['at'];
    }

    private static function inserted(string $routes, int $after, string $line): string
    {
        $lines = explode("\n", $routes);
        $indent = Str::before($lines[$after], ltrim($lines[$after]));
        array_splice($lines, $after + 1, 0, [$indent.$line]);

        return implode("\n", $lines);
    }

    private static function classOf(string $path): string
    {
        return 'App\\'.str_replace('/', '\\', Str::between($path, 'app/', '.php'));
    }

    private static function livewireOf(string $class): string
    {
        return collect(explode('\\', Str::after($class, 'App\\Livewire\\')))->map(fn (string $part): string => Str::kebab($part))->implode('.');
    }

    private static function viewOf(string $path): string
    {
        return str_replace('/', '.', Str::between($path, 'resources/views/', '.blade.php'));
    }

    private static function deckOf(string $path): string
    {
        return Str::between($path, 'resources/views/pages/slides/', '.blade.php');
    }
}
