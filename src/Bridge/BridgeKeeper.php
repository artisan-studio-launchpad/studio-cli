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
 * a child of the studio and stops with it: the studio stops it on quitting,
 * and the bridge stops itself if the studio is gone without doing so. A
 * second studio on the same project finds the bridge already running and
 * starts none. One that stopped is started again, but not within a minute of
 * the last try, so a machine where Claude Code is missing or signed out does
 * not turn into a loop.
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
        if (! $this->consent->isAllowed() || ! $this->studio->isLinked() || $this->tasks->isRunning(self::TASK) || BridgeBeat::isRunning()) {
            return;
        }

        if ($this->triedAt !== null && now()->getTimestamp() - $this->triedAt < self::TRY_AGAIN_AFTER_SECONDS) {
            return;
        }

        $this->triedAt = now()->getTimestamp();

        ($this->starter ?? fn () => $this->tasks->start(
            self::TASK,
            __('studio-cli::bridge.task.label'),
            __('studio-cli::bridge.task.working'),
            self::command(),
            timeout: 0,
        ))();
    }

    /**
     * What the studio runs: the bridge, told which process it belongs to, so
     * it stops by itself if that one is gone.
     *
     * @return list<string>
     */
    public static function command(): array
    {
        return [BridgeCommand::SIGNATURE, '--parent='.getmypid()];
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
