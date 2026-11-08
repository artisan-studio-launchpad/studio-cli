<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\StateFile;

/**
 * Whether the bridge is running on this machine for this project, so every
 * process that is about to change a file can ask. The bridge writes its own
 * process id when it starts and takes it away when it stops; a process that
 * has died without doing so is not counted.
 */
final class BridgeBeat
{
    public static function path(): string
    {
        return StateFile::for('bridge-running', base_path(), '.pid');
    }

    /**
     * Records this process as the bridge, unless another one already is.
     */
    public static function mark(): void
    {
        if (! self::isRunning()) {
            @file_put_contents(self::path(), (string) getmypid(), LOCK_EX);
        }
    }

    /**
     * Takes this process off, if it is the one recorded.
     */
    public static function unmark(): void
    {
        if (self::pid() === getmypid()) {
            @unlink(self::path());
        }
    }

    public static function isRunning(): bool
    {
        return BackgroundTasks::isAlive(self::pid());
    }

    private static function pid(): ?int
    {
        $text = is_file(self::path()) ? trim((string) @file_get_contents(self::path())) : '';

        return ctype_digit($text) ? (int) $text : null;
    }
}
