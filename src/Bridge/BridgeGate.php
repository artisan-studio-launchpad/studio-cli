<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * The one question every change to the developer's code asks first: is the AI
 * bridge connected? Without it nothing fixes, writes or builds. Scan, the
 * tools, the report and reading stay open.
 *
 * Connected means either: the developer said yes, a runner such as Claude
 * Code is installed and signed in, and the bridge is running; or an API key
 * saved in the app is in use because no runner is ready.
 */
class BridgeGate
{
    public const string ON = 'on';

    public const string OFF = 'off';

    public const string ATTENTION = 'attention';

    public const string KEY = 'key';

    public function __construct(private readonly BridgeDetection $detection) {}

    /**
     * on: connected through a runner. key: connected through an API key saved
     * in the app. off: consent not given, or the bridge is stopped. attention:
     * no runner is installed, or the one that is, is signed out.
     */
    public function status(): string
    {
        return match ($this->detection->state()) {
            BridgeDetection::ON => self::ON,
            BridgeDetection::KEY => self::KEY,
            BridgeDetection::SIGNED_OUT, BridgeDetection::NOT_INSTALLED => self::ATTENTION,
            default => self::OFF,
        };
    }

    public function isConnected(): bool
    {
        return in_array($this->status(), [self::ON, self::KEY], true);
    }

    /**
     * @throws BridgeNotConnected
     */
    public function require(): void
    {
        $this->isConnected() || throw new BridgeNotConnected(__('studio-cli::bridge.blocked'));
    }

    /**
     * For the line before a file is written or a commit is made.
     *
     * @throws BridgeNotConnected
     */
    public static function must(): void
    {
        app(self::class)->require();
    }
}
