<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\ProcessTree;
use Closure;
use Symfony\Component\Process\Process;

/**
 * Keeps several bridge workers running at once, so a scan that asks for many
 * answers together is not served one at a time. Each worker is its own
 * process that claims one task, runs it and reports it, so two workers can
 * never run the same task: the studio hands each task out once.
 *
 * A worker that stops is started again, but not within seconds of its own
 * start, so one that cannot run does not turn into a loop.
 */
final class BridgeWorkers
{
    public const int RESTART_AFTER_SECONDS = 5;

    /** @var array<int, array{process: Process, startedAt: int}> */
    private array $slots = [];

    /**
     * @param  Closure(): Process  $make  starts nothing; it builds the process of one worker
     */
    public function __construct(
        private readonly int $count,
        private readonly Closure $make,
    ) {}

    /**
     * Starts the workers that are missing and gives back what the running ones
     * have printed since the last call.
     *
     * @return list<string>
     */
    public function keep(): array
    {
        $lines = [];

        foreach (range(0, max(1, $this->count) - 1) as $slot) {
            $lines = [...$lines, ...$this->linesOf($slot)];

            if (! $this->isRunning($slot) && $this->mayStart($slot)) {
                $process = ($this->make)();
                $process->start();
                $this->slots[$slot] = ['process' => $process, 'startedAt' => time()];
            }
        }

        return $lines;
    }

    public function running(): int
    {
        return count(array_filter(array_keys($this->slots), $this->isRunning(...)));
    }

    public function stop(): void
    {
        array_map(function (array $slot): void {
            $pid = $slot['process']->getPid();

            $pid === null || ProcessTree::stop($pid);

            $slot['process']->stop(2);
        }, $this->slots);

        $this->slots = [];
    }

    private function isRunning(int $slot): bool
    {
        return isset($this->slots[$slot]) && $this->slots[$slot]['process']->isRunning();
    }

    private function mayStart(int $slot): bool
    {
        return ! isset($this->slots[$slot]) || time() - $this->slots[$slot]['startedAt'] >= self::RESTART_AFTER_SECONDS;
    }

    /**
     * @return list<string>
     */
    private function linesOf(int $slot): array
    {
        if (! isset($this->slots[$slot])) {
            return [];
        }

        $process = $this->slots[$slot]['process'];
        $printed = $process->getIncrementalOutput().$process->getIncrementalErrorOutput();

        return array_values(array_filter(array_map('rtrim', explode("\n", $printed)), fn (string $line): bool => $line !== ''));
    }
}
