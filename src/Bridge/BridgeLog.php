<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * A plain record, on this machine, of every task the bridge ran for the studio:
 * what it was asked, which program ran it, how long it took and how it ended.
 * The developer can read it any time to see exactly what their login was used
 * for.
 */
class BridgeLog
{
    private const int PROMPT_KEPT = 4000;

    public function __construct(private readonly string $directory) {}

    public function path(): string
    {
        return $this->directory.'/'.date('Y-m-d').'.jsonl';
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function note(array $task, string $runner, Outcome $outcome, int $milliseconds): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }

        $payload = (array) ($task['payload'] ?? []);

        file_put_contents($this->path(), json_encode([
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
            'instructions' => mb_substr((string) ($payload['instructions'] ?? ''), 0, self::PROMPT_KEPT),
            'prompt' => mb_substr((string) ($payload['prompt'] ?? ''), 0, self::PROMPT_KEPT),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
