<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * A plain record, on this machine, of every task the bridge ran for the studio:
 * which program ran it, which model, how big it was, how long it took, what it
 * cost and how it ended. The developer can read it any time to see what their
 * login was used for.
 *
 * It does not keep what was asked or answered unless the developer turns the
 * debug option on. It is for this machine only: the folder and its files are
 * private to the user, git is told to ignore the folder, and a file older than
 * two weeks is removed.
 */
class BridgeLog
{
    public const int KEPT_DAYS = 14;

    private const int PROMPT_KEPT = 4000;

    public function __construct(
        private readonly string $directory,
        private readonly bool $debug = false,
    ) {}

    public function path(): string
    {
        return $this->directory.'/'.date('Y-m-d').'.jsonl';
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function note(array $task, string $runner, Outcome $outcome, int $milliseconds): void
    {
        $this->prepare();

        $payload = (array) ($task['payload'] ?? []);

        $line = [
            'at' => now()->toIso8601String(),
            'task' => $task['id'] ?? null,
            'kind' => $task['kind'] ?? null,
            'runner' => $runner,
            'model' => $payload['model'] ?? null,
            'ok' => $outcome->ok,
            'ms' => $milliseconds,
            'error' => $outcome->error,
            'tokens' => ($outcome->usage['input_tokens'] ?? 0) + ($outcome->usage['output_tokens'] ?? 0) + ($outcome->usage['cache_write_tokens'] ?? 0),
            'usage' => $outcome->usage,
            'instructions_chars' => mb_strlen((string) ($payload['instructions'] ?? '')),
            'prompt_chars' => mb_strlen((string) ($payload['prompt'] ?? '')),
        ];

        if ($this->debug) {
            $line['instructions'] = mb_substr((string) ($payload['instructions'] ?? ''), 0, self::PROMPT_KEPT);
            $line['prompt'] = mb_substr((string) ($payload['prompt'] ?? ''), 0, self::PROMPT_KEPT);
        }

        $before = umask(0177);

        try {
            file_put_contents($this->path(), json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            umask($before);
        }

        @chmod($this->path(), 0600);
    }

    /**
     * The folder private to this user, with a .gitignore that ignores all of
     * it, and none of the files older than the days kept.
     */
    private function prepare(): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }

        @chmod($this->directory, 0700);

        $ignore = $this->directory.'/.gitignore';

        if (! is_file($ignore)) {
            file_put_contents($ignore, '*'.PHP_EOL);
        }

        $oldest = time() - self::KEPT_DAYS * 86_400;

        foreach (glob($this->directory.'/*.jsonl') ?: [] as $file) {
            if ((int) filemtime($file) < $oldest) {
                @unlink($file);
            }
        }
    }
}
