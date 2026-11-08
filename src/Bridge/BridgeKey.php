<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\StateFile;

/**
 * Whether the developer has saved an API key in the app, as the studio last
 * said. The CLI never asks for the key or keeps it: it only remembers, for the
 * other commands of this checkout, that the studio holds one, and forgets it
 * when the studio has not said for a while.
 */
final class BridgeKey
{
    private const int FRESH_SECONDS = 600;

    public static function path(): string
    {
        return StateFile::for('bridge-key', base_path());
    }

    public static function remember(bool $saved): void
    {
        @file_put_contents(self::path(), (string) json_encode(['saved' => $saved, 'at' => time()]), LOCK_EX);
    }

    public static function isSaved(): bool
    {
        $json = is_file(self::path()) ? json_decode((string) @file_get_contents(self::path()), true) : null;

        return is_array($json) && ($json['saved'] ?? false) === true && time() - (int) ($json['at'] ?? 0) < self::FRESH_SECONDS;
    }
}
