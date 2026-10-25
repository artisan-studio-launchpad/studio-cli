<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

/**
 * What the terminal spent its time on during a build, kept to be sent to the
 * studio, which lays it beside its own: each check, test run, commit and wait,
 * with when it started and when it finished.
 */
final class Timeline
{
    /**
     * @var list<array{kind: string, name: string, step: ?int, file: ?string, started: float, finished: float, meta: array<string, mixed>}>
     */
    private array $events = [];

    /**
     * @param  array<string, mixed>  $meta
     */
    public function note(string $kind, string $name, float $started, float $finished, ?int $step = null, ?string $file = null, array $meta = []): void
    {
        $this->events[] = ['kind' => $kind, 'name' => mb_substr($name, 0, 200), 'step' => $step, 'file' => $file, 'started' => $started, 'finished' => $finished, 'meta' => $meta];
    }

    /**
     * What has been noted and not yet sent, handed over once.
     *
     * @return list<array{kind: string, name: string, step: ?int, file: ?string, started: float, finished: float, meta: array<string, mixed>}>
     */
    public function drain(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
