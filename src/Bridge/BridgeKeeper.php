<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\BridgeCommand;
use ArtisanStudio\StudioCli\Studio;
use Closure;

/**
 * Keeps the AI bridge running for as long as the studio is open, so the
 * developer runs one command, not two, and only when they said yes. It runs as
 * part of the studio and stops with it. A machine where Claude Code is not
 * installed or not signed in lets it end at once, so it is tried again only
 * once a minute.
 */
final class BridgeKeeper
{
    public const string TASK = 'bridge';

    private const int TRY_AGAIN_AFTER_SECONDS = 60;

    private ?int $triedAt = null;

    /**
     * @param  (Closure(): void)|null  $starter  what starts the bridge; a test replaces it
     */
    public function __construct(
        private readonly Studio $studio,
        private readonly BackgroundTasks $tasks,
        private readonly BridgeConsent $consent,
        private readonly ?Closure $starter = null,
    ) {}

    public function keep(): void
    {
        if (! $this->consent->isAllowed() || ! $this->studio->isLinked() || $this->tasks->isRunning(self::TASK)) {
            return;
        }

        if ($this->triedAt !== null && time() - $this->triedAt < self::TRY_AGAIN_AFTER_SECONDS) {
            return;
        }

        $this->triedAt = time();

        ($this->starter ?? fn () => $this->tasks->start(
            self::TASK,
            __('studio-cli::bridge.task.label'),
            __('studio-cli::bridge.task.working'),
            [BridgeCommand::SIGNATURE],
            timeout: 0,
        ))();
    }

    public function isRunning(): bool
    {
        return $this->tasks->isRunning(self::TASK);
    }

    /**
     * The developer said yes: remember it and start. The studio is told when
     * the bridge is really running, which the screen reports as it changes.
     */
    public function allow(): void
    {
        $this->consent->allow();
        $this->triedAt = null;
        $this->keep();
    }

    /**
     * The developer said no: remember it, tell the studio, and stop.
     */
    public function refuse(): void
    {
        $this->consent->refuse();
        $this->studio->reportBridge(BridgeGate::OFF);
        $this->tasks->stop([self::TASK]);
    }
}
