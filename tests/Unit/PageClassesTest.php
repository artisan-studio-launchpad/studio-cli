<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Prototype\PageClasses;
use ArtisanStudio\StudioCli\Scan\ComponentFacts;
use Illuminate\Support\Facades\View;

/*
|--------------------------------------------------------------------------
| Class names and props, read here; only names go to the studio
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->css = [
        'http://localhost:5173/resources/css/app.css' => ".flex{display:flex}.items-center{align-items:center}.md\\:grid-cols-\\[1fr_2fr\\]{x:y}\n",
    ];
    $this->classes = new PageClasses(fn (string $url): ?string => $this->css[$url] ?? null);
    $this->dom = '<html><head><link rel="stylesheet" href="http://localhost:5173/resources/css/app.css"><link rel="stylesheet" href="/build/app.css"></head><body class="flex items-center"><div class="p-4 $x md:grid-cols-[1fr_2fr]"><span class="p-4 $y">Secret text 4242</span></div></body></html>';
});

it('takes only the class names a page uses, once each, without those built from a template, and none of the page', function (): void {
    $used = $this->classes->usedIn($this->dom);

    expect($used)->toBe(['flex', 'items-center', 'p-4', 'md:grid-cols-[1fr_2fr]'])
        ->and(json_encode($used))->not->toContain('Secret');
});

it('finds the stylesheets a page links, and the class names they define, once unescaped', function (): void {
    $sheets = $this->classes->sheetsOf($this->dom, 'http://app.test/prototype/abc');
    $defined = $this->classes->definedIn($sheets);

    expect($sheets)->toBe(['http://localhost:5173/resources/css/app.css', 'http://app.test/build/app.css'])
        ->and(array_keys($defined))->toContain('flex', 'items-center', 'md:grid-cols-[1fr_2fr]')
        ->and($defined)->not->toHaveKey('p-4');
});

it('names the project files a dev server serves the stylesheets from, and no path that climbs out', function (): void {
    $root = sys_get_temp_dir().'/page-classes-'.bin2hex(random_bytes(4));
    mkdir($root.'/resources/css', 0755, true);
    touch($root.'/resources/css/app.css');

    expect($this->classes->entries(['http://localhost:5173/resources/css/app.css', 'http://localhost:5173/../etc/app.css', 'http://localhost:5173/app.js'], $root))->toBe([$root.'/resources/css/app.css']);
});

it('says which props a component declares, by name, from its class or its view, and where it is declared by file name only', function (): void {
    $views = sys_get_temp_dir().'/component-facts-'.bin2hex(random_bytes(4));
    mkdir($views.'/components/card', 0755, true);
    file_put_contents($views.'/components/card/box.blade.php', "@props(['rows', 'size' => 'md'])\n<div>{{ \$slot }}</div>\n");
    View::addLocation($views);

    expect(ComponentFacts::declared('card.box', '/some/project'))->toBe(['path' => 'box.blade.php', 'props' => ['rows', 'size']])
        ->and(ComponentFacts::declared('does-not-exist', '/some/project'))->toBeNull()
        ->and(ComponentFacts::declared('bad name!', '/some/project'))->toBeNull();
});
