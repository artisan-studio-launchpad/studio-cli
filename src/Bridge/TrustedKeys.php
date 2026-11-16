<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * The public keys a task from the studio must be signed with to be run.
 *
 * ★ THE STUDIO'S OWN KEY IS BUILT IN. Without it every developer would have to find and set a key before the
 * bridge ran anything, and a terminal with none refuses every task. It is a public key: it can only check a
 * signature, never make one. Setting STUDIO_CLI_BRIDGE_KEYS replaces it, for somebody who points this at a
 * studio of their own.
 */
final class TrustedKeys
{
    public const string STUDIO = 'apLf6TfhpD2a/jqa+MkOGtb3jZN1es1RzlsUMyXnAE4=';

    /**
     * What is in the config when it is set, otherwise the studio's own key.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $set = array_values(array_filter((array) config('studio-cli.bridge.public_keys'), fn (mixed $key): bool => is_string($key) && $key !== ''));

        return $set === [] ? [self::STUDIO] : $set;
    }
}
