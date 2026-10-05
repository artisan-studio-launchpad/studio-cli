<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\StateFile;
use Closure;
use Illuminate\Support\Carbon;

final class ToolStatus
{
    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string RAN = 'ran';

    public const string STOPPED = 'stopped';

    public const string OFF = 'off';

    private const int CHECK_EVERY_SECONDS = 10;

    private const int TESTS_MOST_SECONDS = 1900;

    /**
     * @var array{key: string, missing: array<string, array{name: string, packages: list<string>, files: array<string, string>, about: string}>}|null
     */
    private ?array $checked = null;

    public function __construct(private readonly string $root) {}

    /**
     * @param  list<string>  $tools
     */
    public function asked(array $tools): void
    {
        $this->write(['asked' => $tools, 'askedAt' => microtime(true)]);
    }

    /**
     * Whether a scan began, or its conventions were counted, after this
     * moment: whatever was shown of the code before it describes an older scan.
     */
    public function scannedSince(float $moment): bool
    {
        $status = $this->read();
        $times = [
            (float) ($status['askedAt'] ?? 0),
            ...array_map(fn (mixed $part): float => is_array($part) && is_string($part['at'] ?? null) ? (float) strtotime($part['at']) : 0.0, [$status['conventions'] ?? null, $status['blueprint'] ?? null]),
        ];

        return max($times) > $moment;
    }

    /**
     * What counting the conventions found, for the card beside the tools'.
     */
    public function conventionsCounted(int $followed, int $undecided, int $files, ?int $flagged = null): void
    {
        $this->write(['conventions' => ['followed' => $followed, 'undecided' => $undecided, 'files' => $files, 'flagged' => $flagged, 'at' => Carbon::now()->toIso8601String()]]);
    }

    /**
     * @return array{followed: int, undecided: int, files: int, flagged: ?int, at: string}|null
     */
    public function conventions(): ?array
    {
        $conventions = $this->read()['conventions'] ?? null;

        return is_array($conventions) && isset($conventions['followed'], $conventions['undecided']) ? [
            'followed' => (int) $conventions['followed'],
            'undecided' => (int) $conventions['undecided'],
            'files' => (int) ($conventions['files'] ?? 0),
            'flagged' => isset($conventions['flagged']) ? (int) $conventions['flagged'] : null,
            'at' => (string) ($conventions['at'] ?? ''),
        ] : null;
    }

    /**
     * How many models the blueprint mapped on this machine, before the studio names them.
     */
    public function blueprintMapped(int $models, ?int $relationships = null): void
    {
        $this->write(['blueprint' => ['models' => $models, 'relationships' => $relationships, 'at' => Carbon::now()->toIso8601String()]]);
    }

    /**
     * @return array{models: int, relationships: ?int}|null
     */
    public function blueprint(): ?array
    {
        $blueprint = $this->read()['blueprint'] ?? null;

        return is_array($blueprint) && is_int($blueprint['models'] ?? null) ? ['models' => $blueprint['models'], 'relationships' => is_int($blueprint['relationships'] ?? null) ? $blueprint['relationships'] : null] : null;
    }

    /**
     * @param  array{ran: bool, reason?: string, findings?: list<mixed>, summary?: array<string, int>}  $result
     */
    public function coverageFinished(array $result): void
    {
        $this->write(['coverage' => [
            'ran' => $result['ran'],
            'running' => false,
            'files' => count($result['findings'] ?? []),
            'percent' => isset($result['summary']['coverage']) ? (int) $result['summary']['coverage'] : null,
            'reason' => (string) ($result['reason'] ?? ''),
        ]]);
    }

    /**
     * Code coverage is being measured in a run of its own, in the background:
     * the tests' own row is left as it was.
     */
    public function coverageRunning(): void
    {
        $this->write(['coverage' => ['ran' => false, 'running' => true, 'at' => Carbon::now()->toIso8601String(), 'files' => 0, 'percent' => null, 'reason' => '']]);
    }

    /**
     * @return array{ran: bool, running: bool, at: ?string, files: int, percent: ?int, reason: string}|null
     */
    public function coverage(): ?array
    {
        $coverage = $this->read()['coverage'] ?? null;

        return is_array($coverage) && is_bool($coverage['ran'] ?? null) ? [
            'ran' => $coverage['ran'],
            'running' => ($coverage['running'] ?? false) === true,
            'at' => is_string($coverage['at'] ?? null) ? $coverage['at'] : null,
            'files' => (int) ($coverage['files'] ?? 0),
            'percent' => is_int($coverage['percent'] ?? null) ? $coverage['percent'] : null,
            'reason' => (string) ($coverage['reason'] ?? ''),
        ] : null;
    }

    public function testsRunning(): void
    {
        $this->write(['tests' => ['state' => self::RUNNING, 'at' => Carbon::now()->toIso8601String()]]);
    }

    /**
     * Whether the tests are running right now, so what else would lean on the
     * machine waits for them. A run that never finished is not waited on for ever.
     */
    public function testsAreRunning(): bool
    {
        $tests = $this->tests();

        return $tests !== null && $tests['state'] === self::RUNNING && Carbon::parse($tests['at'])->diffInSeconds(Carbon::now(), true) < self::TESTS_MOST_SECONDS;
    }

