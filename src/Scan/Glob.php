<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

final class Glob
{
    /** @var array<string, string> */
    private static array $compiled = [];

    public static function toRegex(string $glob): string
    {
        return self::$compiled[$glob] ??= '~^'.preg_replace_callback(
            '~\*\*/|\*\*|\*|\?|\{([^}]*)\}|[^*?{]+~',
            fn (array $part): string => match (true) {
                $part[0] === '**/' => '(?:.*/)?',
                $part[0] === '**' => '.*',
                $part[0] === '*' => '[^/]*',
                $part[0] === '?' => '[^/]',
                str_starts_with($part[0], '{') => '(?:'.implode('|', array_map(fn (string $choice): string => preg_quote($choice, '~'), explode(',', $part[1] ?? ''))).')',
                default => preg_quote($part[0], '~'),
            },
            $glob,
        ).'$~';
    }

    /**
     * @param  list<string>  $globs
     */
    public static function matchesAny(string $path, array $globs): bool
    {
        return collect($globs)->contains(fn (string $glob): bool => preg_match(self::toRegex($glob), $path) === 1);
    }
}
