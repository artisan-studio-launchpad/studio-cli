<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * The one question every change to the developer's code asks first: is the AI
 * bridge connected? Without it nothing fixes, writes or builds. Scan, the
 * tools, the report and reading stay open.
 *
 * Connected means all of: the developer said yes, Claude Code is installed
 * and signed in, and the bridge is running.
 */
class BridgeGate
{
    public const string ON = 'on';

    public const string OFF = 'off';

    public const string ATTENTION = 'attention';

    public function __construct(
        private readonly BridgeConsent $consent,
        private readonly ClaudeAccount $account,
    ) {}

    /**
     * on: connected. off: declined, not asked yet, or stopped. attention:
     * Claude Code is missing or signed out.
     */
    public function status(): string
    {
        $choice = $this->consent->choice();
        $claude = $this->account->status();

        return match (true) {
            $choice === false => self::OFF,
            ! $claude['installed'] || ! $claude['signed_in'] => self::ATTENTION,
            $choice === null => self::OFF,
            BridgeBeat::isRunning() => self::ON,
            default => self::OFF,
        };
    }

    public function isConnected(): bool
    {
        return $this->status() === self::ON;
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
