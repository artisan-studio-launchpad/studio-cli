<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * Runs a task through Codex, signed in on this machine.
 *
 * TODO before real use: only the not-signed-in path has been seen on a real
 * machine. Check the success output, the usage event and a model name end to
 * end once a signed-in Codex is at hand.
 */
final class CodexRunner implements Runner
{
    private const string BINARY = 'codex';

    public function __construct(private readonly Shell $shell) {}

    public function name(): string
    {
        return 'codex';
    }

    public function notReady(): ?string
    {
        if ($this->shell->find(self::BINARY) === null) {
            return 'Codex is not installed on this machine. Install it, run codex login, then start the bridge again.';
        }

        $status = $this->shell->run([self::BINARY, 'login', 'status'], '', $this->folder(), 20);

        return $status['exit'] === 0 && ! str_contains(strtolower($status['out'].$status['err']), 'not logged in')
            ? null
            : 'Codex is not signed in. Run codex login, then start the bridge again.';
    }

    public function run(array $payload): Outcome
    {
        $checked = Limits::check(['kind' => Limits::TEXT, 'payload' => $payload]);

        if (is_string($checked)) {
            return Outcome::failed($checked);
        }

        $last = tempnam(sys_get_temp_dir(), 'studio-codex-');

        try {
            $answered = $this->shell->run(
                [self::BINARY, 'exec', '--skip-git-repo-check', '--ephemeral', '--sandbox', 'read-only', '--json', '-o', $last, '-C', $this->folder(), '-'],
                trim($checked['instructions']."\n\n".$checked['prompt']),
                $this->folder(),
                $checked['timeout'],
            );

            if ($answered['timedOut']) {
                return Outcome::failed('Codex took longer than '.$checked['timeout'].' seconds, so the bridge stopped it.');
            }

            return $this->outcomeOf($answered, (string) @file_get_contents($last));
        } finally {
            @unlink($last);
        }
    }

    /**
     * @param  array{exit: int, out: string, err: string, timedOut: bool}  $answered
     */
    private function outcomeOf(array $answered, string $text): Outcome
    {
        $events = array_values(array_filter(array_map(fn (string $line): mixed => json_decode($line, true), explode("\n", $answered['out'])), is_array(...)));
        $usage = (array) (collect($events)->firstWhere('type', 'turn.completed')['usage'] ?? []);

        $report = [
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($usage['cached_input_tokens'] ?? 0),
            'runner' => $this->name(),
        ];

        if ($answered['exit'] !== 0 || trim($text) === '') {
            $why = (string) (collect($events)->firstWhere('type', 'error')['message'] ?? 'Codex gave no answer.');

            return Outcome::failed(str_contains($why, '401') ? 'Codex is not signed in. Run codex login, then try again.' : mb_substr($why, 0, 400), $report);
        }

        return new Outcome(true, trim($text), usage: $report);
    }

    private function folder(): string
    {
        $folder = sys_get_temp_dir().'/studio-bridge';

        if (! is_dir($folder)) {
            mkdir($folder, 0700, true);
        }

        return $folder;
    }
}
