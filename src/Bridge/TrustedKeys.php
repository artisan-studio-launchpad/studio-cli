<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * The public keys a task from the studio must be signed with to be run.
 *
 * ★ THE STUDIO'S OWN KEY IS BUILT IN. Without it every developer would have to find and set a key before the
 * bridge ran anything, and a terminal with none refuses every task. It is a public key: it can only check a
 * signature, never make one. Keys set in STUDIO_CLI_BRIDGE_KEYS are trusted as well, never instead of it, so
 * a key left in an environment for a studio of one's own cannot lock out the real studio.
 */
final class TrustedKeys
{
    public const string STUDIO = 'apLf6TfhpD2a/jqa+MkOGtb3jZN1es1RzlsUMyXnAE4=';

    /**
     * The studio's own key, then every key set in the config.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $set = array_filter((array) config('studio-cli.bridge.public_keys'), fn (mixed $key): bool => is_string($key) && $key !== '');

        return array_values(array_unique([self::STUDIO, ...$set]));
    }
}
