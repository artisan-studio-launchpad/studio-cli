<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\BridgeCommand;
use ArtisanStudio\StudioCli\Studio;
use Closure;

/**
 * Keeps the AI bridge running for as long as the studio is open, so the
 * developer runs one command, not two. It runs as part of the studio and stops
 * with it. A machine with no Claude or Codex signed in lets it end at once, so
 * it is tried again only once a minute.
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
        private readonly ?Closure $starter = null,
    ) {}

    public function keep(): void
    {
        if (! $this->studio->isLinked() || $this->tasks->isRunning(self::TASK)) {
            return;
        }

        if ($this->triedAt !== null && time() - $this->triedAt < self::TRY_AGAIN_AFTER_SECONDS) {
            return;
        }

        $this->triedAt = time();

        ($this->starter ?? fn () => $this->tasks->start(
            self::TASK,
            'AI bridge',
            'Letting SAMI use the Claude or Codex already on this machine…',
            [BridgeCommand::SIGNATURE],
            timeout: 0,
        ))();
    }
}
