<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Prototype\Prototypes;
use ArtisanStudio\StudioCli\Says;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->build = '01a15b59-5b9b-70ad-8c19-b13a3c0393a5';
    $this->registry = base_path('storage/app/studio-cli/prototypes.json');
    config(['studio-cli.prototypes.serve' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    Prototypes::at(base_path())->remember($this->build, Says::class);
    app()->instance('studio-cli.prototype.mount', fn (string $component): string => '<div data-prototype="'.$component.'">Sample insight deck</div>');
    app()->instance('studio-cli.prototype.page', fn (string $component): mixed => response('<html><body><nav>App shell</nav>Sample insight deck</body></html>'));
    Route::middleware('web')->get('/', fn () => response('<html><body><h1>Your site</h1></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
    Route::middleware('web')->get('/dashboard', fn () => redirect('/login'));
});

afterEach(function (): void {
    @unlink($this->registry);
});

it('opens the prototype inside the app\'s own layout at its build', function (): void {
    $this->get('/prototype/'.$this->build)->assertOk()->assertSee('App shell')->assertSee('Sample insight deck');
});

it('opens the prototype full page, with no app around it and no deck controls', function (): void {
    $this->get('/prototype/'.$this->build.'?full-page')
        ->assertOk()
        ->assertSee('Sample insight deck')
        ->assertDontSee('App shell')
        ->assertSee('.studio-prototype .slidewire-controls', false);
});

it('opens the prototype in a modal over whatever page of the app it is asked on, and keeps asking through a redirect', function (): void {
    $this->get('/?prototype='.$this->build)
        ->assertOk()
        ->assertSee('<h1>Your site</h1>', false)
        ->assertSee('id="studio-prototype"', false)
        ->assertSee('Sample insight deck')
        ->assertDontSee('<iframe', false);

    expect($this->get('/dashboard?prototype='.$this->build)->headers->get('Location'))->toEndWith('/login?prototype='.$this->build);
});

it('serves nothing for a build it does not know, or once serving is switched off', function (): void {
    $this->get('/prototype/0198a2b0-0000-7000-8000-000000000000')->assertNotFound();
    $this->get('/?prototype=0198a2b0-0000-7000-8000-000000000000')->assertDontSee('studio-prototype');

    config(['studio-cli.prototypes.serve' => false]);

    $this->get('/prototype/'.$this->build)->assertNotFound();
    $this->get('/?prototype='.$this->build)->assertDontSee('studio-prototype');
});

it('never serves a prototype in production, even when told to', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(Prototypes::served())->toBeFalse();
    $this->get('/prototype/'.$this->build)->assertNotFound();
    $this->get('/?prototype='.$this->build)->assertDontSee('studio-prototype');
});
