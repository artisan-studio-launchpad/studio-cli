<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * What the developer said about letting Artisan Studio run its AI tasks through
 * their own Claude Code login: yes, no, or nothing yet.
 *
 * It is kept on this machine, outside the project and outside git, in a file
 * only the user can read, one entry per checkout. The bridge starts only on a
 * yes. There is no default and nothing that answers for the developer.
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

        return is_array($entry) && is_bool($entry['allowed'] ?? null) ? $entry['allowed'] : null;
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
            file_put_contents($this->file, (string) json_encode([...$this->all(), $this->key() => ['allowed' => $allowed, 'at' => now()->toIso8601String(), 'checkout' => $this->checkout]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
}
