<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\StateFile;
use Closure;
use Illuminate\Support\Collection;

/**
 * Where a fix run is up to, written by the run and read by the Insights tab,
 * so each rule's card can spin, count its time, and say what landed.
 */
final class FixProgress
{
    public const string WAITING = 'waiting';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string SKIPPED = 'skipped';

    public const string FIXING = 'fixing';

    public const string CHECKING = 'checking';

    public const string FINISHED = 'finished';

    public const string REFUSED = 'refused';

    private const int SHOWN_FOR_SECONDS = 3600;

    private const int LIVE_FILES = 2000;

    /**
     * @var (Closure(array<string, mixed>): void)|null
     */
    private ?Closure $onChange = null;

    public function __construct(private readonly string $root) {}

    /**
     * @param  Closure(array<string, mixed>): void  $onChange  told every state written, so the studio can follow the run live
     */
    public function listen(Closure $onChange): void
    {
        $this->onChange = $onChange;
    }

    /**
     * What the studio is told while a run works: its phase and each rule's state and counts.
     *
     * @param  array<string, mixed>  $progress
     * @return array{phase: string, rulesets: array<string, array{state: string, found: int, fixed: int, left: ?int}>, files: list<array{path: string, fixed: int}>}
     */
    public static function live(array $progress): array
    {
        return [
            'phase' => (string) ($progress['phase'] ?? self::FIXING),
            'rulesets' => array_map(fn (mixed $ruleset): array => [
                'state' => (string) ($ruleset['state'] ?? self::WAITING),
                'found' => (int) ($ruleset['found'] ?? 0),
                'fixed' => (int) ($ruleset['fixed'] ?? 0),
                'left' => isset($ruleset['left']) ? (int) $ruleset['left'] : null,
            ], (array) ($progress['rulesets'] ?? [])),
            'files' => collect((array) ($progress['files'] ?? []))
                ->flatMap(fn (mixed $paths): array => collect((array) $paths)->map(fn (mixed $fixed, int|string $path): array => ['path' => (string) $path, 'fixed' => (int) $fixed])->values()->all())
                ->groupBy('path')
                ->map(fn (Collection $fixes, string $path): array => ['path' => $path, 'fixed' => (int) $fixes->sum('fixed')])
                ->take(self::LIVE_FILES)
                ->values()
                ->all(),
        ];
    }

    /**
     * The rules in the order they run, which is the order their cards show.
     *
     * @param  list<string>  $keys
     * @param  array<string, int>  $found
     */
    public function begin(string $branch, array $keys, array $found, ?string $base = null): void
    {
        $progress = [
            'phase' => self::FIXING,
            'branch' => $branch,
            'base' => $base,
            'at' => microtime(true),
            'pid' => getmypid(),
            'rulesets' => array_combine($keys, array_map(fn (string $key): array => ['state' => self::WAITING, 'found' => $found[$key] ?? 0, 'fixed' => 0, 'files' => 0], $keys)),
        ];

        $this->write($progress);
        $this->told($progress);
    }

    /**
     * The rules it would have fixed are kept, so the right tab says why.
     *
     * @param  list<string>  $keys
     */
    public function refused(string $reason, array $keys = []): void
    {
        $this->write(['phase' => self::REFUSED, 'branch' => '', 'at' => microtime(true), 'reason' => $reason, 'rulesets' => array_fill_keys($keys, ['state' => self::WAITING, 'found' => 0, 'fixed' => 0, 'files' => 0])]);
    }

    /**
     * Whether the run is still going: a run whose process has gone, after a
     * crash or a restart, stopped where it was and will not finish.
     */
    public function isAlive(): bool
    {
        $progress = $this->read();

        return $progress !== null && in_array($progress['phase'], [self::FIXING, self::CHECKING], true) && BackgroundTasks::isAlive($progress['pid']);
    }

    public function hasStopped(): bool
    {
        $progress = $this->read();

        return $progress !== null && in_array($progress['phase'], [self::FIXING, self::CHECKING], true) && ! BackgroundTasks::isAlive($progress['pid']);
    }

    /**
     * The rule's own check runs again, so its card can say what is left.
     */
    public function rechecking(string $key): void
    {
        $this->changeRuleset($key, fn (array $ruleset): array => [...$ruleset, 'rechecking' => true]);
    }

