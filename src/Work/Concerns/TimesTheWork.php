<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work\Concerns;

use ArtisanStudio\StudioCli\Work\Timeline;
use Closure;

/**
 * Notes how long each piece of a run takes and sends it to the studio, so a
 * build can be read back second by second.
 *
 * Needs from the class: $studio and $session.
 */
trait TimesTheWork
{
    private ?Timeline $timeline = null;

    /**
     * Runs the callback and notes how long it took, whatever it does.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  (Closure(mixed): ?string)|null  $detail  a few words about what it found, from what it returned
     * @return T
     */
    private function timed(string $kind, string $name, Closure $callback, ?int $step = null, ?string $file = null, ?Closure $detail = null): mixed
    {
        $this->timeline ??= new Timeline;
        $started = microtime(true);
        $result = null;

        try {
            return $result = $callback();
        } finally {
            $said = $detail === null ? null : $detail($result);
            $this->timeline->note($kind, $name, $started, microtime(true), $step, $file, $said === null ? [] : ['detail' => $said]);
        }
    }

    /**
     * Sends what has been timed since the last time. Never fails the run.
     */
    private function timelineSent(): void
    {
        $events = $this->timeline?->drain() ?? [];

        if ($events === [] || $this->session <= 0) {
            return;
        }

        rescue(fn () => $this->studio->submitScanWork(['session' => $this->session, 'events' => $events]), null, false);
    }
}
