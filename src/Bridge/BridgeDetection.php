<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * Where the AI connection stands, in one word, for the setup screen and the
 * Settings bridge section:
 *
 *  - on: the developer said yes, a runner is ready and the bridge is running
 *  - stopped: they said yes and a runner is ready, but the bridge is not running yet
 *  - key: no runner is ready, and an API key saved in the app is in use
 *  - consent_not_given: a runner is ready, but the developer has not said yes
 *  - signed_out: a runner is installed, but signed out
 *  - not_installed: no runner is installed
 *
 * Only the runners the config enables count.
 */
class BridgeDetection
{
    public const string ON = 'on';

    public const string STOPPED = 'stopped';

    public const string KEY = 'key';

    public const string CONSENT_NOT_GIVEN = 'consent_not_given';

    public const string SIGNED_OUT = 'signed_out';

    public const string NOT_INSTALLED = 'not_installed';

    public function __construct(
        private readonly BridgeConsent $consent,
        private readonly Runners $runners,
    ) {}

    public function state(): string
    {
        $ready = $this->runners->hasReady();

        return match (true) {
            $ready && $this->consent->choice() === true => BridgeBeat::isRunning() ? self::ON : self::STOPPED,
            BridgeKey::isSaved() => self::KEY,
            $ready => self::CONSENT_NOT_GIVEN,
            $this->runners->hasInstalled() => self::SIGNED_OUT,
            default => self::NOT_INSTALLED,
        };
    }

    /**
     * Whether neither a runner nor a key is there to connect with, so the
     * screen shows how to get one.
     */
    public function isBlocked(): bool
    {
        return in_array($this->state(), [self::SIGNED_OUT, self::NOT_INSTALLED], true);
    }
}