    public function left(string $key, ?int $left): void
    {
        $this->changeRuleset($key, fn (array $ruleset): array => [
            ...$ruleset,
            'rechecking' => false,
            'left' => $left,
            'took' => isset($ruleset['started']) ? (int) ((microtime(true) - (float) $ruleset['started']) * 1000) : ($ruleset['took'] ?? null),
        ]);
    }

    /**
     * How many fixes each rule has made so far, so the table counts up while
     * the fixers work rather than jumping at the end.
     *
     * @param  array<string, int>  $counts
     * @param  array<string, array<string, int>>  $files
     */
    public function fixing(array $counts, array $files = []): void
    {
        $this->change(fn (array $progress): array => [
            ...$progress,
            'rulesets' => collect((array) $progress['rulesets'])
                ->map(fn (array $ruleset, string $key): array => isset($counts[$key]) ? [...$ruleset, 'fixed' => $counts[$key]] : $ruleset)
                ->all(),
            'files' => [...(array) ($progress['files'] ?? []), ...$files],
        ]);
    }

    /**
     * @param  array<string, int>  $files
     */
    public function fixedIn(string $key, array $files): void
    {
        $this->change(fn (array $progress): array => [...$progress, 'files' => [...(array) ($progress['files'] ?? []), $key => $files]]);
    }