    public function testsOff(): void
    {
        $this->write(['tests' => ['state' => self::OFF, 'at' => Carbon::now()->toIso8601String()]]);
    }

    /**
     * @param  array{ran: bool, reason?: string, took?: int, summary?: array<string, int>}  $result
     */
    public function testsFinished(array $result): void
    {
        $this->write(['tests' => [
            'state' => $result['ran'] ? self::RAN : self::STOPPED,
            'at' => Carbon::now()->toIso8601String(),
            'tests' => (int) ($result['summary']['tests'] ?? 0),
            'failed' => (int) ($result['summary']['failed'] ?? 0),
            'skipped' => (int) ($result['summary']['skipped'] ?? 0),
            'took' => (int) ($result['took'] ?? 0),
            'reason' => (string) ($result['reason'] ?? ''),
        ]]);
    }

    /**
     * A slow tool that runs after the others, in the background, so it never
     * holds up the findings or the health score.
     */
    public function behind(string $tool, string $state): void
    {
        $this->write(['behind' => [...$this->allBehind(), $tool => $state]]);
    }

    /**
     * @param  array{ran: bool, reason?: string, findings?: list<mixed>}  $result
     */
    public function caughtUp(string $tool, array $result): void
    {
        $run = $this->run() ?? ['tools' => [], 'done' => [], 'outcomes' => []];

        $this->write([
            'behind' => array_diff_key($this->allBehind(), [$tool => true]),
            'run' => [
                ...$run,
                'outcomes' => [...$run['outcomes'], $tool => ['ran' => $result['ran'], 'findings' => count($result['findings'] ?? []), 'reason' => (string) ($result['reason'] ?? ''), 'took' => null]],
            ],
        ]);
    }

    /**
     * The packages a major version behind whose findings the studio set aside.
     *
     * @param  list<array{package: string, installed: string, latest: string, findings: int}>  $packages
     */
    public function excluded(array $packages): void
    {
        $this->write(['excluded' => $packages]);
    }

    /**
     * @return list<array{package: string, installed: string, latest: string, findings: int}>
     */
    public function excludedPackages(): array
    {
        return array_values(array_map(
            fn (array $package): array => ['package' => (string) $package['package'], 'installed' => (string) ($package['installed'] ?? ''), 'latest' => (string) ($package['latest'] ?? ''), 'findings' => (int) ($package['findings'] ?? 0)],
            array_filter($this->read()['excluded'] ?? [], fn (mixed $package): bool => is_array($package) && is_string($package['package'] ?? null)),
        ));
    }

    /**
     * How the studio groups what was found into the problems Insights lists:
     * the same problem in several places counts once, and some are set aside.
     *
     * @param  array{problems?: int, open?: int}  $grouped
     */
    public function grouped(array $grouped): void
    {
        $this->write(['grouped' => ['problems' => (int) ($grouped['problems'] ?? 0), 'open' => (int) ($grouped['open'] ?? 0)]]);
    }

    /**
     * @return array{problems: int, open: int}|null
     */
    public function groupedProblems(): ?array
    {
        $grouped = $this->read()['grouped'] ?? null;

        return is_array($grouped) ? ['problems' => (int) ($grouped['problems'] ?? 0), 'open' => (int) ($grouped['open'] ?? 0)] : null;
    }

    /**
     * Whether a scan has begun on this machine: the app has asked for one, or
     * something of it has been counted or run. A reset leaves none.
     */
    public function hasScan(): bool
    {
        return array_intersect_key($this->read(), array_flip(['asked', 'picked', 'run', 'tests', 'blueprint', 'conventions', 'coverage'])) !== [];
    }

