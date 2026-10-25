<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Prototype;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ShowsPrototype
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($request->query('studio-still') !== null && Prototypes::served() && $response instanceof Response && str_contains((string) $response->headers->get('Content-Type'), 'text/html') && str_contains((string) $response->getContent(), '</body>')) {
            $response->setContent(Str::replaceLast('</body>', Prototypes::still().'</body>', (string) $response->getContent()));
        }

        $stage = is_string($request->query(Prototypes::WIREFRAME)) ? Prototypes::WIREFRAME : Prototypes::PROTOTYPE;
        $build = $request->query($stage);

        if (! is_string($build) || ! $response instanceof Response || ! Prototypes::served()) {
            return $response;
        }

        if ($response->isRedirection()) {
            $location = (string) $response->headers->get('Location');
            str_contains($location, $stage.'=') || $response->headers->set('Location', $location.(str_contains($location, '?') ? '&' : '?').$stage.'='.rawurlencode($build));

            return $response;
        }

        $component = Prototypes::at(base_path())->componentFor($build, $stage);
        $page = (string) $response->getContent();

        if ($component === null || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html') || ! str_contains($page, '</body>')) {
            return $response;
        }

        $modal = app('view')->file(Prototypes::view('modal'), ['prototype' => app('studio-cli.prototype.mount')($component), 'close' => $request->fullUrlWithoutQuery([$stage]), 'theme' => Prototypes::theme(), 'assets' => Prototypes::missingFrom($page, Prototypes::assets(base_path()))])->render();
        $response->setContent(Str::replaceLast('</body>', $modal.'</body>', $page));

        return $response;
    }
}
