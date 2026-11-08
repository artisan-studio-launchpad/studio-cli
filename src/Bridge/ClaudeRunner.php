<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * Runs a task through Claude Code, signed in on this machine.
 *
 * The studio never sees that login. This runs `claude -p` as the developer
 * would, with nothing it could use on the project: no tools, no settings, no
 * saved session, and a folder of its own to stand in. Replacing the system
 * prompt matters twice over: Claude Code's own writes about fifty thousand
 * tokens to the cache on every call, which would eat a subscription for no
 * reason.
 */
final class ClaudeRunner implements Runner
{
    private const string BINARY = 'claude';

    private const string FALLBACK_INSTRUCTIONS = 'You are a careful assistant. Answer only what is asked.';

    private ?string $version = null;

    public function __construct(private readonly Shell $shell) {}

    public function name(): string
    {
        return 'claude';
    }

    public function notReady(): ?string
    {
        if ($this->shell->find(self::BINARY) === null) {
            return 'Claude Code is not installed on this machine. Install it, run claude once to sign in, then start the bridge again.';
        }

        $status = $this->shell->run([self::BINARY, 'auth', 'status'], '', $this->folder(), 20);
        $signedIn = json_decode($status['out'], true)['loggedIn'] ?? false;

        return $status['exit'] === 0 && $signedIn === true
            ? null
            : 'Claude Code is not signed in. Run claude, sign in, then start the bridge again.';
    }

    public function run(array $payload): Outcome
    {
        $checked = Limits::check(['kind' => Limits::TEXT, 'payload' => $payload]);

        if (is_string($checked)) {
            return Outcome::failed($checked);
        }

        $answered = $this->shell->run($this->command($checked), $checked['prompt'], $this->folder(), $checked['timeout']);

        if ($answered['timedOut']) {
            return Outcome::failed('Claude Code took longer than '.$checked['timeout'].' seconds, so the bridge stopped it.');
        }

        return $this->outcomeOf($answered['out'], $answered['err']);
    }

    /**
     * @param  array{prompt: string, instructions: string, model: string, timeout: int, schema: ?string, effort: ?string}  $task
     * @return list<string>
     */
    private function command(array $task): array
    {
        return [
            self::BINARY, '-p',
            '--output-format', 'json',
            '--model', $task['model'],
            '--tools', '',
            '--system-prompt', $task['instructions'] !== '' ? $task['instructions'] : self::FALLBACK_INSTRUCTIONS,
            '--setting-sources', '',
            '--no-session-persistence',
            '--max-turns', $task['schema'] === null ? '1' : '3',
            ...($task['schema'] === null ? [] : ['--json-schema', $task['schema']]),
            ...($task['effort'] === null ? [] : ['--effort', $task['effort']]),
        ];
    }

    private function outcomeOf(string $out, string $err): Outcome
    {
        $answer = json_decode($out, true);

        if (! is_array($answer)) {
            return Outcome::failed('Claude Code gave an answer the bridge could not read.'.($err !== '' ? ' '.mb_substr(trim($err), 0, 300) : ''));
        }

        $usage = $this->usageOf($answer);

        if (($answer['is_error'] ?? false) === true) {
            return Outcome::failed($this->friendly((string) ($answer['result'] ?? 'Claude Code stopped with an error.')), $usage);
        }

        return new Outcome(
            ok: true,
            text: (string) ($answer['result'] ?? ''),
            structured: is_array($answer['structured_output'] ?? null) ? $answer['structured_output'] : null,
            usage: $usage,
        );
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return array<string, int|float|string>
     */
    private function usageOf(array $answer): array
    {
        $used = (array) ($answer['usage'] ?? []);

        return [
            'input_tokens' => (int) ($used['input_tokens'] ?? 0),
            'output_tokens' => (int) ($used['output_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($used['cache_read_input_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($used['cache_creation_input_tokens'] ?? 0),
            'cost_usd' => (float) ($answer['total_cost_usd'] ?? 0),
            'duration_ms' => (int) ($answer['duration_ms'] ?? 0),
            'api_ms' => (int) ($answer['duration_api_ms'] ?? 0),
            'turns' => (int) ($answer['num_turns'] ?? 0),
            'runner' => $this->name(),
            'version' => $this->version(),
        ];
    }

    private function friendly(string $message): string
    {
        return match (true) {
            str_contains($message, 'Not logged in') => 'Claude Code is not signed in. Run claude, sign in, then try again.',
            str_contains(strtolower($message), 'limit') => 'Your Claude plan is at its limit: '.mb_substr($message, 0, 200),
            default => mb_substr($message, 0, 400),
        };
    }

    private function version(): string
    {
        return $this->version ??= trim($this->shell->run([self::BINARY, '--version'], '', $this->folder(), 10)['out']);
    }

    /**
     * An empty folder of its own, so the task stands in nothing of the project.
     */
    private function folder(): string
    {
        $folder = sys_get_temp_dir().'/studio-bridge';

        if (! is_dir($folder)) {
            mkdir($folder, 0700, true);
        }

        return $folder;
    }
}
