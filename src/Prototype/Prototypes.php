<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Prototype;

use Illuminate\Foundation\Vite;
use Illuminate\Support\Str;

final class Prototypes
{
    private const int LAYOUT_HOPS = 3;

    private const string AUDIT = <<<'JS'
        (function () {
            function describe(el) {
                var cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 4).join('.') : '';
                return '<' + el.tagName.toLowerCase() + (cls ? ' .' + cls : '') + '>';
            }
            function audit() {
                var found = [];
                var width = window.innerWidth;
                var root = document.querySelector('.studio-prototype') || document.querySelector('#studio-prototype');
                var nodes = root ? root.querySelectorAll('*') : [];
                var clipped = 0, flat = 0, empty = 0;
                if (document.documentElement.scrollWidth > width + 2) {
                    found.push('The page scrolls sideways: its content is ' + document.documentElement.scrollWidth + 'px wide in a ' + width + 'px window.');
                }
                if (root && getComputedStyle(root).overflowY === 'visible' && root.scrollHeight > root.clientHeight + 2) {
                    found.push('Content runs ' + (root.scrollHeight - root.clientHeight) + 'px below the window and nothing scrolls, so it cannot be reached.');
                }
                for (var i = 0; i < nodes.length; i++) {
                    var el = nodes[i];
                    var style = getComputedStyle(el);
                    var cls = typeof el.className === 'string' ? el.className : '';
                    if (style.display === 'none' || style.visibility === 'hidden') { continue; }
                    if ((style.overflowY === 'hidden' || style.overflowY === 'clip') && el.clientHeight > 0 && el.scrollHeight > el.clientHeight + 2 && clipped < 3) {
                        clipped++;
                        found.push(describe(el) + ' cuts off ' + (el.scrollHeight - el.clientHeight) + 'px of its content at the bottom.');
                    }
                    if (/(?:^|\s)(?:from-|bg-gradient-to-|bg-linear-to-)/.test(cls) && style.backgroundImage === 'none' && flat < 3) {
                        flat++;
                        found.push(describe(el) + ' has a gradient class that draws nothing, so it shows no fill.');
                    }
                    if (el.children.length === 0 && el.parentElement && /(?:^|\s)bg-/.test(cls) && (el.offsetHeight === 0 || el.offsetWidth === 0) && el.parentElement.offsetHeight > 0 && el.parentElement.offsetWidth > 0 && empty < 3) {
                        empty++;
                        found.push(describe(el) + ' is styled as a shape but has no size (' + el.offsetWidth + 'x' + el.offsetHeight + 'px).');
                    }
                }
                var out = document.createElement('pre');
                out.id = 'studio-audit';
                out.hidden = true;
                out.style.display = 'none';
                out.textContent = JSON.stringify(found);
                document.body.appendChild(out);
            }
            if (document.readyState === 'complete') { setTimeout(audit, 1500); } else { window.addEventListener('load', function () { setTimeout(audit, 1500); }); }
        })();
        JS;

    public const string WIREFRAME = 'wireframe';

    public const string PROTOTYPE = 'prototype';

    private const string PAINT = '/^(?:dark:)?(?:bg|text|font)-/';

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
    /**
     * The app's own assets that a page does not load. A prototype shown in a
     * modal over a page that uses another bundle (a public site, a login) has
     * the styles its components need only when it brings them. Scripts come
     * too, unless the page already runs Livewire and Alpine: a second copy of
     * either would break the page.
     *
     * @param  list<string>  $assets
     * @return list<string>
     */
    public static function missingFrom(string $page, array $assets): array
    {
        $runsLivewire = stripos($page, 'livewire') !== false;

        return array_values(array_filter($assets, function (string $entry) use ($page, $runsLivewire): bool {
            $url = rescue(fn (): string => (string) app(Vite::class)->asset($entry), '', false);
            $missing = $url === '' || ! str_contains($page, (string) parse_url($url, PHP_URL_PATH));

            return $missing && (str_ends_with($entry, '.css') || ! $runsLivewire);
        }));
    }

    public static function assets(string $root): array
    {
        $config = collect(['vite.config.ts', 'vite.config.js', 'vite.config.mjs'])
            ->map(fn (string $name): string => $root.'/'.$name)
            ->first(fn (string $path): bool => is_file($path));
        $declared = $config === null || preg_match('/input:\s*\[([^\]]*)\]/', (string) file_get_contents($config), $found) !== 1 ? [] : Str::matchAll('/[\'"]([^\'"]+)[\'"]/', $found[1])->all();
        $own = array_values(array_filter($declared, fn (string $path): bool => str_starts_with(basename($path), 'app.')));

        return array_values(array_filter($own !== [] ? $own : ($declared !== [] ? $declared : ['resources/css/app.css', 'resources/js/app.js']), fn (string $path): bool => is_file($root.'/'.$path)));
    }

    public static function still(): string
    {
        return '<style>*,*::before,*::after{animation-delay:0s!important;animation-duration:1ms!important;animation-iteration-count:1!important;transition:none!important}</style>'
            .'<script>window.requestAnimationFrame=function(callback){return setTimeout(function(){callback(performance.now()+60000)},0)}</script>'
            .'<script>'.self::AUDIT.'</script>';
    }

    public static function theme(): string
    {
        return self::layoutClass('html');
    }

    public static function backdrop(): string
    {
        return implode(' ', array_filter(explode(' ', self::layoutClass('body')), fn (string $class): bool => preg_match(self::PAINT, $class) === 1));
    }

    private static function layoutClass(string $tag): string
    {
        $layout = collect([config('livewire.component_layout'), config('livewire.layout'), 'components.layouts.app', 'layouts.app'])
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->map(fn (string $name): ?string => self::viewFile($name))
            ->first(fn (?string $file): bool => $file !== null);

        return $layout === null ? '' : self::classIn($layout, $tag, self::LAYOUT_HOPS);
    }

    private static function classIn(string $file, string $tag, int $hops): string
    {
        $source = (string) file_get_contents($file);
        $found = preg_match('/<'.$tag.'\b(?:[^>"]|"[^"]*")*>/', $source, $open) === 1 ? $open[0] : null;
        $next = preg_match('/<x-(layouts?(?:\.|::)[a-z0-9.:-]+)/', $source, $parent) === 1 ? self::viewFile('components.'.str_replace('::', '.', $parent[1])) : null;

        return match (true) {
            $found !== null => preg_match('/\sclass="([^"{}@]*)"/', $found, $class) === 1 ? trim($class[1]) : '',
            $next === null || $hops === 0 => '',
            default => self::classIn($next, $tag, $hops - 1),
        };
    }

    private static function viewFile(string $name): ?string
    {
        return rescue(fn (): string => app('view')->getFinder()->find($name), null, false);
    }

    /**
     * A build has a wireframe, kept under its own uuid, and when the developer
     * asks for one a prototype beside it, kept under the uuid and the stage.
     */
    public function remember(string $build, string $component, string $stage = self::WIREFRAME): void
    {
        is_dir(dirname($this->file)) || mkdir(dirname($this->file), 0755, true);
        file_put_contents($this->file, (string) json_encode([...$this->all(), self::keyOf($build, $stage) => $component], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The component of a build's stage. A build with no prototype shows its
     * wireframe at the prototype address too, so a link made before the stages
     * were kept apart still opens.
     */
    public function componentFor(string $build, string $stage = self::WIREFRAME): ?string
    {
        $known = $this->all();
        $component = $known[self::keyOf($build, $stage)] ?? ($stage === self::PROTOTYPE ? ($known[$build] ?? null) : null);

        return $component !== null && class_exists($component) ? $component : null;
    }

    private static function keyOf(string $build, string $stage): string
    {
        return $stage === self::PROTOTYPE ? $build.':'.self::PROTOTYPE : $build;
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
