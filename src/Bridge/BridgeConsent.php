<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * What the developer said about letting Artisan Studio run its AI tasks through
 * their own Claude Code login: yes, no, or nothing yet.
 *
 * It is kept on this machine, outside the project and outside git, in a file
 * only the user can read, one entry per checkout and per link to the studio. The
 * bridge starts only on a yes. There is no default and nothing that answers for
 * the developer.
 */
final class BridgeConsent
{
    public function __construct(
        private readonly string $file,
        private readonly string $checkout,
    ) {}

    /**
     * The consent for this checkout, in the file the config names or else in
     * the user's own config folder.
     */
    public static function make(): self
    {
        $configured = config('studio-cli.bridge.consent_file');

        return new self(is_string($configured) && $configured !== '' ? $configured : self::defaultFile(), base_path());
    }

    public static function defaultFile(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $config = $_SERVER['XDG_CONFIG_HOME'] ?? getenv('XDG_CONFIG_HOME');
        $base = is_string($config) && $config !== '' ? $config : (is_string($home) && $home !== '' ? $home.'/.config' : sys_get_temp_dir());

        return $base.'/artisan-studio/bridge.json';
    }

    /**
     * True for yes, false for no, null when the developer has not said.
     */
    public function choice(): ?bool
    {
        $entry = $this->all()[$this->key()] ?? null;

        if (! is_array($entry) || ! is_bool($entry['allowed'] ?? null) || ($entry['link'] ?? null) !== $this->link()) {
            return null;
        }

        return $entry['allowed'];
    }

    public function isAllowed(): bool
    {
        return $this->choice() === true;
    }

    public function allow(): void
    {
        $this->remember(true);
    }

    public function refuse(): void
    {
        $this->remember(false);
    }

    /**
     * Forget the answer for this checkout, whatever it was, so the developer is asked again. Done
     * when the studio no longer knows this machine's token (Reset Scan takes it away), so a reset
     * leaves nothing of the bridge behind here.
     */
    public function forget(): void
    {
        $all = $this->all();

        if (! array_key_exists($this->key(), $all)) {
            return;
        }

        unset($all[$this->key()]);

        if ($all === []) {
            @unlink($this->file);

            return;
        }

        @file_put_contents($this->file, (string) json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Forget the answer only if it was given under this token, which the studio has just refused.
     * An answer given under another token is a different link's, and stays. Whether anything was
     * forgotten.
     */
    public function forgetUnder(string $token): bool
    {
        $entry = $this->all()[$this->key()] ?? null;

        if (! is_array($entry) || (isset($entry['link']) && $entry['link'] !== hash('xxh128', $token))) {
            return false;
        }

        $this->forget();

        return true;
    }

    public function path(): string
    {
        return $this->file;
    }

    private function remember(bool $allowed): void
    {
        $directory = dirname($this->file);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $before = umask(0177);

        try {
            file_put_contents($this->file, (string) json_encode([...$this->all(), $this->key() => ['allowed' => $allowed, 'link' => $this->link(), 'at' => now()->toIso8601String(), 'checkout' => $this->checkout]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        } finally {
            umask($before);
        }

        @chmod($this->file, 0600);
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $json = is_file($this->file) ? json_decode((string) @file_get_contents($this->file), true) : null;

        return is_array($json) ? $json : [];
    }

    private function key(): string
    {
        return hash('xxh128', $this->checkout);
    }

    /**
     * ★ AN ANSWER BELONGS TO THE LINK IT WAS GIVEN ON. It is kept per checkout, so on its own it
     * outlived everything the studio does to a project: Reset Scan, a new token, a fresh start. The
     * next link then found a yes from long ago and turned the bridge on without asking. The answer
     * is stored with a fingerprint of the token it was given under, and counts only while the CLI is
     * linked with that same token. A new token is a new question; the token itself is never stored.
     */
    private function link(): string
    {
        return hash('xxh128', (string) config('studio-cli.token'));
    }
}
