<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\StateFile;
use Symfony\Component\Process\Process;

/**
 * Everything each tool found in the last scan, kept on this machine with the
 * commit it read, so fixing can start from it instead of checking again. Kept
 * only for a clean working tree, since the lines must be the committed ones.
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
        $commit = $this->cleanCommit();

        if ($commit === null) {
            return;
        }

        $kept = $this->all();
        $tools = ($kept['commit'] ?? null) === $commit ? ($kept['tools'] ?? []) : [];

        @file_put_contents($this->path(), (string) json_encode([
            'commit' => $commit,
            'tools' => [...$tools, $key => $findings],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * What the tool found at this commit. Found at an earlier commit, it
     * still stands for every file nothing has changed since, so a commit
     * after the scan costs only the findings in the files it touched.
     *
     * @return list<array{where: string, rule: string, message: string}>|null
     */
    public function for(string $key): ?array
    {
        $kept = $this->all();
        $commit = $this->cleanCommit();
        $findings = $kept['tools'][$key] ?? null;

        if ($commit === null || $findings === null || ! isset($kept['commit'])) {
            return null;
        }

        if ($kept['commit'] === $commit) {
            return $findings;
        }

        $changed = $this->changedSince($kept['commit']);

        return $changed === null ? null : array_values(array_filter($findings, fn (array $finding): bool => ! isset($changed[(string) preg_replace('/:\d+$/', '', $finding['where'])])));
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
        return $this->all()['commit'] ?? null;
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
     * @return array{commit?: string, tools?: array<string, list<array{where: string, rule: string, message: string}>>}
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
