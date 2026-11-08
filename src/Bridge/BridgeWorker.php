<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\Studio;

/**
 * Claims one task from the studio, runs it on this machine and reports back.
 */
class BridgeWorker
{
    public function __construct(
        private readonly Studio $studio,
        private readonly Runner $runner,
        private readonly BridgeLog $log,
    ) {}

    /**
     * @return array{id: string, ok: bool, ms: int, error: ?string, reported: bool}|null null when nothing was waiting
     */
    public function tick(): ?array
    {
        $task = $this->studio->claimAiTask();

        if ($task === null) {
            return null;
        }

        $startedAt = microtime(true);
        $checked = Limits::check($task);

        $outcome = is_string($checked)
            ? Outcome::failed($checked)
            : $this->runner->run((array) $task['payload']);

        $milliseconds = (int) round((microtime(true) - $startedAt) * 1000);

        $this->log->note($task, $this->runner->name(), $outcome, $milliseconds);

        $reported = $this->studio->submitAiTask($task['id'], $outcome->toReport());

        return ['id' => $task['id'], 'ok' => $outcome->ok, 'ms' => $milliseconds, 'error' => $outcome->error, 'reported' => $reported];
    }
}
