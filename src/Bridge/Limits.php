<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * What a task is allowed to ask of this machine, decided here and never by the
 * task. A task is data from the studio; it picks a model and a time limit
 * inside these bounds and nothing else.
 *
 * TODO before real use: check the studio's signature on the task, and
 * give each task kind its own narrow tool list here.
 */
final class Limits
{
    public const string TEXT = 'text';

    private const int LARGEST_PROMPT = 400_000;

    private const int LARGEST_INSTRUCTIONS = 100_000;

    private const int LARGEST_SCHEMA = 20_000;

    private const int LEAST_SECONDS = 10;

    private const int MOST_SECONDS = 600;

    private const array EFFORTS = ['low', 'medium', 'high'];

    /**
     * @param  array<string, mixed>  $task
     * @return array{prompt: string, instructions: string, model: string, timeout: int, schema: ?string, effort: ?string}|string a string is why the task is refused
     */
    public static function check(array $task): array|string
    {
        if (($task['kind'] ?? null) !== self::TEXT) {
            return 'This terminal only runs plain text tasks, and this one is a '.($task['kind'] ?? 'task of no kind').'.';
        }

        $payload = (array) ($task['payload'] ?? []);
        $prompt = (string) ($payload['prompt'] ?? '');
        $instructions = (string) ($payload['instructions'] ?? '');
        $model = (string) ($payload['model'] ?? '');
        $schema = isset($payload['schema']) ? json_encode($payload['schema']) : null;

        return match (true) {
            trim($prompt) === '' => 'The task came with nothing to ask.',
            strlen($prompt) > self::LARGEST_PROMPT => 'The task is larger than this terminal will run.',
            strlen($instructions) > self::LARGEST_INSTRUCTIONS => 'The task instructions are larger than this terminal will run.',
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,59}$/', $model) !== 1 => 'The task named a model this terminal does not recognise.',
            $schema !== null && (! is_string($schema) || strlen($schema) > self::LARGEST_SCHEMA) => 'The task answer shape is larger than this terminal will use.',
            default => [
                'prompt' => $prompt,
                'instructions' => $instructions,
                'model' => $model,
                'timeout' => max(self::LEAST_SECONDS, min(self::MOST_SECONDS, (int) ($payload['timeout'] ?? 300))),
                'schema' => $schema ?: null,
                'effort' => in_array($payload['effort'] ?? null, self::EFFORTS, true) ? $payload['effort'] : null,
            ],
        };
    }
}
