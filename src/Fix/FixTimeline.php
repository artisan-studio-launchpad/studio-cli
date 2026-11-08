<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\StateFile;
use Closure;

/**
 * Where a fix run's time went, step by step: reading what the scan found, each
 * trip to the studio, writing the files, Pint, Rector, every commit. A step
 * that happens many times (one request per batch) adds up under one name with
 * its count, so the whole run reads in a dozen lines.
 *
 * It is written beside the run's progress, so `studio:fix-timeline` can show
 * the last run after it has finished.
 */
final class FixTimeline
{
    public function __construct(private readonly string $root) {}

    public function begin(): void
    {
        $this->save(['began' => microtime(true), 'steps' => []]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @param  string|Closure(T): string  $detail  or a closure that describes what the work returned
     * @return T
     */
    public function time(string $step, Closure $work, string|Closure $detail = ''): mixed
    {
        $started = hrtime(true);
        $result = $work();
        $this->add($step, (int) ((hrtime(true) - $started) / 1_000_000), $detail instanceof Closure ? $detail($result) : $detail);

        return $result;
    }

    public function add(string $step, int $milliseconds, string $detail = ''): void
    {
        $handle = @fopen($this->path(), 'c+');

        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        $json = json_decode((string) stream_get_contents($handle), true);
        $timeline = is_array($json) && isset($json['began']) ? $json : ['began' => microtime(true), 'steps' => []];
        $was = (array) ($timeline['steps'][$step] ?? []);
        $timeline['steps'][$step] = [
            'ms' => (int) ($was['ms'] ?? 0) + $milliseconds,
            'count' => (int) ($was['count'] ?? 0) + 1,
            'at' => $was['at'] ?? max(0, (int) ((microtime(true) - (float) $timeline['began']) * 1000) - $milliseconds),
            'detail' => $detail !== '' ? $detail : (string) ($was['detail'] ?? ''),
        ];
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($timeline));
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @return array{began: float, steps: list<array{step: string, ms: int, count: int, at: int, detail: string}>}|null
     */
    public function last(): ?array
    {
        $json = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;

        return is_array($json) && isset($json['began']) && is_array($json['steps'] ?? null)
            ? ['began' => (float) $json['began'], 'steps' => array_values(collect($json['steps'])->map(fn (array $step, string $name): array => ['step' => $name, 'ms' => (int) $step['ms'], 'count' => (int) $step['count'], 'at' => (int) $step['at'], 'detail' => (string) $step['detail']])->sortBy('at')->all())]
            : null;
    }

    public function path(): string
    {
        return StateFile::for('fixes', $this->root, '-timeline.json');
    }

    /**
     * @param  array<string, mixed>  $timeline
     */
    private function save(array $timeline): void
    {
        @file_put_contents($this->path(), (string) json_encode($timeline), LOCK_EX);
    }
}
