<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work\Concerns;

use ArtisanStudio\StudioCli\Work\WorkRun;

/**
 * Where a run stops for the developer: the question goes to the studio and
 * the terminal, and the run waits for their answer from either.
 *
 * Needs from the class: $studio, $progress, $pollSeconds, step(), pulse().
 */
trait AsksTheDeveloper
{
    private const int LARGEST_HELD = 60_000;

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $extra
     * @return array{decision: string, note: ?string}
     */
    private function ask(int $number, array $paths, string $reason, string $patch = '', array $extra = []): array
    {
        $since = microtime(true);
        $question = [
            'batch' => $number,
            'files' => $paths,
            'reason' => $reason,
            'held' => strlen($patch) <= self::LARGEST_HELD ? $patch : '',
            'kind' => 'final',
            'rule' => $this->rule,
            'options' => [WorkRun::WORKS, WorkRun::BROKE],
            'asked_at' => $since,
            ...$extra,
        ];
        $this->step('I need you: '.$reason);
        $this->progress->stopped($question);
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'stopped', 'batches' => $this->batchesForTheStudio(), 'question' => [...$question, 'reason' => (string) $this->fitting($reason)]]);

        $answer = $this->waited();
        $this->waited[$number] = ($this->waited[$number] ?? 0.0) + microtime(true) - $since;
        $this->progress->resumed();
        $this->pulse('fixing');
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'running', 'consumed_answer' => true]);
        $this->step('You said: '.$answer['decision'].($answer['note'] === null ? '' : ' ('.$answer['note'].')').'.');

        return $answer;
    }

    /**
     * @return array{decision: string, note: ?string}
     */
    private function waited(): array
    {
        while (true) {
            $local = $this->progress->takeAnswer();

            if ($local !== null) {
                return $local;
            }

            $work = $this->studio->scanWork();
            $mine = collect($work['sessions'] ?? [])->first(fn (array $session): bool => (int) ($session['id'] ?? 0) === $this->session);

            if ($work !== null && $mine === null) {
                return ['decision' => WorkRun::STOP, 'note' => null];
            }

            if (is_array($mine['answer'] ?? null) && is_string($mine['answer']['decision'] ?? null)) {
                return ['decision' => $mine['answer']['decision'], 'note' => isset($mine['answer']['note']) ? (string) $mine['answer']['note'] : null];
            }

            $this->pulse('needs-you');
            sleep($this->pollSeconds);
        }
    }
}
