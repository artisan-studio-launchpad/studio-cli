<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\StudioChecks;
use Illuminate\Support\Str;

/**
 * A ruleset whose checks run in Artisan Studio on the files it asks for.
 * There is nothing to run on this machine: the findings are what the studio's
 * checks for the ruleset send back.
 */
final class StudioRuleset extends Tool
{
    /**
     * @var list<array{where: string, rule: string}>
     */
    private array $notRead = [];

    public function __construct(
        private readonly string $ruleset,
        private readonly StudioChecks $checks,
    ) {}

    /**
     * One tool for each ruleset the studio's described checks report into.
     *
     * @return list<self>
     */
    public static function all(string $root, StudioChecks $checks): array
    {
        return array_map(fn (string $ruleset): self => new self($ruleset, $checks), StudioChecks::rulesets($root));
    }

    public function key(): string
    {
        return $this->ruleset;
    }

    public function name(): string
    {
        return Str::headline($this->ruleset);
    }

    public function command(string $root): ?array
    {
        return [];
    }

    public function checksOnlyTheFilesAsked(): bool
    {
        return true;
    }

    public function findings(string $output, string $root): ?array
    {
        return $this->findingsIn([]);
    }

    /**
     * What the studio's checks found in these files, or in the whole project
     * when none are named.
     *
     * @param  list<string>  $paths
     * @return list<array{where: string, rule: string, message: string}>|null
     */
    public function findingsIn(array $paths): ?array
    {
        $report = $this->checks->ruleset($this->ruleset, $paths === [] ? null : $paths);
        $this->notRead = $report['unread'] ?? [];

        return $report === null ? null : array_map(fn (array $finding): array => $this->finding(...self::parts($finding)), $report['findings']);
    }

    public function unread(string $output): array
    {
        return $this->notRead;
    }

    /**
     * @param  array{where: string, rule: string, message: string}  $finding
     * @return array{string, int|null, string, string}
     */
    private static function parts(array $finding): array
    {
        $line = preg_match('/^(.*):(\d+)$/', $finding['where'], $at) === 1;

        return [$line ? $at[1] : $finding['where'], $line ? (int) $at[2] : null, $finding['rule'], $finding['message']];
    }
}
