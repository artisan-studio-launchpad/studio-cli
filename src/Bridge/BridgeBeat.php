<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\StateFile;

/**
 * Whether the bridge is running on this machine for this project, so every
 * process that is about to change a file can ask, and so a second studio never
 * starts a second bridge.
 *
 * The bridge holds a lock on one file for as long as it lives. The system
 * lets go of that lock when the process ends for any reason, a crash or a kill
 * included, so a bridge that died is never counted and never has to be cleaned
 * up after.
 */
final class BridgeBeat
{
    /** @var resource|null */
    private static $held = null;

    public static function path(): string
    {
        return StateFile::for('bridge-running', base_path(), '.lock');
    }

    /**
     * Takes the lock for this process. False when another process already is
     * the bridge, and nothing is changed then.
     */
    public static function mark(): bool
    {
        self::unmark();

        $file = @fopen(self::path(), 'c');

        if ($file === false) {
            return false;
        }

        if (! flock($file, LOCK_EX | LOCK_NB)) {
            fclose($file);

            return false;
        }

        ftruncate($file, 0);
        fwrite($file, (string) getmypid());
        fflush($file);

        self::$held = $file;

        return true;
    }

    /**
     * Lets go of the lock, if this process holds it.
     */
    public static function unmark(): void
    {
        if (self::$held === null) {
            return;
        }

        flock(self::$held, LOCK_UN);
        fclose(self::$held);

        self::$held = null;
    }

    /**
     * Whether some process holds the lock: asked by trying to take it.
     */
    public static function isRunning(): bool
    {
        $file = @fopen(self::path(), 'c');

        if ($file === false) {
            return false;
        }

        $free = flock($file, LOCK_EX | LOCK_NB);

        $free && flock($file, LOCK_UN);
        fclose($file);

        return ! $free;
    }
}
