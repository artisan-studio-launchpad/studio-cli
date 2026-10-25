<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Prototype\Prototypes;

it('brings the app\'s styles to a modal over a page that does not load them, and its scripts only when the page does not run Livewire already', function (string $page, array $expected): void {
    expect(Prototypes::missingFrom($page, ['resources/css/app.css', 'resources/js/app.ts']))->toBe($expected);
})->with([
    'a page with Livewire and its own bundle' => ['<html><script src="/livewire/livewire.js"></script></html>', ['resources/css/app.css']],
    'a page with nothing of the app' => ['<html><body>hello</body></html>', ['resources/css/app.css', 'resources/js/app.ts']],
]);
