<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\StateFile;

/**
 * Where the work is up to on a job on this machine, written by the run and
 * read by the Insights tab, so the tab can show each batch, and the question
 * the work stopped to ask, and take the developer's answer.
 */
final class WorkProgress
{
    public const string WORKING = 'working';

    public const string STOPPED = 'stopped';

    public const string FINISHED = 'finished';

    public const string REFUSED = 'refused';

    private const int SHOWN_FOR_SECONDS = 1800;

    public function __construct(private readonly string $root) {}

    /**
     * @param  list<string>  $files
     */
    public function begin(int $session, string $job, string $name, string $mode, string $branch, array $files): void
    {
        $this->write([
            'phase' => self::WORKING,
            'session' => $session,
            'job' => $job,
            'name' => $name,
            'mode' => $mode,
            'branch' => $branch,
            'files' => $files,
            'at' => microtime(true),
            'pid' => getmypid(),
            'batches' => [],
            'question' => null,
            'answer' => null,
            'reason' => null,
        ]);
    }

    public function planned(int $batches): void
    {
        $this->change(fn (array $progress): array => [...$progress, 'planned' => $batches]);
    }

    public function says(string $line): void
    {
        $this->change(fn (array $progress): array => [...$progress, 'says' => $line]);
    }

    public function refused(int $session, string $name, string $mode, string $reason): void
    {
        $this->write(['phase' => self::REFUSED, 'session' => $session, 'job' => '', 'name' => $name, 'mode' => $mode, 'branch' => '', 'files' => [], 'at' => microtime(true), 'finished' => microtime(true), 'pid' => null, 'batches' => [], 'question' => null, 'answer' => null, 'reason' => $reason]);
    }

    /**
     * @param  array{number: int, files: list<string>, rule: string, state: string, commit: ?string, fixed: int, left: int, note: ?string, started: float, finished: ?float, tools: list<string>, also?: list<array{rule: string, label: string, fixed: int}>}  $batch
     */
    public function batch(array $batch): void
    {
        $this->change(fn (array $progress): array => [
            ...$progress,
            'phase' => self::WORKING,
            'batches' => [...array_values(array_filter($progress['batches'], fn (array $each): bool => $each['number'] !== $batch['number'])), $batch],
        ]);
    }

    /**
     * @param  array{batch: int, files: list<string>, reason: string, options: list<string>}  $question
     */
    public function stopped(array $question): void
    {
        $this->change(fn (array $progress): array => [...$progress, 'phase' => self::STOPPED, 'question' => $question, 'answer' => null]);
    }

    public function answered(string $decision, ?string $note): void
    {
        $this->change(fn (array $progress): array => $progress['question'] === null ? $progress : [...$progress, 'answer' => ['decision' => $decision, 'note' => $note]]);
    }

    /**
     * @return array{decision: string, note: ?string}|null
     */
    public function takeAnswer(): ?array
    {
        $taken = $this->raw()['answer'] ?? null;

        if (! is_array($taken) || ! is_string($taken['decision'] ?? null)) {
            return null;
        }

        $this->change(fn (array $progress): array => [...$progress, 'phase' => self::WORKING, 'question' => null, 'answer' => null]);

        return ['decision' => $taken['decision'], 'note' => isset($taken['note']) ? (string) $taken['note'] : null];
    }

    public function resumed(): void
    {
        $this->change(fn (array $progress): array => [...$progress, 'phase' => self::WORKING, 'question' => null, 'answer' => null]);
    }

    public function finished(?string $reason = null): void
    {
        $this->change(fn (array $progress): array => [...$progress, 'phase' => self::FINISHED, 'finished' => microtime(true), 'question' => null, 'answer' => null, 'reason' => $reason]);
    }

    /**
     * @return array{phase: string, session: int, job: string, name: string, mode: string, branch: string, files: list<string>, planned: int, at: float, finished: ?float, pid: ?int, batches: list<array{number: int, files: list<string>, rule?: string, state: string, commit: ?string, fixed: int, left: int, note: ?string, started?: float, finished?: ?float, tools?: list<string>}>, question: ?array{batch: int, files: list<string>, reason: string, options: list<string>}, answer: ?array{decision: string, note: ?string}, reason: ?string, says: ?string}|null
     */
    public function read(): ?array
    {
        $json = $this->raw();

        if ($json === null || ! isset($json['phase'], $json['session'])) {
            return null;
        }

        $progress = [
            'phase' => (string) $json['phase'],
            'session' => (int) $json['session'],
            'job' => (string) ($json['job'] ?? ''),
            'name' => (string) ($json['name'] ?? ''),
            'mode' => (string) ($json['mode'] ?? 'terminal'),
            'branch' => (string) ($json['branch'] ?? ''),
            'files' => array_values(array_filter((array) ($json['files'] ?? []), is_string(...))),
            'planned' => (int) ($json['planned'] ?? 0),
            'at' => (float) ($json['at'] ?? 0),
            'finished' => isset($json['finished']) ? (float) $json['finished'] : null,
            'pid' => isset($json['pid']) ? (int) $json['pid'] : null,
            'batches' => array_values(array_filter((array) ($json['batches'] ?? []), is_array(...))),
            'question' => is_array($json['question'] ?? null) ? $json['question'] : null,
            'answer' => is_array($json['answer'] ?? null) ? $json['answer'] : null,
            'reason' => isset($json['reason']) ? (string) $json['reason'] : null,
            'says' => isset($json['says']) ? (string) $json['says'] : null,
        ];

        return in_array($progress['phase'], [self::FINISHED, self::REFUSED], true) && microtime(true) - ($progress['finished'] ?? $progress['at']) > self::SHOWN_FOR_SECONDS ? null : $progress;
    }

    public function isAlive(): bool
    {
        $progress = $this->read();

        return $progress !== null && in_array($progress['phase'], [self::WORKING, self::STOPPED], true) && BackgroundTasks::isAlive($progress['pid']);
    }

    public function hasStopped(): bool
    {
        $progress = $this->read();

        return $progress !== null && in_array($progress['phase'], [self::WORKING, self::STOPPED], true) && ! BackgroundTasks::isAlive($progress['pid']);
    }

    public function forget(): void
    {
        @unlink($this->path());
    }

    public function path(): string
    {
        return StateFile::for('work', $this->root);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function change(callable $change): void
    {
        $lock = @fopen($this->path().'.lock', 'c');

        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }

        try {
            $progress = $this->raw();

            if ($progress !== null) {
                $this->write($change($progress));
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function raw(): ?array
    {
        $json = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function write(array $progress): void
    {
        @file_put_contents($this->path(), (string) json_encode($progress), LOCK_EX);
    }
}
