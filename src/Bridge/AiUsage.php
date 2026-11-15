<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\Saloon\ProviderUsageConnector;
use ArtisanStudio\StudioCli\Saloon\Requests\ReadAiUsageRequest;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * How much of the AI on this machine the developer has used, as two
 * percentages and when each resets, so the app can show it in its footer.
 *
 * It is asked here, with the login already on this machine, and only the
 * numbers go to the studio. The login is read and never changed: no refresh,
 * no write, no copy. A login that has expired is left alone, because the AI
 * renews it by itself the next time it runs.
 */
final class AiUsage
{
    private const int ASK_EVERY_SECONDS = 120;

    private const int SAY_AGAIN_AFTER_SECONDS = 600;

    private const string KEYCHAIN = 'Claude Code-credentials';

    private int $askedAt = 0;

    private int $toldAt = 0;

    private ?string $told = null;

    private ?string $problem = null;

    public function __construct(
        private readonly Shell $shell,
        private readonly Studio $studio,
    ) {}

    /**
     * Asks every couple of minutes, and tells the studio when the numbers
     * changed or after a while, so the footer never goes stale.
     */
    public function keep(): void
    {
        if (time() - $this->askedAt < self::ASK_EVERY_SECONDS) {
            return;
        }

        $this->askedAt = time();
        $usage = $this->read();
        $this->note($usage);

        if ($usage === null) {
            return;
        }

        $said = (string) json_encode($usage);

        if ($said === $this->told && time() - $this->toldAt < self::SAY_AGAIN_AFTER_SECONDS) {
            return;
        }

        $this->told = $said;
        $this->toldAt = time();
        $this->studio->reportAiUsage($usage);
    }

    /**
     * Leaves a line on this machine about the last reading, with no secret in
     * it, so a footer that shows no percentage can be explained. The folder
     * ignores itself in git, so the line can never be committed by accident.
     *
     * @param  array{session: ?array{percent: int, resets_at: ?string}, week: ?array{percent: int, resets_at: ?string}}|null  $usage
     */
    private function note(?array $usage): void
    {
        $file = storage_path('studio/ai-usage.json');

        if (! is_dir(dirname($file))) {
            return;
        }

        $ignore = dirname($file).'/.gitignore';

        if (! is_file($ignore)) {
            @file_put_contents($ignore, '*'.PHP_EOL);
        }

        @file_put_contents($file, (string) json_encode([
            'at' => now()->toIso8601String(),
            'outcome' => $usage === null ? $this->problem : 'read',
            'session' => $usage['session']['percent'] ?? null,
            'week' => $usage['week']['percent'] ?? null,
        ], JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * @return array{session: ?array{percent: int, resets_at: ?string}, week: ?array{percent: int, resets_at: ?string}}|null
     */
    public function read(): ?array
    {
        $this->problem = null;
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        try {
            $response = (new ProviderUsageConnector($token))->send(new ReadAiUsageRequest);
        } catch (Throwable) {
            $this->problem = 'could not reach the AI to ask';

            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            $this->problem = 'the AI would not say (HTTP '.$response->status().')';

            return null;
        }

        $answer = (array) $response->json();
        $usage = ['session' => $this->window($answer['five_hour'] ?? null), 'week' => $this->window($answer['seven_day'] ?? null)];

        if ($usage['session'] === null && $usage['week'] === null) {
            $this->problem = 'the answer had no usage numbers in it';

            return null;
        }

        return $usage;
    }

    /**
     * Why the last reading gave nothing, in words with no secret in them, or
     * null when it worked.
     */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * @return array{percent: int, resets_at: ?string}|null
     */
    private function window(mixed $window): ?array
    {
        if (! is_array($window) || ! is_numeric($window['utilization'] ?? null)) {
            return null;
        }

        $resets = is_string($window['resets_at'] ?? null) ? $window['resets_at'] : null;

        try {
            $resets = $resets === null ? null : Carbon::parse($resets)->utc()->toIso8601String();
        } catch (Throwable) {
            $resets = null;
        }

        return ['percent' => (int) max(0, min(100, round((float) $window['utilization']))), 'resets_at' => $resets];
    }

    /**
     * The login's access token, read from where the AI keeps it on this
     * machine: its own file on every system, and the keychain on a Mac. Null
     * when there is none, or it has run out.
     */
    private function token(): ?string
    {
        $credentials = $this->credentials();
        $oauth = is_array($credentials['claudeAiOauth'] ?? null) ? $credentials['claudeAiOauth'] : [];
        $token = $oauth['accessToken'] ?? null;
        $expires = $oauth['expiresAt'] ?? null;

        if (! is_string($token) || $token === '') {
            $this->problem ??= 'no login found on this machine';

            return null;
        }

        if (is_numeric($expires) && (int) $expires / 1000 <= time()) {
            $this->problem = 'the login has run out; it renews the next time the AI runs';

            return null;
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        foreach ($this->files() as $file) {
            $json = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

            if (is_array($json)) {
                return $json;
            }
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            return [];
        }

        $keychain = $this->fromKeychain($this->account());

        if ($keychain['exit'] !== 0) {
            $keychain = $this->fromKeychain(null);
        }

        $json = $keychain['exit'] === 0 ? json_decode(trim($keychain['out']), true) : null;

        $this->problem = match (true) {
            is_array($json) => null,
            $keychain['timedOut'] => 'the keychain did not answer; a window may be waiting for you to allow it',
            $keychain['exit'] !== 0 => 'the keychain would not give the login (exit '.$keychain['exit'].')',
            default => 'the keychain login could not be read',
        };

        return is_array($json) ? $json : [];
    }

    /**
     * The keychain holds the login under the user's name; an older item of the
     * same name under another account can be left behind with a login that
     * ran out long ago, and a lookup by name alone finds that one first.
     *
     * @return array{exit: int, out: string, err: string, timedOut: bool}
     */
    private function fromKeychain(?string $account): array
    {
        return $this->shell->run(['security', 'find-generic-password', '-s', self::KEYCHAIN, ...($account === null ? [] : ['-a', $account]), '-w'], '', (string) getcwd(), 5);
    }

    /**
     * Who is signed in to this machine, as the keychain knows them.
     */
    private function account(): ?string
    {
        $name = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        $name ??= getenv('USER') ?: null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $folder = config('studio-cli.bridge.claude_config_dir');
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? getenv('HOME') ?: getenv('USERPROFILE');
        $base = is_string($folder) && $folder !== '' ? $folder : (is_string($home) && $home !== '' ? $home.DIRECTORY_SEPARATOR.'.claude' : null);

        return $base === null ? [] : [$base.DIRECTORY_SEPARATOR.'.credentials.json', $base.DIRECTORY_SEPARATOR.'credentials.json'];
    }
}
