<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work\Concerns;

/**
 * What a run on this machine tells the studio and the terminal as it goes:
 * each batch or step with its state, every line said, and why a run
 * could not start.
 *
 * Needs from the class: $studio, $progress, $journal.
 */
trait ReportsToTheStudio
{
    private const int LONGEST_NOTE = 8000;

    private const int LONGEST_LINE = 240;

    private int $session = 0;

    private string $rule = '';

    /**
     * @var list<array{number: int, files: list<string>, rule: string, state: string, commit: ?string, fixed: int, left: int, note: ?string, started: float, finished: ?float, tools: list<string>, waited: float, worked: ?float, done: int, also?: list<array{rule: string, label: string, fixed: int}>}>
     */
    private array $batches = [];

    /**
     * @var array<int, string>
     */
    private array $labels = [];

    /**
     * @var list<string>
     */
    private array $tools = [];

    /**
     * @var array<int, list<array{rule: string, label: string, fixed: int}>>
     */
    private array $also = [];

    /**
     * @var array<int, float>
     */
    private array $waited = [];

    /**
     * @var array<int, float>
     */
    private array $worked = [];

    /**
     * @var array<int, int>
     */
    private array $done = [];

    /**
     * @param  list<string>  $paths
     */
    private function record(int $number, array $paths, string $state, ?string $commit, int $fixed, int $left, ?string $note): void
    {
        $before = collect($this->batches)->firstWhere('number', $number);
        $going = in_array($state, ['queued', 'running', 'checking'], true);
        $batch = [
            'number' => $number,
            'files' => is_array($before) ? $before['files'] : $paths,
            'rule' => $this->labels[$number] ?? $this->rule,
            'state' => $state,
            'commit' => $commit,
            'fixed' => $fixed,
            'left' => $left,
            'note' => $note,
            'started' => is_array($before) && $before['state'] !== 'queued' ? $before['started'] : microtime(true),
            'finished' => $going ? null : microtime(true),
            'tools' => $this->tools,
            'waited' => $this->waited[$number] ?? 0.0,
            'worked' => $going ? null : ($this->worked[$number] ?? null),
            'done' => $this->done[$number] ?? 0,
        ];

        if (($this->also[$number] ?? []) !== []) {
            $batch['also'] = $this->also[$number];
        }

        $reasons = $going ? [] : $this->reasonsFor($batch['files']);
        $written = $going ? [] : $this->testsWrittenSoFar();

        if ($reasons !== []) {
            $batch['reasons'] = $reasons;
        }

        if ($written !== []) {
            $batch['written'] = $written;
        }

        $this->batches = [...array_values(array_filter($this->batches, fn (array $each): bool => $each['number'] !== $number)), $batch];
        usort($this->batches, fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        $this->progress->batch($batch);
        $this->studio->submitScanWork(['session' => $this->session, 'batches' => $this->batchesForTheStudio()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batchesForTheStudio(): array
    {
        return array_map(fn (array $each): array => [...$each, 'note' => $this->fitting($each['note'])], $this->batches);
    }

    private function fitting(?string $note): ?string
    {
        if ($note === null || mb_strlen($note) <= self::LONGEST_NOTE) {
            return $note;
        }

        $length = 0;
        $kept = collect(preg_split('/\n{2,}/', $note) ?: [])
            ->takeWhile(function (string $paragraph) use (&$length): bool {
                $length += mb_strlen($paragraph) + 2;

                return $length <= self::LONGEST_NOTE;
            })
            ->implode("\n\n");

        return $kept === '' ? mb_substr($note, 0, self::LONGEST_NOTE) : $kept;
    }

    private function oneLine(string $text, int $longest = self::LONGEST_LINE): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, $longest);
    }

    /**
     * The reason for each file of a batch, when the run keeps one.
     *
     * @param  list<string>  $paths
     * @return list<array{path: string, left: bool, why: string}>
     */
    private function reasonsFor(array $paths): array
    {
        return [];
    }

    /**
     * The test files a run wrote, for the studio to run them later.
     *
     * @return list<string>
     */
    private function testsWrittenSoFar(): array
    {
        return [];
    }

    private function refuse(string $name, string $mode, string $reason, bool $terminal): string
    {
        $this->progress->refused($this->session, $name, $mode, $reason);
        $this->studio->submitScanWork(['session' => $this->session, ...($terminal ? ['state' => 'refused'] : []), 'refused' => $this->oneLine($reason)]);
        $this->step($reason);

        return $reason;
    }

    private function step(string $line): void
    {
        $this->journal->note($line);

        if ($this->session > 0) {
            $this->studio->submitScanWork(['session' => $this->session, 'said' => mb_substr($line, 0, 8000)]);
        }
    }

    private function say(string $line): void
    {
        $this->progress->says($line);
        $this->step($line);
    }
}
