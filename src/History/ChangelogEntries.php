<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\History;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * What a project says it has built, read from its own CHANGELOG with no AI:
 * the lines under each release's Added, Features or New heading, or every
 * line of a release that has no headings. Each line comes out shaped like a
 * feature commit, so a project with a changelog and a messy history is read
 * the same way as one with tidy commits.
 */
final class ChangelogEntries
{
    private const array FILES = ['CHANGELOG.md', 'CHANGELOG', 'changelog.md', 'Changelog.md', 'CHANGES.md', 'HISTORY.md'];

    private const string RELEASE = '/^##\s+\[?v?(?<version>[^\]\s]+)\]?\s*(?:[-–(]\s*(?<date>\d{4}-\d{2}-\d{2}))?/';

    private const string FEATURE_HEADING = '/^###\s+(added|features?|new)\b/i';

    private const string OTHER_HEADING = '/^###\s+/';

    private const string ITEM = '/^\s*[-*+]\s+(?<text>.+)$/';

    public function __construct(private readonly string $root) {}

    /**
     * @return list<array{hash: string, committed_at: string, scope: ?string, subject: string, body: string}>
     */
    public function read(): array
    {
        $text = $this->text();

        if ($text === null) {
            return [];
        }

        $entries = [];
        $date = null;
        $inRelease = false;
        $heading = null;
        $sectioned = $this->hasFeatureHeadings($text);

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match(self::RELEASE, $line, $release) === 1) {
                $inRelease = true;
                $date = $release['date'] ?? null;
                $heading = null;

                continue;
            }

            if (preg_match(self::FEATURE_HEADING, $line) === 1) {
                $heading = 'feature';

                continue;
            }

            if (preg_match(self::OTHER_HEADING, $line) === 1) {
                $heading = 'other';

                continue;
            }

            if (! $inRelease || preg_match(self::ITEM, $line, $item) !== 1 || ($sectioned && $heading !== 'feature')) {
                continue;
            }

            $subject = trim((string) preg_replace('/\s*\(?\[?#\d+\]?\)?$/', '', trim($item['text'])));

            $subject === '' || $entries[] = [
                'hash' => substr(hash('sha256', $subject), 0, 40),
                'committed_at' => $this->when($date),
                'scope' => null,
                'subject' => mb_substr($subject, 0, FeatureCommits::LONGEST_SUBJECT),
                'body' => '',
            ];

            if (count($entries) >= FeatureCommits::MOST_SENT) {
                break;
            }
        }

        return $entries;
    }

    private function text(): ?string
    {
        foreach (self::FILES as $file) {
            $path = $this->root.'/'.$file;

            if (is_file($path) && filesize($path) <= 2_000_000) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }

    private function hasFeatureHeadings(string $text): bool
    {
        return preg_match('/^###\s+(added|features?|new)\b/mi', $text) === 1;
    }

    private function when(?string $date): string
    {
        try {
            return ($date === null ? Carbon::now() : Carbon::parse($date))->toIso8601String();
        } catch (Throwable) {
            return Carbon::now()->toIso8601String();
        }
    }
}
