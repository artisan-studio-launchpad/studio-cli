<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\TestsCommand;
use ArtisanStudio\StudioCli\Scan\Tools\DefinedTool;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;

/**
 * The rules the scan checks, one row each, in the order of their group: what
 * every developer gets first, then whatever else they picked. Each row says
 * how many issues it caught, or what it is doing until it knows.
 */
final class ScanRules
{
    public const string INITIAL = 'Initial';

    public const string WAITING = 'waiting';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string INSTALL = 'install';

    public const string OFF = 'off';

    /**
     * The groups in the order they show, and the tools in each, as the app
     * offers them to pick. The first group runs for everyone.
     *
     * @var array<string, list<string>>
     */
    public const array GROUPS = [
        self::INITIAL => ['pint', TestsCommand::TESTS],
        'General' => ['rector'],
        'Security' => ['composer-audit', 'node-audit'],
        'Advanced' => ['phpstan', 'pest-type-coverage', 'pest-arch', TestsCommand::COVERAGE],
    ];

    private const string OTHER = 'Other';

    private bool $scanned = true;

    public function __construct(
        private readonly ToolStatus $status,
        private readonly BackgroundTasks $tasks,
        private readonly Toolbox $toolbox,
    ) {}

    /**
     * Says whether the studio has a scan: when it has none, or has reset it,
     * nothing an earlier scan left on this machine is shown.
     */
    public function forStudio(bool $scanned): self
    {
        $this->scanned = $scanned;

        return $this;
    }

    /**
     * @return list<array{group: string, key: string, name: string, state: string, caught: ?int, note: string, elapsed: ?int}>
     */
    public function rows(): array
    {
        if (! $this->scanned && ! $this->isBusy()) {
            return $this->waiting();
        }

        $keys = array_values(array_unique([...self::GROUPS[self::INITIAL], ...$this->status->picked(), ...array_diff($this->status->run()['tools'] ?? [], self::GROUPS[self::INITIAL])]));

        return array_values(collect($keys)
            ->sortBy(fn (string $key): int => $this->position($key))
            ->map(fn (string $key): array => ['group' => $this->groupOf($key), 'key' => $key, 'name' => $this->nameOf($key), ...$this->stateOf($key)])
            ->all());
    }

    /**
     * The rows for a scan the studio has not started, or has reset: the
     * first group only, all waiting, whatever an earlier scan left on this
     * machine.
     *
     * @return list<array{group: string, key: string, name: string, state: string, caught: ?int, note: string, elapsed: ?int}>
     */
    public function waiting(): array
    {
        return array_map(fn (string $key): array => ['group' => self::INITIAL, 'key' => $key, 'name' => $this->nameOf($key), 'state' => self::WAITING, 'caught' => null, 'note' => 'waiting', 'elapsed' => null], self::GROUPS[self::INITIAL]);
    }

    /**
     * Whether the scan is under way on this machine right now.
     */
    public function isBusy(): bool
    {
        return collect(['blueprint', 'conventions', 'tools', TestsCommand::TESTS, TestsCommand::COVERAGE_TASK])->contains(fn (string $task): bool => $this->tasks->isRunning($task));
    }

    /**
     * Everything the rules caught so far.
     */
    public function caught(): int
    {
        return (int) collect($this->rows())->sum(fn (array $row): int => (int) $row['caught']);
    }

    /**
     * What Insights will list for these issues: the same problem in several
     * places counted once, and how many of those are set aside. Null until
     * the studio has said.
     *
     * @return array{problems: int, open: int}|null
     */
    public function grouped(): ?array
    {
        return $this->caught() > 0 ? $this->status->groupedProblems() : null;
    }

    public function isChecking(): bool
    {
        return collect($this->rows())->contains(fn (array $row): bool => in_array($row['state'], [self::WAITING, self::RUNNING], true));
    }

    public function groupOf(string $key): string
    {
        return collect($this->groups())->search(fn (array $tools): bool => in_array($key, $tools, true)) ?: self::OTHER;
    }

    /**
     * The groups with the tools Artisan Studio describes after the CLI's own.
     *
     * @return array<string, list<string>>
     */
    private function groups(): array
    {
        return collect($this->toolbox->defined())
            ->filter(fn (DefinedTool $tool): bool => array_key_exists((string) $tool->group(), self::GROUPS))
            ->reduce(fn (array $groups, DefinedTool $tool): array => [...$groups, (string) $tool->group() => [...$groups[(string) $tool->group()], $tool->key()]], self::GROUPS);
    }

    private function position(string $key): int
    {
        $group = array_search($this->groupOf($key), [...array_keys(self::GROUPS), self::OTHER], true);

        return (int) $group * 100 + (int) (array_search($key, $this->groups()[$this->groupOf($key)] ?? [], true) ?: 0);
    }

