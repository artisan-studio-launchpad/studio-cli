<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work\Concerns;

use Closure;

/**
 * Waiting for the work to come back from the studio, file by file, and
 * handing each file to the run as it lands; and splitting what is sent into
 * parts the studio takes.
 *
 * Needs from the class: $studio, $pollSeconds, $pickupSeconds, record(),
 * and pulse(), which says the run is alive.
 */
trait StreamsFromTheStudio
{
    private const int LARGEST_SENT = 400_000;

    private const int PROPOSALS_WAIT_SECONDS = 600;

    private const float PULSE_EVERY_SECONDS = 20.0;

    private float $pulsedAt = 0.0;

    /**
     * @var array<string, string>
     */
    private array $failures = [];

    abstract private function pulse(string $phase): void;

    /**
     * @param  list<array{path: string, content: string}>  $files
     * @return list<list<array{path: string, content: string}>>
     */
    private function parts(array $files): array
    {
        return collect($files)
            ->reduce(function (array $parts, array $file): array {
                $last = array_pop($parts) ?? [];
                $size = array_sum(array_map(fn (array $held): int => strlen($held['content']), $last));
                $fits = $last === [] || $size + strlen($file['content']) <= self::LARGEST_SENT;

                return $fits ? [...$parts, [...$last, $file]] : [...$parts, $last, [$file]];
            }, []);
    }

    /**
     * @param  list<string>  $paths
     */
    private function leftOut(array $paths, string $why): void
    {
        array_map(function (string $path) use ($why): void {
            $this->failures[$path] = $why;
        }, $paths);
    }

    /**
     * @param  list<string>  $paths
     * @param  bool  $counts  whether the files that land count up the step, which a second look at files already counted does not
     */
    private function streamed(int $number, int $request, array $paths, Closure $apply, bool $counts = true): void
    {
        $started = microtime(true);
        $before = $this->done[$number] ?? 0;
        $applied = [];

        do {
            $held = $this->held($request);
            $arrived = array_filter((array) ($held['files'] ?? []), fn (mixed $proposal, int|string $path): bool => is_array($proposal) && in_array($path, $paths, true) && ! in_array($path, $applied, true), ARRAY_FILTER_USE_BOTH);
            $applied = [...$applied, ...array_map(fn (array $proposal, int|string $path): string => $apply((string) $path, $proposal), $arrived, array_keys($arrived))];
            $arrived === [] || ! $counts || $this->progressed($number, $paths, min(count((array) (collect($this->batches)->firstWhere('number', $number)['files'] ?? $paths)), $before + count($applied)));
            $why = $this->unanswered(array_values(array_diff($paths, $applied)), (string) ($held['state'] ?? ''), microtime(true) - $started);
            $why === null && $this->waitedFor();
        } while ($why === null);

        $this->leftOut(array_values(array_diff($paths, $applied)), $why);
    }

    /**
     * @return ?array<string, mixed>
     */
    private function held(int $request): ?array
    {
        $mine = collect($this->studio->scanWork()['sessions'] ?? [])->first(fn (array $session): bool => (int) ($session['id'] ?? 0) === $this->session);

        return is_array($mine['prepared'] ?? null) && ($mine['prepared']['id'] ?? null) === $request ? $mine['prepared'] : null;
    }

    /**
     * @param  list<string>  $waiting
     */
    private function unanswered(array $waiting, string $state, float $waited): ?string
    {
        return match (true) {
            $waiting === [] => '',
            $state === 'failed' => 'is as it was: something went wrong in the studio while I worked on it.',
            $waited >= self::PROPOSALS_WAIT_SECONDS => 'is as it was: the studio did not answer in time.',
            in_array($state, ['', 'queued'], true) && $waited >= $this->pickupSeconds => 'is as it was: the studio did not pick the work up.',
            default => null,
        };
    }

    /**
     * @param  list<string>  $paths
     */
    private function progressed(int $number, array $paths, int $applied): void
    {
        $this->done[$number] = $applied;
        $this->record($number, $paths, 'running', null, 0, 0, null);
    }

    private function waitedFor(): void
    {
        $this->heartbeat();
        sleep($this->pollSeconds);
    }

    private function heartbeat(): void
    {
        if (microtime(true) - $this->pulsedAt >= self::PULSE_EVERY_SECONDS) {
            $this->pulse('fixing');
        }
    }
}
