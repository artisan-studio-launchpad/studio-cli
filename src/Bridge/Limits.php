<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * What a task is allowed to ask of this machine, decided here and never by the
 * task. A task is data from the studio; it picks a model and a time limit
 * inside these bounds and nothing else.
 *
 * Every task also has to be signed by the studio: an Ed25519 signature, made
 * with a private key that only Artisan Studio holds, over the task's project,
 * id, kind, expiry and payload. This terminal holds the matching public key
 * and nothing that could sign. A task without a valid signature is refused.
 *
 * TODO before real use: give each task kind its own narrow tool list here.
 */
final class Limits
{
    public const string TEXT = 'text';

    private const string SIGNING_CONTEXT = 'studio-bridge-task-v1';

    private const int LONGEST_LIFE_SECONDS = 3600;

    private const int LARGEST_PROMPT = 400_000;

    private const int LARGEST_INSTRUCTIONS = 100_000;

    private const int LARGEST_SCHEMA = 20_000;

    private const int LEAST_SECONDS = 10;

    private const int MOST_SECONDS = 600;

    private const array EFFORTS = ['low', 'medium', 'high'];

    /**
     * Why the studio's signature on this task is not good enough, or null when
     * it is.
     *
     * @param  array<string, mixed>  $task
     * @param  list<string>  $publicKeys  base64 Ed25519 public keys the studio signs with
     */
    public static function unsigned(array $task, string $project, array $publicKeys, int $now): ?string
    {
        $signature = is_string($task['signature'] ?? null) ? base64_decode($task['signature'], true) : false;
        $expires = $task['expires_at'] ?? null;

        return match (true) {
            ! function_exists('sodium_crypto_sign_verify_detached') => __('studio-cli::bridge.signature.no_sodium'),
            array_filter($publicKeys, is_string(...)) === [] => __('studio-cli::bridge.signature.no_key'),
            $signature === false || $signature === '' || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES => __('studio-cli::bridge.signature.missing'),
            ! is_int($expires) || $expires < $now || $expires > $now + self::LONGEST_LIFE_SECONDS => __('studio-cli::bridge.signature.expired'),
            ($task['project'] ?? null) !== $project => __('studio-cli::bridge.signature.other_project'),
            ! self::isSignedBy($signature, self::signedText($task, $project), $publicKeys) => __('studio-cli::bridge.signature.invalid'),
            default => null,
        };
    }

    /**
     * Exactly what the studio signs: one line for the format, then the
     * project, the task id, its kind, when it expires, and a hash of its
     * payload with every key in sorted order.
     *
     * @param  array<string, mixed>  $task
     */
    public static function signedText(array $task, string $project): string
    {
        return implode("\n", [
            self::SIGNING_CONTEXT,
            $project,
            (string) ($task['id'] ?? ''),
            (string) ($task['kind'] ?? ''),
            (string) ($task['expires_at'] ?? ''),
            hash('sha256', (string) json_encode(self::sorted($task['payload'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }

    /**
     * @param  list<string>  $publicKeys
     */
    private static function isSignedBy(string $signature, string $text, array $publicKeys): bool
    {
        foreach ($publicKeys as $key) {
            $raw = is_string($key) ? base64_decode($key, true) : false;

            if (is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($signature, $text, $raw)) {
                return true;
            }
        }

        return false;
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(self::sorted(...), $value);
        array_is_list($sorted) || ksort($sorted);

        return $sorted;
    }

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
