<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use Illuminate\Support\Str;
use Illuminate\View\Compilers\ComponentTagCompiler;
use ReflectionClass;
use Throwable;

/**
 * What a Blade or Livewire component declares as props, by the name it has in
 * a tag, as this app's own Blade compiler resolves it: its class's
 * constructor parameters, or the @props of its view. Names only, and where
 * it is declared. Never any of its code.
 */
final class ComponentFacts
{
    /**
     * What a component is called in a tag: x-card.box, or flux::button.
     */
    public const string NAME = '/^(flux::)?[\w.\-:]{1,100}$/';

    /**
     * @return array{path: string, props: list<string>}|null
     */
    public static function declared(string $name, string $root): ?array
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }

        try {
            $blade = app('blade.compiler');
            $resolved = (new ComponentTagCompiler($blade->getClassComponentAliases(), $blade->getClassComponentNamespaces(), $blade))->componentClass($name);

            return class_exists($resolved) ? self::ofClass($resolved, $root) : self::ofView((string) app('view')->getFinder()->find($resolved), $root);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string  $class
     * @return array{path: string, props: list<string>}
     */
    private static function ofClass(string $class, string $root): array
    {
        $reflection = new ReflectionClass($class);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];

        return ['path' => self::relative((string) $reflection->getFileName(), $root), 'props' => array_values(array_map(fn ($parameter): string => $parameter->getName(), $parameters))];
    }

    /**
     * @return array{path: string, props: list<string>}
     */
    private static function ofView(string $path, string $root): array
    {
        preg_match_all('/@(?:props|aware)\s*\(\s*\[(?<list>.*?)\]\s*\)/s', (string) file_get_contents($path), $blocks);
        preg_match_all('/(?:^|[\[,(])\s*[\'"]([\w:-]+)[\'"]/m', implode(",\n", $blocks['list']), $names);

        return ['path' => self::relative($path, $root), 'props' => array_values(array_unique($names[1]))];
    }

    /**
     * The path inside the project, or just the file's name for one outside it,
     * so no folder of this machine is told.
     */
    private static function relative(string $path, string $root): string
    {
        $real = realpath($path) ?: $path;
        $base = (realpath($root) ?: rtrim($root, '/')).'/';

        return Str::startsWith($real, $base) ? Str::after($real, $base) : basename($real);
    }
}
