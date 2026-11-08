<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\LocalChanges;
use Closure;

final class Walk
{
    public const int WHERE_LIMIT = 50;

    private const int MOST_WHERE = 1000;

    private const int LARGEST_FILE = 1_000_000;

    private const string QUALIFIED_NAME = '/\\\\?([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)/';

    /** @var list<string> */
    private array $classFiles = [];

    /** @var list<string> */
    private array $testFiles = [];

    /** @var list<string> */
    private array $testableFolders = [];

    /** @var array<string, array<string, array{files: int, hits: int, where: list<string>}>> */
    private array $checks = [];

    /** @var array<string, string> */
    private array $testable = [];

    /** @var array<string, true> */
    private array $referenced = [];

    /** @var array{extends: array<string, array{count: int, where: list<string>}>, implements: array<string, array{count: int, where: list<string>}>, traits: array<string, array{count: int, where: list<string>}>} */
    private array $classes = ['extends' => [], 'implements' => [], 'traits' => []];

    /** @var array<string, int> */
    private array $folders = [];

    public function __construct(
        private readonly string $root,
        private readonly LocalChanges $changes,
    ) {}

    /**
     * @param  array{checks: array<string, array{count?: string, facts?: string, sides?: list<array{key: string, label: string, in: list<string>, match: string, absent?: bool, limit?: int}>}>, testable?: list<string>}  $detectors  testable: the folders whose classes should have a test, as the studio names them
     * @param  (Closure(int, int): void)|null  $progressed
     * @return array{commit: string, files: int, read: int, took: int, checks: array<string, array<string, array{files: int, hits: int, where: list<string>}>>, classes: array<string, array<string, array{count: int, where: list<string>}>>, folders: array<string, int>, tested: array{classes: int, tested: int, untested: list<string>}}
     */
    public function facts(array $detectors, ?Closure $progressed = null): array
    {
        $started = hrtime(true);
        $this->checks = [];
        $this->classes = ['extends' => [], 'implements' => [], 'traits' => []];
        $this->folders = [];
        $this->testable = [];
        $this->referenced = [];
        $this->classFiles = $this->globsOf('autoload');
        $this->testFiles = $this->globsOf('autoload-dev');
        $this->testableFolders = array_values(array_filter((array) ($detectors['testable'] ?? []), is_string(...)));
        $sides = $this->sides($detectors);
        $files = $this->changes->trackedFiles();
        $read = 0;

        $progressed?->__invoke(0, count($files));

        foreach ($files as $index => $path) {
            $read += $this->read($path, $sides) ? 1 : 0;
            $progressed?->__invoke($index + 1, count($files));
        }

        return [
            'commit' => $this->changes->currentSha(),
            'files' => count($files),
            'read' => $read,
            'took' => (int) ((hrtime(true) - $started) / 1_000_000),
            'checks' => $this->checks,
            'classes' => $this->classes,
            'folders' => $this->folders,
            'tested' => $this->tested(),
        ];
    }

    /**
     * The folders the project's own composer.json loads classes from, as globs
     * for their PHP files. Which folders hold the app and which hold the tests
     * is the project's to say, not assumed here.
     *
     * @param  'autoload'|'autoload-dev'  $section
     * @return list<string>
     */
    private function globsOf(string $section): array
    {
        $composer = json_decode((string) @file_get_contents($this->root.'/composer.json'), true);

        return array_values(collect((array) ($composer[$section]['psr-4'] ?? []))
            ->flatMap(fn (mixed $folders): array => (array) $folders)
            ->filter(fn (mixed $folder): bool => is_string($folder))
            ->map(fn (string $folder): string => trim($folder, '/'))
            ->reject(fn (string $folder): bool => $folder === '' || str_contains($folder, '..'))
            ->unique()
            ->map(fn (string $folder): string => $folder.'/**/*.php')
            ->all());
    }

    /**
     * @return array{classes: int, tested: int, untested: list<string>}
     */
    private function tested(): array
    {
        $untested = collect($this->testable)
            ->reject(fn (string $path, string $name): bool => isset($this->referenced[$name]))
            ->sort()
            ->values();

        return [
            'classes' => count($this->testable),
            'tested' => count($this->testable) - $untested->count(),
            'untested' => array_values($untested->take(self::WHERE_LIMIT)->all()),
        ];
    }

