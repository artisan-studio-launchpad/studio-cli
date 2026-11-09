<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Prototype;

use Closure;

/**
 * The class names on a page and in its stylesheets, read on this machine.
 * Only names are ever taken from either: the page itself and the stylesheets
 * stay here, and the studio is told just the names the page uses.
 */
final class PageClasses
{
    private const int MOST_USED = 20000;

    private const int LONGEST_NAME = 120;

    /**
     * @param  Closure(string): ?string  $fetch  returns a stylesheet's text from its address
     */
    public function __construct(private readonly Closure $fetch) {}

    /**
     * Each class the page puts on an element, once, without those built from
     * a template.
     *
     * @return list<string>
     */
    public function usedIn(string $dom): array
    {
        preg_match_all('/\sclass="([^"]*)"/', $dom, $found);

        return array_slice(array_values(array_unique(array_filter(
            preg_split('/\s+/', html_entity_decode(implode(' ', $found[1]))) ?: [],
            fn (string $class): bool => $class !== '' && strlen($class) <= self::LONGEST_NAME && ! str_contains($class, '{') && ! str_contains($class, '$'),
        ))), 0, self::MOST_USED);
    }

    /**
     * The addresses of the stylesheets the page links.
     *
     * @return list<string>
     */
    public function sheetsOf(string $dom, string $pageUrl): array
    {
        preg_match_all('/<link\b[^>]*\brel="stylesheet"[^>]*>/i', $dom, $links);

        return array_values(array_unique(array_filter(array_map(function (string $link) use ($pageUrl): ?string {
            if (preg_match('/\bhref="([^"]+)"/i', $link, $href) !== 1) {
                return null;
            }

            $address = html_entity_decode($href[1]);

            return match (true) {
                str_starts_with($address, 'http') => $address,
                str_starts_with($address, '//') => 'https:'.$address,
                default => rtrim((string) preg_replace('#^(https?://[^/]+).*$#', '$1', $pageUrl), '/').'/'.ltrim($address, '/'),
            };
        }, $links[0]))));
    }

    /**
     * The class names these stylesheets define, as a set.
     *
     * @param  list<string>  $sheets
     * @return array<string, int>
     */
    public function definedIn(array $sheets): array
    {
        $css = implode("\n", array_map(fn (string $url): string => ($this->fetch)($url) ?? '', $sheets));

        preg_match_all('/\.((?:\\\\.|[A-Za-z0-9_-])+)/', $css, $found);

        return array_flip(array_map(fn (string $name): string => (string) preg_replace('/\\\\(.)/', '$1', $name), $found[1]));
    }

    /**
     * The files in the project that the page's stylesheets are served from,
     * when a dev server serves them by their path, such as
     * /resources/css/app.css.
     *
     * @param  list<string>  $sheets
     * @return list<string>
     */
    public function entries(array $sheets, string $root): array
    {
        return array_values(array_filter(array_map(
            fn (string $url): string => rtrim($root, '/').'/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/'),
            array_filter($sheets, fn (string $url): bool => str_ends_with((string) parse_url($url, PHP_URL_PATH), '.css')),
        ), fn (string $file): bool => ! str_contains($file, '..') && is_file($file)));
    }
}
