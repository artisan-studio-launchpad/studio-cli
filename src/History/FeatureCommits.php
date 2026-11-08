<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\History;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * What a project has built, read from its own git history with no AI: the
 * conventional `feat:` and `feat(scope):` commits, newest first, and what its CHANGELOG lists. A history
 * that does not use conventional commits is reported as such and sends none,
 * since guessing at free-form messages gives messy, wrong results.
 */
final class FeatureCommits
{
    public const int MOST_READ = 5000;

    public const int MOST_SENT = 400;

    public const int LONGEST_SUBJECT = 300;

    public const int LONGEST_BODY = 1500;

    private const string CONVENTIONAL = '/^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([^)]+\))?!?:\s/';

    private const string FEATURE = '/^feat(?:\(([^)]+)\))?!?:\s*(.+)$/s';

    private const string TRAILERS = '/^(Co-Authored-By|Signed-off-by|Reviewed-by|Change-Id):.*$/mi';

    private const int AT_LEAST_CONVENTIONAL = 5;

    private const int AT_LEAST_CHANGELOG = 3;

    private const float AT_LEAST_SHARE = 0.3;

    public function __construct(private readonly string $root) {}

    /**
     * The tidy feature commits and what the changelog lists, each once. A
     * history that is neither is reported as not usable.
     *
     * @return array{commit: ?string, total: int, conventional: bool, commits: list<array{hash: string, committed_at: string, scope: ?string, subject: string, body: string}>}
     */
    public function read(): array
    {
        $all = $this->log();
        $conventional = array_filter($all, fn (array $commit): bool => preg_match(self::CONVENTIONAL, $commit['subject']) === 1);
        $uses = count($conventional) >= self::AT_LEAST_CONVENTIONAL && count($all) > 0 && count($conventional) / count($all) >= self::AT_LEAST_SHARE;
        $changelog = (new ChangelogEntries($this->root))->read();
        $told = count($changelog) >= self::AT_LEAST_CHANGELOG;
        $commits = $uses ? $this->features($all) : [];

        return [
            'commit' => $all[0]['hash'] ?? null,
            'total' => count($all),
            'conventional' => $uses || $told,
            'commits' => array_slice($told ? self::joined($commits, $changelog) : $commits, 0, self::MOST_SENT),
        ];
    }

    /**
     * @param  list<array{hash: string, committed_at: string, scope: ?string, subject: string, body: string}>  $commits
     * @param  list<array{hash: string, committed_at: string, scope: ?string, subject: string, body: string}>  $changelog
     * @return list<array{hash: string, committed_at: string, scope: ?string, subject: string, body: string}>
     */
    private static function joined(array $commits, array $changelog): array
    {
        $said = array_map(fn (array $commit): string => mb_strtolower($commit['subject']), $commits);

        return [...$commits, ...array_values(array_filter($changelog, fn (array $entry): bool => ! in_array(mb_strtolower($entry['subject']), $said, true)))];
    }

    /**
     * @param  list<array{hash: string, committed_at: string, subject: string, body: string}>  $all
     * @return list<array{hash: string, committed_at: string, scope: ?string, subject: string, body: string}>
     */
    private function features(array $all): array
    {
        $features = [];

        foreach ($all as $commit) {
            if (preg_match(self::FEATURE, $commit['subject'], $found) !== 1) {
                continue;
            }

            $features[] = [
                'hash' => $commit['hash'],
                'committed_at' => $commit['committed_at'],
                'scope' => $found[1] !== '' ? $found[1] : null,
                'subject' => mb_substr(trim($found[2]), 0, self::LONGEST_SUBJECT),
                'body' => $commit['body'],
            ];

            if (count($features) >= self::MOST_SENT) {
                break;
            }
        }

        return $features;
    }

    /**
     * @return list<array{hash: string, committed_at: string, subject: string, body: string}>
     */
    private function log(): array
    {
        $process = new Process(['git', 'log', '--no-merges', '-n', (string) self::MOST_READ, '--format=%H%x1f%aI%x1f%s%x1f%b%x1e'], $this->root, timeout: 60);

        try {
            $process->run();
        } catch (Throwable) {
            return [];
        }

        if (! $process->isSuccessful()) {
            return [];
        }

        $commits = [];

        foreach (explode("\x1e", $process->getOutput()) as $record) {
            $fields = explode("\x1f", trim($record), 4);

            if (count($fields) < 3 || preg_match('/^[0-9a-f]{7,64}$/', $fields[0]) !== 1) {
                continue;
            }

            $commits[] = [
                'hash' => $fields[0],
                'committed_at' => $fields[1],
                'subject' => trim($fields[2]),
                'body' => mb_substr(trim((string) preg_replace(self::TRAILERS, '', $fields[3] ?? '')), 0, self::LONGEST_BODY),
            ];
        }

        return $commits;
    }
}