    /**
     * @param  array{checks: array<string, array{count?: string, facts?: string, sides?: list<array{key: string, label: string, in: list<string>, match: string, absent?: bool, limit?: int}>}>}  $detectors
     * @return list<array{check: string, key: string, in: list<string>, match: string, absent: bool, limit: int}>
     */
    private function sides(array $detectors): array
    {
        return array_values(collect($detectors['checks'])
            ->flatMap(fn (array $check, string $key): array => array_map(fn (array $side): array => [
                'check' => $key,
                'key' => $side['key'],
                'in' => $side['in'],
                'match' => '~'.$side['match'].'~m',
                'absent' => $side['absent'] ?? false,
                'limit' => min(self::MOST_WHERE, $side['limit'] ?? self::WHERE_LIMIT),
            ], $check['sides'] ?? []))
            ->filter(fn (array $side): bool => @preg_match($side['match'], '') !== false)
            ->all());
    }

    /**
     * @param  list<array{check: string, key: string, in: list<string>, match: string, absent: bool, limit: int}>  $sides
     */
    private function read(string $path, array $sides): bool
    {
        $wanted = array_values(array_filter($sides, fn (array $side): bool => Glob::matchesAny($path, $side['in'])));
        $isAClass = Glob::matchesAny($path, $this->classFiles);
        $isATest = Glob::matchesAny($path, $this->testFiles);
        $file = $this->root.'/'.$path;

        if (($wanted === [] && ! $isAClass && ! $isATest) || ! is_file($file) || filesize($file) > self::LARGEST_FILE) {
            return false;
        }

        $source = (string) file_get_contents($file);
        array_map(fn (array $side) => $this->detect($side, $path, $source), $wanted);

        if ($isAClass) {
            $this->learnClass($path, $source);
        }

        if ($isATest) {
            $this->learnTest($source);
        }

        return true;
    }

    private function learnTest(string $source): void
    {
        preg_match_all(self::QUALIFIED_NAME, $source, $names);

        $this->referenced += array_fill_keys($names[1], true);
    }

    /**
     * @param  array{check: string, key: string, in: list<string>, match: string, absent: bool, limit: int}  $side
     */
    private function detect(array $side, string $path, string $source): void
    {
        $hits = (int) preg_match_all($side['match'], $source, $matches, PREG_OFFSET_CAPTURE);

        if ($side['absent'] ? $hits > 0 : $hits === 0) {
            return;
        }

        $found = $this->checks[$side['check']][$side['key']] ?? ['files' => 0, 'hits' => 0, 'where' => []];
        $lines = $side['absent'] ? [$path] : array_map(fn (array $match): string => $path.':'.(substr_count($source, "\n", 0, $match[1]) + 1), array_slice($matches[0], 0, max(0, $side['limit'] - count($found['where']))));

        $this->checks[$side['check']][$side['key']] = [
            'files' => $found['files'] + 1,
            'hits' => $found['hits'] + max(1, $hits),
            'where' => array_slice([...$found['where'], ...$lines], 0, $side['limit']),
        ];
    }

    private function learnClass(string $path, string $source): void
    {
        $facts = ClassFacts::of($source);
        $folder = implode('/', array_slice(explode('/', $path), 0, 2));
        $this->folders[$folder] = ($this->folders[$folder] ?? 0) + 1;

        if ($facts === null) {
            return;
        }

        if ($facts['kind'] === 'class' && preg_match('/\babstract\s+class\b/', $source) !== 1 && collect($this->testableFolders)->contains(fn (string $testable): bool => str_starts_with($path, $testable))) {
            $this->testable[$facts['name']] = $path;
        }

        $this->remember('extends', $facts['extends'], $path);
        $this->remember('implements', $facts['implements'], $path);
        $this->remember('traits', $facts['traits'], $path);
    }

    /**
     * @param  'extends'|'implements'|'traits'  $kind
     * @param  list<string>  $names
     */
    private function remember(string $kind, array $names, string $path): void
    {
        array_map(function (string $name) use ($kind, $path): void {
            $seen = $this->classes[$kind][$name] ?? ['count' => 0, 'where' => []];
            $this->classes[$kind][$name] = [
                'count' => $seen['count'] + 1,
                'where' => count($seen['where']) < self::WHERE_LIMIT ? [...$seen['where'], $path] : $seen['where'],
            ];
        }, $names);
    }
}
