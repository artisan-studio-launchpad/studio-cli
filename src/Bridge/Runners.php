<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * The AI tools the bridge can run through on this machine, and where each
 * stands: not installed, installed but signed out, or ready. Built from the
 * config, so a runner that is switched on later is detected, and named in
 * what the screens say, without any copy being rewritten.
 */
final class Runners
{
    public const string CLAUDE = 'claude';

    public const string CODEX = 'codex';

    private const int KEPT_SECONDS = 30;

    /**
     * @var array<string, array{at: int, status: array{installed: bool, signed_in: bool}}>
     */
    private array $known = [];

    public function __construct(
        private readonly Shell $shell,
        private readonly ClaudeAccount $claude,
    ) {}

    /**
     * The runners the config enables, Claude Code first.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        return [self::CLAUDE, ...(config('studio-cli.bridge.codex') === true ? [self::CODEX] : [])];
    }

    /**
     * Whether a runner is left out of this release, so the screens say its
     * support is coming.
     */
    public function isComing(string $runner): bool
    {
        return $runner === self::CODEX && ! in_array(self::CODEX, $this->enabled(), true);
    }

    /**
     * @return array{installed: bool, signed_in: bool}
     */
    public function status(string $runner): array
    {
        if ($runner === self::CLAUDE) {
            $status = $this->claude->status();

            return ['installed' => $status['installed'], 'signed_in' => $status['signed_in']];
        }

        $kept = $this->known[$runner] ?? null;

        if ($kept !== null && time() - $kept['at'] < self::KEPT_SECONDS) {
            return $kept['status'];
        }

        $this->known[$runner] = ['at' => time(), 'status' => $this->read($runner)];

        return $this->known[$runner]['status'];
    }

    /**
     * Each enabled runner and where it stands.
     *
     * @return array<string, array{installed: bool, signed_in: bool}>
     */
    public function statuses(): array
    {
        return collect($this->enabled())->mapWithKeys(fn (string $runner): array => [$runner => $this->status($runner)])->all();
    }

    /**
     * Whether any enabled runner is installed and signed in.
     */
    public function hasReady(): bool
    {
        return collect($this->statuses())->contains(fn (array $status): bool => $status['installed'] && $status['signed_in']);
    }

    /**
     * Whether any enabled runner is installed, signed in or not.
     */
    public function hasInstalled(): bool
    {
        return collect($this->statuses())->contains(fn (array $status): bool => $status['installed']);
    }

    /**
     * Checked again at once, without waiting out what was remembered.
     */
    public function forget(): void
    {
        $this->known = [];
        $this->claude->forget();
    }

    /**
     * @return array{installed: bool, signed_in: bool}
     */
    private function read(string $runner): array
    {
        if ($this->shell->find($runner) === null) {
            return ['installed' => false, 'signed_in' => false];
        }

        $ran = $this->shell->run([$runner, 'login', 'status'], '', sys_get_temp_dir(), 20);

        return ['installed' => true, 'signed_in' => $ran['exit'] === 0 && ! str_contains(strtolower($ran['out'].$ran['err']), 'not logged in')];
    }
}