    private function nameOf(string $key): string
    {
        return match ($key) {
            TestsCommand::TESTS => 'Your tests',
            TestsCommand::COVERAGE => 'Pest code coverage',
            default => $this->status->missing()[$key]['name'] ?? $this->toolbox->name($key),
        };
    }

    /**
     * @return array{state: string, caught: ?int, note: string, elapsed: ?int}
     */
    private function stateOf(string $key): array
    {
        return match ($key) {
            TestsCommand::TESTS => $this->testsState(),
            TestsCommand::COVERAGE => $this->coverageState(),
            default => $this->toolState($key),
        };
    }

    /**
     * @return array{state: string, caught: ?int, note: string, elapsed: ?int}
     */
    private function toolState(string $key): array
    {
        $run = $this->status->run();
        $outcome = $this->status->outcome($key);
        $started = $this->status->startedAt($key);
        $inRun = $this->tasks->isRunning('tools') && $started !== null && ! in_array($key, $run['done'] ?? [], true);
        $behind = $this->status->isBehind($key);
        $took = $outcome['took'] ?? null;

        return match (true) {
            $inRun || $behind === ToolStatus::RUNNING => ['state' => self::RUNNING, 'caught' => null, 'note' => 'checking', 'elapsed' => (int) ((microtime(true) - ($started ?? microtime(true))) * 1000)],
            isset($this->status->missing()[$key]) => ['state' => self::INSTALL, 'caught' => null, 'note' => 'not installed', 'elapsed' => null],
            $outcome !== null && $outcome['ran'] => ['state' => self::DONE, 'caught' => $outcome['findings'], 'note' => $outcome['findings'] === 0 ? 'all clear' : 'detected', 'elapsed' => $took],
            $outcome !== null => ['state' => self::FAILED, 'caught' => null, 'note' => 'did not run', 'elapsed' => $took],
            default => ['state' => self::WAITING, 'caught' => null, 'note' => 'waiting', 'elapsed' => null],
        };
    }

    /**
     * The tests catch their failing tests.
     *
     * @return array{state: string, caught: ?int, note: string, elapsed: ?int}
     */
    private function testsState(): array
    {
        $tests = $this->status->tests();
        $failed = (int) ($tests['failed'] ?? 0);

        $since = isset($tests['at']) ? strtotime((string) $tests['at']) : false;

        return match ($tests['state'] ?? null) {
            ToolStatus::RUNNING => ['state' => self::RUNNING, 'caught' => null, 'note' => 'running', 'elapsed' => $since === false ? null : max(0, (int) ((microtime(true) - $since) * 1000))],
            ToolStatus::RAN => ['state' => self::DONE, 'caught' => $failed, 'note' => $failed === 0 ? number_format($tests['tests']).' passed' : number_format($failed).' failing of '.number_format($tests['tests']), 'elapsed' => $tests['took']],
            ToolStatus::OFF => ['state' => self::OFF, 'caught' => null, 'note' => 'off', 'elapsed' => null],
            ToolStatus::STOPPED => ['state' => self::FAILED, 'caught' => null, 'note' => 'did not run', 'elapsed' => null],
            default => ['state' => self::WAITING, 'caught' => null, 'note' => 'waiting', 'elapsed' => null],
        };
    }

    /**
     * Coverage rides on the tests run: it catches the files they barely reach,
     * and takes the time the tests do.
     *
     * @return array{state: string, caught: ?int, note: string, elapsed: ?int}
     */
    private function coverageState(): array
    {
        $coverage = $this->status->coverage();
        $tests = $this->testsState();

        $since = isset($coverage['at']) ? strtotime($coverage['at']) : false;

        return match (true) {
            $coverage !== null && $coverage['running'] => ['state' => self::RUNNING, 'caught' => null, 'note' => 'checking', 'elapsed' => $since === false ? null : max(0, (int) ((microtime(true) - $since) * 1000))],
            $coverage !== null && $coverage['ran'] => ['state' => self::DONE, 'caught' => $coverage['files'], 'note' => $coverage['percent'] === null ? 'files barely tested' : $coverage['percent'].'% covered', 'elapsed' => $tests['elapsed']],
            $coverage !== null => ['state' => self::FAILED, 'caught' => null, 'note' => 'did not run', 'elapsed' => null],
            $tests['state'] === self::RUNNING => ['state' => self::RUNNING, 'caught' => null, 'note' => 'with your tests', 'elapsed' => $tests['elapsed']],
            default => ['state' => self::WAITING, 'caught' => null, 'note' => $tests['state'] === self::DONE ? 'waiting its turn' : 'with your tests', 'elapsed' => null],
        };
    }
}