    public function isBehind(string $tool): ?string
    {
        return $this->allBehind()[$tool] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function allBehind(): array
    {
        return $this->read()['behind'] ?? [];
    }

    /**
     * @param  list<string>  $tools
     */
    public function pick(array $tools): void
    {
        $this->write(['picked' => $tools]);
    }

    /**
     * @return list<string>
     */
    public function picked(): array
    {
        $picked = $this->read()['picked'] ?? [];

        return is_array($picked) ? array_values(array_filter($picked, is_string(...))) : [];
    }

    /**
     * @param  list<string>  $tools
     */
    public function running(array $tools): void
    {
        $this->update(fn (array $status): array => [...$status, 'run' => ['tools' => $tools, 'done' => [], 'starts' => [], 'outcomes' => (array) ($status['run']['outcomes'] ?? [])]]);
    }

    /**
     * @param  array{ran: bool, reason?: string, findings?: list<mixed>, took?: int}  $result
     */
    public function ran(string $tool, array $result = ['ran' => true]): void
    {
        $this->update(fn (array $status): array => ! is_array($status['run'] ?? null) ? $status : [...$status, 'run' => [
            ...$status['run'],
            'done' => array_values(array_unique([...(array) ($status['run']['done'] ?? []), $tool])),
            'outcomes' => [...(array) ($status['run']['outcomes'] ?? []), $tool => ['ran' => $result['ran'], 'findings' => count($result['findings'] ?? []), 'reason' => (string) ($result['reason'] ?? ''), 'took' => isset($result['took']) ? (int) $result['took'] : null]],
        ]]);
    }

    /**
     * Notes that a tool of this run has begun, so one still waiting its turn
     * is not shown as running, and each one's time can be counted.
     */
    public function started(string $tool): void
    {
        $this->update(fn (array $status): array => ! is_array($status['run'] ?? null) ? $status : [...$status, 'run' => [...$status['run'], 'starts' => [...(array) ($status['run']['starts'] ?? []), $tool => microtime(true)]]]);
    }

    /**
     * When a tool of this run began, or null while it waits its turn. A run
     * that did not note its starts began every tool together.
     */
    public function startedAt(string $tool): ?float
    {
        $run = $this->read()['run'] ?? null;
        $at = is_array($run) && is_array($run['starts'] ?? null) ? ($run['starts'][$tool] ?? null) : ($run['started'] ?? null);

        return is_float($at) || is_int($at) ? (float) $at : null;
    }

    /**
     * @return array{ran: bool, findings: int, reason: string, took: ?int}|null
     */
    public function outcome(string $tool): ?array
    {
        return $this->run()['outcomes'][$tool] ?? null;
    }

    /**
     * @return array{tools: list<string>, done: list<string>, outcomes: array<array{ran: bool, findings: int, reason: string, took: ?int}>}|null
     */
    public function run(): ?array
    {
        $run = $this->read()['run'] ?? null;

        if (! is_array($run) || ! is_array($run['tools'] ?? null) || ! is_array($run['done'] ?? null)) {
            return null;
        }

        return [
            'tools' => array_values(array_filter($run['tools'], is_string(...))),
            'done' => array_values(array_filter($run['done'], is_string(...))),
            'outcomes' => array_map(
                fn (array $outcome): array => [
                    'ran' => ($outcome['ran'] ?? false) === true,
                    'findings' => (int) ($outcome['findings'] ?? 0),
                    'reason' => (string) ($outcome['reason'] ?? ''),
                    'took' => is_int($outcome['took'] ?? null) ? $outcome['took'] : null,
                ],
                array_filter(is_array($run['outcomes'] ?? null) ? $run['outcomes'] : [], is_array(...)),
            ),
        ];
    }

    public function asks(string $tool): bool
    {
        return in_array($tool, (array) ($this->read()['asked'] ?? []), true);
    }

    /**
     * @return array{state: string, at: string, tests: int, failed: int, skipped: int, took: ?int, reason: string}|null
     */
    public function tests(): ?array
    {
        $tests = $this->read()['tests'] ?? null;

        if (! is_array($tests) || ! is_string($tests['state'] ?? null) || ! is_string($tests['at'] ?? null)) {
            return null;
        }

        return [
            'state' => $tests['state'],
            'at' => $tests['at'],
            'tests' => (int) ($tests['tests'] ?? 0),
            'failed' => (int) ($tests['failed'] ?? 0),
            'skipped' => (int) ($tests['skipped'] ?? 0),
            'took' => is_int($tests['took'] ?? null) ? $tests['took'] : null,
            'reason' => (string) ($tests['reason'] ?? ''),
        ];
    }

    /**
     * @return array<string, array{name: string, packages: list<string>, files: array<string, string>, about: string}>
     */
    public function missing(): array
    {
        $asked = $this->read()['asked'] ?? [];
        $asked = is_array($asked) ? array_values(array_filter($asked, is_string(...))) : [];
        $key = implode(',', $asked).'|'.@filemtime($this->root.'/composer.lock').'|'.intdiv(time(), self::CHECK_EVERY_SECONDS);

        if ($this->checked === null || $this->checked['key'] !== $key) {
            $this->checked = ['key' => $key, 'missing' => $asked === [] ? [] : (new Toolbox($this->root))->missing($asked)];
        }

        return $this->checked['missing'];
    }

    public function forget(): void
    {
        @unlink($this->path());
        $this->checked = null;
    }

    public function path(): string
    {
        return StateFile::for('tools', $this->root);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $json = is_file($this->path()) ? file_get_contents($this->path()) : false;
        $status = $json === false ? null : json_decode($json, true);

        return is_array($status) ? $status : [];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function write(array $changes): void
    {
        $this->update(fn (array $status): array => [...$status, ...$changes]);
    }

    /**
     * Changes the status from what it is at this moment. Several processes
     * write it at once (the checks, the tests, the studio itself), so each
     * change is read, made and put in place under a lock, and put in place whole,
     * so no one's change is lost and no one reads half a file.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function update(Closure $change): void
    {
        $lock = @fopen($this->path().'.lock', 'c');

        if ($lock === false) {
            return;
        }

        flock($lock, LOCK_EX);

        try {
            $temporary = $this->path().'.'.getmypid().'.tmp';

            if (@file_put_contents($temporary, (string) json_encode($change($this->read()))) !== false) {
                @rename($temporary, $this->path());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
