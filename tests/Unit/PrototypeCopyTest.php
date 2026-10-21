<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Work\PrototypeCopy;

beforeEach(function (): void {
    $this->copies = [
        'app/Livewire/Prototype/InsightDeck.php' => 'app/Livewire/InsightDeck.php',
        'resources/views/livewire/prototype/insight-deck.blade.php' => 'resources/views/livewire/insight-deck.blade.php',
        'resources/views/pages/slides/prototype/insight-deck.blade.php' => 'resources/views/pages/slides/insight-deck.blade.php',
        'resources/views/components/prototype/finding-card.blade.php' => 'resources/views/components/finding-card.blade.php',
    ];
});

it('renames the namespace and every name of another copy, and leaves the rest exactly as it was', function (): void {
    $class = "<?php\n\nnamespace App\\Livewire\\Prototype;\n\nuse Livewire\\Component;\n\nfinal class InsightDeck extends Component\n{\n    public function render()\n    {\n        return view('livewire.prototype.insight-deck')->title('Insights');\n    }\n}\n";
    $view = "<div class=\"[contain:paint]\">\n    <livewire:slidewire::presentation-deck presentation=\"prototype/insight-deck\" />\n    <x-prototype.finding-card tone=\"danger\" />\n    <a href=\"{{ route('prototype.insights.deck') }}\">Sample</a>\n</div>\n";

    expect(PrototypeCopy::of($class, $this->copies))->toBe(str_replace(['namespace App\\Livewire\\Prototype;', "'livewire.prototype.insight-deck'"], ['namespace App\\Livewire;', "'livewire.insight-deck'"], $class))
        ->and(PrototypeCopy::of($view, $this->copies))->toBe("<div class=\"[contain:paint]\">\n    <livewire:slidewire::presentation-deck presentation=\"insight-deck\" />\n    <x-finding-card tone=\"danger\" />\n    <a href=\"{{ route('prototype.insights.deck') }}\">Sample</a>\n</div>\n");
});

it('routes the copy beside the routes of the part of the app it belongs to, never beside the public prototype route', function (): void {
    $routes = implode("\n", [
        '<?php',
        '',
        'use App\\Livewire\\Prototype\\InsightDeck as PrototypeInsightDeck;',
        '',
        "Route::middleware(['auth', 'verified'])->group(function () {",
        "    Route::get('/dashboard', Dashboard::class)->name('dashboard');",
        '    Route::middleware(EnsureProjectScanned::class)->group(function () {',
        "        Route::get('/insights', ProjectInsights::class)->name('insights');",
        "        Route::get('/insights/rulesets', Rulesets::class)->name('insights.rulesets');",
        '    });',
        '});',
        '',
        "Route::get('/prototype/insights/deck', PrototypeInsightDeck::class)->name('prototype.insights.deck');",
        '',
    ]);

    $routed = PrototypeCopy::routed($routes, $this->copies);

    expect($routed['added'])->toBe(['/insights/deck'])
        ->and($routed['unplaced'])->toBe([])
        ->and($routed['routes'])->toContain("        Route::get('/insights/rulesets', Rulesets::class)->name('insights.rulesets');\n        Route::get('/insights/deck', \\App\\Livewire\\InsightDeck::class)->name('insights.deck');\n    });")
        ->and(PrototypeCopy::routed($routed['routes'], $this->copies))->toBe(['routes' => $routed['routes'], 'added' => [], 'unplaced' => []])
        ->and(PrototypeCopy::routed(str_replace("Route::get('/insights', ProjectInsights::class)->name('insights');\n        Route::get('/insights/rulesets', Rulesets::class)->name('insights.rulesets');\n", '', $routes), $this->copies))->toMatchArray(['added' => [], 'unplaced' => ['/insights/deck']]);
});