    /**
     * What the rule's fix did, as the studio's fix report has it, so whichever
     * process finishes a rule can send the report of everything so far.
     *
     * @param  array<string, mixed>  $report
     */
    public function report(string $key, array $report): void
    {
        $this->changeRuleset($key, fn (array $ruleset): array => [...$ruleset, 'report' => $report]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function reports(): array
    {
        return collect((array) ($this->raw()['rulesets'] ?? []))
            ->filter(fn (mixed $ruleset): bool => is_array($ruleset) && is_array($ruleset['report'] ?? null))
            ->map(fn (array $ruleset): array => $ruleset['report'])
            ->all();
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function changeRuleset(string $key, callable $change): void
    {
        $this->change(fn (array $progress): array => is_array($progress['rulesets'][$key] ?? null)
            ? [...$progress, 'rulesets' => [...$progress['rulesets'], $key => $change($progress['rulesets'][$key])]]
            : $progress);
    }

    /**
     * @param  list<string>  $keys
     */
    public function running(array $keys): void
    {
        $this->change(fn (array $progress): array => [
            ...$progress,
            'rulesets' => [
                ...$progress['rulesets'],
                ...array_combine($keys, array_map(fn (string $key): array => [...$progress['rulesets'][$key] ?? [], 'state' => self::RUNNING, 'started' => microtime(true)], $keys)),
            ],
        ]);
    }

    public function done(string $key, bool $ran, int $fixed, int $files, ?int $found = null): void
    {
        $this->change(function (array $progress) use ($key, $ran, $fixed, $files, $found): array {
            $ruleset = $progress['rulesets'][$key] ?? [];

            return [...$progress, 'rulesets' => [...$progress['rulesets'], $key => [
                ...$ruleset,
                'state' => $ran ? self::DONE : self::SKIPPED,
                'took' => (int) ((microtime(true) - (float) ($ruleset['started'] ?? microtime(true))) * 1000),
                'fixed' => $fixed,
                'files' => $files,
                'found' => $found ?? (int) ($ruleset['found'] ?? 0),
            ]]];
        });
    }

    public function checking(): void
    {
        $this->change(fn (array $progress): array => [...$progress, 'phase' => self::CHECKING, 'checking' => microtime(true)]);
    }

    /**
     * @param  array<string, int|null>  $left
     */
    public function finished(array $left): void
    {
        $this->change(fn (array $progress): array => [
            ...$progress,
            'phase' => self::FINISHED,
            'finished' => microtime(true),
            'rulesets' => collect((array) $progress['rulesets'])->map(fn (array $ruleset, string $key): array => [...$ruleset, 'rechecking' => false, 'left' => $left[$key] ?? $ruleset['left'] ?? null])->all(),
        ]);
    }

    /**
     * @return array{phase: string, branch: string, base: ?string, at: float, pid: ?int, reason: ?string, checking: ?float, finished: ?float, rulesets: array<string, array{state: string, found: int, fixed: int, files: int, started: ?float, took: ?int, left: ?int, rechecking: bool}>}|null
     */
    public function read(): ?array
    {
        $json = $this->raw();

        if ($json === null || ! isset($json['phase'], $json['rulesets']) || ! is_array($json['rulesets'])) {
            return null;
        }

        $progress = [
            'phase' => (string) $json['phase'],
            'branch' => (string) ($json['branch'] ?? ''),
            'base' => isset($json['base']) ? (string) $json['base'] : null,
            'at' => (float) ($json['at'] ?? 0),
            'pid' => isset($json['pid']) ? (int) $json['pid'] : null,
            'reason' => isset($json['reason']) ? (string) $json['reason'] : null,
            'checking' => isset($json['checking']) ? (float) $json['checking'] : null,
            'finished' => isset($json['finished']) ? (float) $json['finished'] : null,
            'rulesets' => collect($json['rulesets'])
                ->filter(fn (mixed $ruleset): bool => is_array($ruleset))
                ->mapWithKeys(fn (array $ruleset, int|string $key): array => [(string) $key => $this->ruleset($ruleset)])
                ->all(),
        ];

        return in_array($progress['phase'], [self::FINISHED, self::REFUSED], true) && microtime(true) - ($progress['finished'] ?? $progress['at']) > self::SHOWN_FOR_SECONDS ? null : $progress;
    }

    /**
     * @param  array<mixed>  $ruleset
     * @return array{state: string, found: int, fixed: int, files: int, started: ?float, took: ?int, left: ?int, rechecking: bool}
     */
    private function ruleset(array $ruleset): array
    {
        return [
            'state' => (string) ($ruleset['state'] ?? self::WAITING),
            'found' => (int) ($ruleset['found'] ?? 0),
            'fixed' => (int) ($ruleset['fixed'] ?? 0),
            'files' => (int) ($ruleset['files'] ?? 0),
            'started' => isset($ruleset['started']) ? (float) $ruleset['started'] : null,
            'took' => isset($ruleset['took']) ? (int) $ruleset['took'] : null,
            'left' => isset($ruleset['left']) ? (int) $ruleset['left'] : null,
            'rechecking' => (bool) ($ruleset['rechecking'] ?? false),
        ];
    }

    /**
     * How far the run is: each rule is a step, and the last check is one more.
     */
    public function fraction(): ?float
    {
        $progress = $this->read();

        if ($progress === null || $progress['phase'] === self::FINISHED) {
            return null;
        }

        $finished = count(array_filter($progress['rulesets'], fn (array $ruleset): bool => in_array($ruleset['state'], [self::DONE, self::SKIPPED], true)));

        return $finished / (count($progress['rulesets']) + 1);
    }

    public function forget(): void
    {
        @unlink($this->path());
        @unlink($this->scannedPath());
    }

    /**
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     */
    public function scanned(array $findings): void
    {
        @file_put_contents($this->scannedPath(), (string) json_encode($findings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>
     */
    public function scannedFor(string $key): array
    {
        $json = is_file($this->scannedPath()) ? json_decode((string) file_get_contents($this->scannedPath()), true) : null;

        return is_array($json) && is_array($json[$key] ?? null) ? array_values($json[$key]) : [];
    }

    private function scannedPath(): string
    {
        return StateFile::for('fixes', $this->root, '-scanned.json');
    }

    public function path(): string
    {
        return StateFile::for('fixes', $this->root);
    }

    /**
     * Two processes can write at once, the fix run and the security fix beside
     * it, so every change reads and writes under one lock.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function change(callable $change): void
    {
        $written = null;

        $this->lock(function () use ($change, &$written): void {
            $progress = $this->raw();

            if ($progress !== null && is_array($progress['rulesets'] ?? null)) {
                $written = $change($progress);
                $this->write($written);
            }
        });

        if ($written !== null) {
            $this->told($written);
        }
    }

    /**
     * @param  callable(): void  $then
     */
    private function lock(callable $then): void
    {
        $lock = @fopen($this->path().'.lock', 'c');

        if ($lock === false) {
            $then();

            return;
        }

        flock($lock, LOCK_EX);

        try {
            $then();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function raw(): ?array
    {
        $json = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function write(array $progress): void
    {
        @file_put_contents($this->path(), (string) json_encode($progress), LOCK_EX);
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function told(array $progress): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($progress);
        }
    }
}
