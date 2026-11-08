<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\StateFile;
use Symfony\Component\Process\Process;

/**
 * Everything each tool found in the last scan, kept on this machine so fixing
 * can start from it instead of checking again.
 *
 * Each tool's findings are kept with the commit it read, when the working tree
 * was clean, and with a fingerprint of every file it flagged. A finding still
 * stands where nothing has changed in its file, whatever happened to the
 * commits since: a scan taken before the developer committed, or a history
 * that was rewritten, costs nothing but the files that really changed.
 */
final class LastFindings
{
    private ?string $commit = null;

    private bool $read = false;

    public function __construct(private readonly string $root) {}

    /**
     * @param  list<array{where: string, rule: string, message: string}>  $findings
     */
    public function keep(string $key, array $findings): void
    {
        $kept = $this->all();

        @file_put_contents($this->path(), (string) json_encode([
            ...$kept,
            'tools' => [...($kept['tools'] ?? []), $key => $findings],
            'commits' => [...($kept['commits'] ?? []), $key => $this->cleanCommit()],
            'hashes' => [...($kept['hashes'] ?? []), $key => $this->fingerprints($findings)],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * What the tool found that still stands. Found at this commit, all of it.
     * Found at an earlier commit behind this one, what is in the files nothing
     * has changed since. Found anywhere else, what is in the files that are
     * exactly as the scan read them. Null when none of it can be trusted.
     *
     * @return list<array{where: string, rule: string, message: string}>|null
     */
    public function for(string $key): ?array
    {
        $kept = $this->all();
        $findings = $kept['tools'][$key] ?? null;

        if ($findings === null) {
            return null;
        }

        $at = array_key_exists($key, (array) ($kept['commits'] ?? [])) ? $kept['commits'][$key] : ($kept['commit'] ?? null);
        $commit = $this->cleanCommit();

        if ($commit !== null && $at !== null) {
            $changed = $at === $commit ? [] : $this->changedSince($at);

            if ($changed !== null) {
                return array_values(array_filter($findings, fn (array $finding): bool => ! isset($changed[self::fileOf($finding)])));
            }
        }

        return $this->unchanged($findings, (array) ($kept['hashes'][$key] ?? []));
    }

    /**
     * @param  list<array{where: string, rule: string, message: string}>  $findings
     * @param  array<string, string>  $fingerprints
     * @return list<array{where: string, rule: string, message: string}>|null
     */
    private function unchanged(array $findings, array $fingerprints): ?array
    {
        $standing = array_values(array_filter($findings, fn (array $finding): bool => ($fingerprints[self::fileOf($finding)] ?? null) !== null && $fingerprints[self::fileOf($finding)] === $this->fingerprint(self::fileOf($finding))));

        return $standing === [] ? null : $standing;
    }

    /**
     * @param  list<array{where: string, rule: string, message: string}>  $findings
     * @return array<string, string>
     */
    private function fingerprints(array $findings): array
    {
        return collect($findings)
            ->map(fn (array $finding): string => self::fileOf($finding))
            ->unique()
            ->mapWithKeys(fn (string $path): array => [$path => $this->fingerprint($path)])
            ->filter()
            ->all();
    }

    private function fingerprint(string $path): ?string
    {
        $file = $this->root.'/'.$path;

        return $path !== '' && ! str_contains($path, '..') && is_file($file) ? (string) @hash_file('xxh128', $file) : null;
    }

    /**
     * @param  array{where: string}  $finding
     */
    private static function fileOf(array $finding): string
    {
        return (string) preg_replace('/:\d+$/', '', $finding['where']);
    }

    /**
     * @return array<string, true>|null the files changed since that commit, or null when it is not behind this one
     */
    private function changedSince(string $commit): ?array
    {
        $ancestor = new Process(['git', 'merge-base', '--is-ancestor', $commit, 'HEAD'], $this->root);
        $ancestor->run();

        if (! $ancestor->isSuccessful()) {
            return null;
        }

        $diff = new Process(['git', 'diff', '--name-only', $commit, 'HEAD'], $this->root);
        $diff->run();

        return $diff->isSuccessful() ? array_fill_keys(array_filter(explode("\n", trim($diff->getOutput()))), true) : null;
    }

    public function commit(): ?string
    {
        $kept = $this->all();

        return collect((array) ($kept['commits'] ?? []))->filter()->last() ?? ($kept['commit'] ?? null);
    }

    public function forget(): void
    {
        @unlink($this->path());
    }

    public function path(): string
    {
        return StateFile::for('findings', $this->root);
    }

    /**
     * @return array{commit?: string, tools?: array<string, list<array{where: string, rule: string, message: string}>>, commits?: array<string, string|null>, hashes?: array<string, array<string, string>>}
     */
    private function all(): array
    {
        $json = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;

        return is_array($json) ? $json : [];
    }

    private function cleanCommit(): ?string
    {
        if (! $this->read) {
            $branch = new LocalBranch($this->root);
            $this->commit = $branch->isRepository() && $branch->isClean() ? $branch->head() : null;
            $this->read = true;
        }

        return $this->commit;
    }
}
