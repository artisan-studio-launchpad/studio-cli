<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

/**
 * Where the desktop player is: the one the package ships in its own bin folder, found from where this
 * class sits, so it never depends on the name the package was installed under. A path set in
 * STUDIO_CLI_PRESENCE_PLAYER is used as it is.
 */
final class AvatarPlayer
{
    public static function path(): string
    {
        $set = config('studio-cli.presence.player');

        return is_string($set) && $set !== '' ? $set : dirname(__DIR__).'/bin/avatardesktop';
    }
}
