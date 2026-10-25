<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Prototype;

use Illuminate\Http\Request;

final class PrototypeController
{
    public function __invoke(Request $request, string $build, string $stage = Prototypes::PROTOTYPE): mixed
    {
        $component = Prototypes::served() ? Prototypes::at(base_path())->componentFor($build, $stage) : null;
        abort_if($component === null, 404);

        return $request->has('full-page')
            ? response(app('view')->file(Prototypes::view('full'), ['prototype' => app('studio-cli.prototype.mount')($component), 'assets' => Prototypes::assets(base_path()), 'theme' => Prototypes::theme(), 'backdrop' => Prototypes::backdrop()])->render())
            : app('studio-cli.prototype.page')($component);
    }
}
