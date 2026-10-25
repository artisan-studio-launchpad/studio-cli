<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Prototype\Prototypes;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Studio;
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

it('gives the full page and the modal the class the app\'s own layout puts on its html tag, even when it is set in a layout that layout uses', function (): void {
    $folder = sys_get_temp_dir().'/prototype-theme-'.bin2hex(random_bytes(4));
    mkdir($folder.'/components/layouts/app', 0755, true);
    file_put_contents($folder.'/components/layouts/app.blade.php', "<x-layouts.app.sidebar>\n{{ \$slot }}\n</x-layouts.app.sidebar>\n");
    file_put_contents($folder.'/components/layouts/app/sidebar.blade.php', "<!DOCTYPE html>\n<html\n    lang=\"en\"\n    class=\"dark\"\n>\n<head></head><body></body></html>\n");
    app('view')->addLocation($folder);
    config(['livewire.component_layout' => 'components.layouts.app']);

    $this->get('/prototype/'.$this->build.'?full-page')->assertSee("lang=\"en\"\n    class=\"dark\"", false);
    $this->get('/?prototype='.$this->build)->assertSee('id="studio-prototype"', false)->assertSee('class="dark"', false);

    expect(Prototypes::theme())->toBe('dark');
});

it('finishes every animation on a page asked for in still mode, so a screenshot shows the finished page, and leaves other pages alone', function (): void {
    $still = $this->get('/?prototype='.$this->build.'&studio-still=1')->assertOk();

    $still->assertSee('animation-duration:1ms', false)->assertSee('window.requestAnimationFrame', false);
    $this->get('/?prototype='.$this->build)->assertDontSee('animation-duration:1ms', false);
    $this->get('/prototype/'.$this->build.'?full-page')->assertDontSee('animation-duration:1ms', false);
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

it('serves the wireframe and the prototype of a build at their own addresses, each its own component', function (): void {
    Prototypes::at(base_path())->remember($this->build, Studio::class, Prototypes::PROTOTYPE);

    $this->get('/wireframe/'.$this->build.'?full-page')->assertOk()->assertSee('data-prototype="'.Says::class.'"', false);
    $this->get('/prototype/'.$this->build.'?full-page')->assertOk()->assertSee('data-prototype="'.Studio::class.'"', false);
    $this->get('/?wireframe='.$this->build)->assertOk()->assertSee('data-prototype="'.Says::class.'"', false);
    $this->get('/?prototype='.$this->build)->assertOk()->assertSee('data-prototype="'.Studio::class.'"', false);
});

it('shows a build that has only a wireframe at its prototype address too, and finds no prototype for a build it does not know', function (): void {
    $this->get('/prototype/'.$this->build.'?full-page')->assertOk()->assertSee('data-prototype="'.Says::class.'"', false);
    $this->get('/wireframe/01a15b59-0000-70ad-8c19-b13a3c0393a5')->assertNotFound();
    $this->get('/prototype/01a15b59-0000-70ad-8c19-b13a3c0393a5')->assertNotFound();
});
