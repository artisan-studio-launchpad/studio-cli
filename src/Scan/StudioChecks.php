<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\StateFile;
use ArtisanStudio\StudioCli\Studio;

/**
 * Runs the checks Artisan Studio describes. The studio says which files a
 * check needs, this machine sends them through the one outbound filter, and
 * the studio answers with where it found something and which rule. The check
 * itself, what it looks for, is the studio's: nothing of it is kept here.
 *
 * Only the files the studio asks for leave, never one holding a secret, and
 * the studio keeps none of them.
 */
final class StudioChecks
{
    private const string STATE = 'scan-checks';

    private const int MOST_ROUNDS = 8;

    private const int MOST_BYTES_EACH_TIME = 16_000_000;

    /**
     * @var array<string, array{findings: list<array{where: string, rule: string, message: string, ruleset: string}>, unread: list<array{where: string, rule: string}>}|null>
     */
    private array $answers = [];

    public function __construct(
        private readonly string $root,
        private readonly ?Studio $studio = null,
    ) {}

    /**
     * Keeps what the studio described, for every command that runs a ruleset.
     *
     * @param  array<mixed>  $checks
     */
    public static function keep(string $root, array $checks): void
    {
        @file_put_contents(StateFile::for(self::STATE, $root), (string) json_encode(array_values(array_filter($checks, self::isValid(...)))));
    }

    /**
     * The checks the studio last described.
     *
     * @return list<array{key: string, name: string, about: string, rulesets: list<string>}>
     */
    public static function described(string $root): array
    {
        $kept = json_decode((string) @file_get_contents(StateFile::for(self::STATE, $root)), true);

        return array_values(array_filter(is_array($kept) ? $kept : [], self::isValid(...)));
    }

    private static function isValid(mixed $check): bool
    {
        return is_array($check)
            && is_string($check['key'] ?? null) && preg_match('/^[a-z0-9-]{1,40}$/', $check['key']) === 1
            && is_string($check['name'] ?? null)
            && is_string($check['about'] ?? null)
            && is_array($check['rulesets'] ?? null) && array_is_list($check['rulesets']) && collect($check['rulesets'])->every(fn (mixed $ruleset): bool => is_string($ruleset) && preg_match('/^[a-z0-9-]{1,40}$/', $ruleset) === 1);
    }

    /**
     * The rulesets the described checks report into.
     *
     * @return list<string>
     */
    public static function rulesets(string $root): array
    {
        return array_values(array_unique(array_merge([], ...array_column(self::described($root), 'rulesets'))));
    }

    /**
     * What every check for a ruleset found there, and the files and rules it
     * could not read. Null when a check could not be run.
     *
     * @param  list<string>|null  $only
     * @return array{findings: list<array{where: string, rule: string, message: string}>, unread: list<array{where: string, rule: string}>}|null
     */
    public function ruleset(string $ruleset, ?array $only = null): ?array
    {
        $checks = array_values(array_filter(self::described($this->root), fn (array $check): bool => in_array($ruleset, $check['rulesets'], true)));
        $answers = array_map(fn (array $check): ?array => $this->once($check['key'], $only), $checks);

        if ($checks === [] || in_array(null, $answers, true)) {
            return null;
        }

        $findings = array_merge([], ...array_map(fn (array $answer): array => $answer['findings'], $answers));
        $unread = array_merge([], ...array_map(fn (array $answer): array => $answer['unread'], $answers));

        return [
            'findings' => array_values(array_map(
                fn (array $finding): array => ['where' => $finding['where'], 'rule' => $finding['rule'], 'message' => $finding['message']],
                array_filter($findings, fn (array $finding): bool => $finding['ruleset'] === $ruleset),
            )),
            'unread' => $unread,
        ];
    }

    /**
     * A check asked once for the same files, however many rulesets it reports
     * into.
     *
     * @param  list<string>|null  $only
     * @return array{findings: list<array{where: string, rule: string, message: string, ruleset: string}>, unread: list<array{where: string, rule: string}>}|null
     */
    private function once(string $check, ?array $only): ?array
    {
        $id = $check.'|'.json_encode($only);

        return array_key_exists($id, $this->answers) ? $this->answers[$id] : $this->answers[$id] = $this->run($check, $only);
    }

    /**
     * What one check found, and the files and rules it could not read. Null
     * when the studio could not be reached or never had what it needed.
     *
     * With files named, the studio is told to check only them.
     *
     * @param  list<string>|null  $only
     * @return array{findings: list<array{where: string, rule: string, message: string, ruleset: string}>, unread: list<array{where: string, rule: string}>}|null
     */
    public function run(string $check, ?array $only = null): ?array
    {
        $studio = $this->studio ?? app(Studio::class);
        $reads = new ProjectReads($this->root, self::MOST_BYTES_EACH_TIME);
        $scoped = $only === null ? [] : ['only' => array_values(array_filter($only, $reads->isProjectPath(...)))];
        $sent = [];

        for ($round = 1; $round <= self::MOST_ROUNDS; $round++) {
            $answer = $studio->runScanCheck($check, [...$scoped, 'files' => $this->files($reads, $sent), 'missing' => $reads->missing(), 'found' => (object) $reads->found()]);

            if (! is_array($answer) || is_array($answer['findings'] ?? null)) {
                return is_array($answer) ? self::report($answer) : null;
            }

            $more = $reads->answer((array) ($answer['needs'] ?? []), $sent);

            if ($more === null) {
                return null;
            }

            $sent = [...$sent, ...$more];
        }

        return null;
    }

    /**
     * @param  list<string>  $paths
     * @return list<array{path: string, content: string}>
     */
    private function files(ProjectReads $reads, array $paths): array
    {
        return array_values(array_filter(array_map(
            fn (string $path): ?array => ($text = $reads->readable($path)) === null ? null : ['path' => $path, 'content' => base64_encode($text)],
            array_values(array_unique($paths)),
        )));
    }

    /**
     * Only the place, the rule and the one line come back from the studio, in
     * the shape every tool's findings have.
     *
     * @param  array<mixed>  $answer
     * @return array{findings: list<array{where: string, rule: string, message: string, ruleset: string}>, unread: list<array{where: string, rule: string}>}
     */
    private static function report(array $answer): array
    {
        return [
            'findings' => array_values(array_map(
                fn (array $finding): array => ['where' => $finding['where'], 'rule' => $finding['rule'], 'message' => $finding['message'], 'ruleset' => $finding['ruleset']],
                array_filter((array) $answer['findings'], fn (mixed $finding): bool => is_array($finding) && is_string($finding['where'] ?? null) && is_string($finding['rule'] ?? null) && is_string($finding['message'] ?? null) && is_string($finding['ruleset'] ?? null)),
            )),
            'unread' => array_values(array_map(
                fn (array $unread): array => ['where' => $unread['where'], 'rule' => $unread['rule']],
                array_filter((array) ($answer['unread'] ?? []), fn (mixed $unread): bool => is_array($unread) && is_string($unread['where'] ?? null) && is_string($unread['rule'] ?? null)),
            )),
        ];
    }
}
