<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use Carbon\CarbonInterface;

/**
 * What the setup screen and the studio screen say about the AI bridge, and
 * what picking yes or no there does.
 */
final class BridgeSetup
{
    public const string YES = 'yes';

    public const string NO = 'no';

    private const int SAYS_FOR_SECONDS = 30;

    private ?CarbonInterface $sayingSince = null;

    public function __construct(
        private readonly BridgeConsent $consent,
        private readonly ClaudeAccount $account,
        private readonly BridgeKeeper $keeper,
    ) {}

    /**
     * Whether the developer has not said yes or no yet.
     */
    public function isUndecided(): bool
    {
        return $this->consent->choice() === null;
    }

    /**
     * What is missing for Claude Code to run the bridge, or null when it is
     * installed and signed in.
     */
    public function notReady(): ?string
    {
        $status = $this->account->status();

        return match (true) {
            ! $status['installed'] => __('studio-cli::bridge.setup.not_installed'),
            ! $status['signed_in'] => __('studio-cli::bridge.setup.not_signed_in'),
            default => null,
        };
    }

    /**
     * The choices the setup screen offers. Claude Code only.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        return [self::YES => __('studio-cli::bridge.consent.yes'), self::NO => __('studio-cli::bridge.consent.no')];
    }

    /**
     * Applies the developer's choice and says what happened.
     */
    public function choose(string $choice): string
    {
        if ($choice !== self::YES) {
            $this->keeper->refuse();

            return __('studio-cli::bridge.consent.refused');
        }

        $missing = $this->notReady();

        if ($missing !== null) {
            return __('studio-cli::bridge.consent.not_ready', ['reason' => $missing]);
        }

        $this->keeper->allow();

        return __('studio-cli::bridge.consent.allowed');
    }

    /**
     * The row of the settings screen.
     *
     * @return array{label: string, value: string, status: string, colour: string}
     */
    public function row(): array
    {
        $missing = $this->notReady();

        return match (true) {
            $this->consent->choice() === true => ['label' => __('studio-cli::bridge.status.row'), 'value' => __('studio-cli::bridge.status.value.on'), 'status' => $this->keeper->isRunning() ? __('studio-cli::bridge.status.running_short') : __('studio-cli::bridge.status.on'), 'colour' => 'green'],
            $this->consent->choice() === false => ['label' => __('studio-cli::bridge.status.row'), 'value' => __('studio-cli::bridge.status.value.off'), 'status' => __('studio-cli::bridge.status.off'), 'colour' => 'dim'],
            $missing !== null => ['label' => __('studio-cli::bridge.status.row'), 'value' => $missing, 'status' => __('studio-cli::bridge.status.off'), 'colour' => 'amber'],
            default => ['label' => __('studio-cli::bridge.status.row'), 'value' => __('studio-cli::bridge.status.value.undecided'), 'status' => __('studio-cli::bridge.status.asks'), 'colour' => 'amber'],
        };
    }

    /**
     * The line for the studio screen: that the bridge is running, or empty.
     */
    public function line(): string
    {
        $missing = $this->missing();

        return match (true) {
            $this->keeper->isRunning() => __('studio-cli::bridge.status.running'),
            $missing !== null && $this->isSaying() => __('studio-cli::bridge.consent.not_ready', ['reason' => $missing]),
            default => '',
        };
    }

    /**
     * What is missing is said for half a minute from the first time it is
     * asked, then left to the Settings row, so it is a message and not a nag.
     */
    private function isSaying(): bool
    {
        $this->sayingSince ??= now();

        return $this->sayingSince->diffInSeconds(now(), true) < self::SAYS_FOR_SECONDS;
    }

    public function lineColour(): string
    {
        return $this->keeper->isRunning() ? 'green' : 'amber';
    }

    /**
     * What is missing when the developer has not decided and Claude Code
     * cannot run the bridge, so the setup says so once and the studio goes on.
     */
    public function missing(): ?string
    {
        return $this->isUndecided() ? $this->notReady() : null;
    }
}
