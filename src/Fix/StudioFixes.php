<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Scan\ProjectReads;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Support\Collection;

/**
 * The studio's own fixes, made in the studio. Each flagged file goes up once, in
 * batches, with its findings; the changed files come back and are written
 * once at the end, so no batch moves another's lines.
 *
 * When a fixer needs more of the project, another file or a search, it is
 * read here and the batch goes again with it. A file's edits from an earlier
 * batch travel back with the file. Only the project's own files are ever
 * sent, never one holding a secret, and the studio keeps none of them.
 */
final class StudioFixes
{
    public const int MOST_BYTES = 1_000_000;

    public const int MOST_FILES = 150;

    private const int MOST_ROUNDS = 4;

    private const int MOST_TRIES = 3;

    private const int RETRY_AFTER_MICROSECONDS = 700_000;

    /**
     * @var array<string, string>
     */
    private array $states = [];

    /**
     * @var array<string, string>
     */
    private array $changed = [];

    private readonly ProjectReads $reads;

    private readonly FixTimeline $timeline;

    private int $requests = 0;

    private int $sent = 0;

    /**
     * @var array{ms: int, parsing_ms: int, phases: array<string, int>, rules: list<array<string, mixed>>, fixers: list<array<string, mixed>>}
     */
    private array $engine = ['ms' => 0, 'parsing_ms' => 0, 'phases' => [], 'rules' => [], 'fixers' => []];

    public function __construct(
        private readonly string $root,
        private readonly Studio $studio,
    ) {
        $this->reads = new ProjectReads($root);
        $this->timeline = new FixTimeline($root);
    }

    /**
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     * @param  (callable(string, string): void)|null  $onFixed  told the rule and the file of each fix, batch by batch
     * @param  (callable(array<string, mixed>): void)|null  $onEvent  told how the run goes: the batches planned, each round, each retry and each failure
     * @return array{fixed: list<array{key: string, label: string, path: string, finding: array{where: string, rule: string, message: string}}>, swept: list<array{where: string, rule: string, message: string}>, saved: list<string>, failed: int, requests: int, sent: int, timings: array{ms: int, parsing_ms: int, phases: array<string, int>, rules: list<array<string, mixed>>, fixers: list<array<string, mixed>>}}
     */
    public function fix(array $findings, bool $sweep = false, ?callable $onFixed = null, ?callable $onEvent = null): array
    {
        BridgeGate::must();

        $paths = array_values(array_unique(array_map(fn (array $finding): string => self::pathOf($finding['where']), array_merge(...array_values($findings)))));
        sort($paths);
        $batches = $this->batches($paths);
        $planned = $batches === [] && $sweep ? [[]] : $batches;
        $this->tell($onEvent, ['type' => 'planned', 'batches' => count($planned), 'files' => count($paths)]);
        $answers = collect($planned)
            ->map(function (array $batch, int $index) use ($findings, $sweep, $onFixed, $onEvent): ?array {
                $answer = $this->batch($batch, [
                    'findings' => array_map(fn (array $found): array => array_values(array_filter($found, fn (array $finding): bool => in_array(self::pathOf($finding['where']), $batch, true))), $findings),
                    'sweep' => $sweep && $index === 0,
                ], $index + 1, $onEvent);

                collect($answer['fixed'] ?? [])->each(fn (array $fix): mixed => $onFixed === null ? null : $onFixed((string) $fix['key'], (string) $fix['path']));
                $this->tell($onEvent, ['type' => 'batch', 'batch' => $index + 1, 'fixes' => count((array) ($answer['fixed'] ?? [])), 'ok' => $answer !== null]);

                return $answer;
            });

        return [
            'fixed' => array_values($answers->filter()->flatMap(fn (array $answer): array => array_values(array_filter((array) ($answer['fixed'] ?? []), is_array(...))))->all()),
            'swept' => array_values(array_filter((array) ($answers->first()['swept'] ?? []), is_array(...))),
            'saved' => $this->timeline->time('write the changed files', fn (): array => $this->write()),
            'failed' => $answers->filter(fn (?array $answer): bool => $answer === null)->count(),
            'requests' => $this->requests,
            'sent' => $this->sent,
            'timings' => $this->timings(),
        ];
    }

    public static function pathOf(string $where): string
    {
        return (string) preg_replace('/:\d+$/', '', $where);
    }

    /**
     * The flagged files in batches small enough to send in one go.
     *
     * @param  list<string>  $paths
     * @return list<list<string>>
     */
    private function batches(array $paths): array
    {
        $batches = [];
        $bytes = 0;

        foreach (array_filter($paths, $this->reads->isSendable(...)) as $path) {
            $size = (int) filesize($this->root.'/'.$path);
            $last = array_key_last($batches);
            $starts = $last === null || count($batches[$last]) >= self::MOST_FILES || $bytes + $size > self::MOST_BYTES;
            $bytes = $starts ? $size : $bytes + $size;

            if ($starts) {
                $batches[] = [$path];
            } else {
                $batches[$last][] = $path;
            }
        }

        return $batches;
    }

    /**
     * One request to the studio, asked again a moment later when the studio
     * answered with a server error such as a database deadlock: nothing in the
     * request has changed, and a round lost to it loses the fixes that waited.
     *
     * @param  array<string, mixed>  $batch
     * @return array<mixed>|null
     */
    private function ask(array $batch): ?array
    {
        foreach (range(1, self::MOST_TRIES) as $try) {
            $answer = $this->studio->fixScanFiles($batch);

            if ($answer !== null || $try === self::MOST_TRIES || ! str_starts_with((string) $this->studio->lastFailure(), 'HTTP 5')) {
                return $answer;
            }

            usleep(self::RETRY_AFTER_MICROSECONDS);
        }

        return null;
    }

    /**
     * One batch. When a fixer asks for more of the project, only the findings
     * it could not answer go again, with their files and what was asked; what
     * the other fixers did is kept, so no round does the work of an earlier one.
     *
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $asked
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @return array{fixed: list<array<string, mixed>>, swept: list<array<string, mixed>>}|null
     */
    private function batch(array $paths, array $asked, int $number = 1, ?callable $onEvent = null): ?array
    {
        $extra = [];
        $send = $paths;
        $findings = (array) ($asked['findings'] ?? []);
        $made = ['fixed' => [], 'swept' => []];
        $legacy = false;

        for ($round = 1; $round <= self::MOST_ROUNDS; $round++) {
            $files = $this->timeline->time('read and encode the files', fn (): array => $this->files([...$send, ...$extra]));
            $this->requests++;
            $this->sent += array_sum(array_map(fn (array $file): int => strlen($file['content']), $files));
            $this->tell($onEvent, ['type' => 'round', 'batch' => $number, 'round' => $round, 'files' => count($files), 'findings' => array_sum(array_map(count(...), $findings))]);
            $asking = hrtime(true);
            $answer = $this->ask([
                ...$this->project(),
                'findings' => $findings,
                'sweep' => $round === 1 && ($asked['sweep'] ?? false),
                'done' => $this->done($made['fixed']),
                'files' => $files,
                'missing' => $this->reads->missing(),
                'found' => (object) $this->reads->found(),
            ]);
            $waited = (int) ((hrtime(true) - $asking) / 1_000_000);
            $engine = min($waited, (int) ($answer['timing']['engine_ms'] ?? 0));
            $this->timeline->add('studio: the fixers run', $engine);
            $this->timeline->add('studio: sending and waiting (network, upload, decode)', $waited - $engine);

            if ($answer === null) {
                $this->tell($onEvent, ['type' => 'failed', 'batch' => $number, 'round' => $round, 'reason' => $this->studio->lastFailure() ?? 'no answer']);

                return $round === 1 || $legacy ? null : $made;
            }

            $this->record($engine, (array) ($answer['timing'] ?? []));
            $needs = (array) ($answer['needs'] ?? []);
            $fixed = array_values(array_filter((array) ($answer['fixed'] ?? []), is_array(...)));
            $swept = array_values(array_filter((array) ($answer['swept'] ?? []), is_array(...)));

            $legacy = ! array_key_exists('unresolved', $answer);

            if ($legacy) {
                $made = ['fixed' => $fixed, 'swept' => $swept];
                $more = $round === self::MOST_ROUNDS
                    ? (($needs['read'] ?? []) === [] && ($needs['search'] ?? []) === [] ? null : [])
                    : $this->timeline->time('search the project for what the fixers asked', fn (): ?array => $this->reads->answer($needs, $extra));

                if ($more === null) {
                    $this->keep((array) ($answer['files'] ?? []));

                    return $made;
                }

                $this->tell($onEvent, ['type' => 'retry', 'batch' => $number, 'round' => $round, 'reads' => count((array) ($needs['read'] ?? [])), 'searches' => count((array) ($needs['search'] ?? [])), 'sent' => count($more), 'findings' => array_sum(array_map(count(...), $findings))]);
                $extra = [...$extra, ...$more];

                continue;
            }

            $this->keep((array) ($answer['files'] ?? []));
            $made = ['fixed' => [...$made['fixed'], ...$fixed], 'swept' => $round === 1 ? $swept : $made['swept']];
            $unresolved = array_values(array_filter((array) $answer['unresolved'], is_array(...)));

            if ($unresolved === []) {
                return $made;
            }

            if ($round === self::MOST_ROUNDS) {
                $this->tell($onEvent, ['type' => 'unfinished', 'batch' => $number, 'round' => $round, 'findings' => count($unresolved)]);

                return $made;
            }

            $more = $this->timeline->time('search the project for what the fixers asked', fn (): ?array => $this->reads->answer($needs, [...$send, ...$extra]));

            if ($more === null) {
                return $made;
            }

            $this->tell($onEvent, ['type' => 'retry', 'batch' => $number, 'round' => $round, 'reads' => count((array) ($needs['read'] ?? [])), 'searches' => count((array) ($needs['search'] ?? [])), 'sent' => count($more), 'findings' => count($unresolved)]);
            $extra = [...$extra, ...$more];
            $send = array_values(array_unique(array_map(fn (array $finding): string => self::pathOf((string) $finding['where']), $unresolved)));
            $findings = collect($unresolved)
                ->groupBy('key')
                ->map(fn (Collection $rows): array => $rows->map(fn (array $row): array => ['where' => $row['where'], 'rule' => $row['rule'], 'message' => $row['message']])->values()->all())
                ->all();
        }

        $this->tell($onEvent, ['type' => 'failed', 'batch' => $number, 'round' => self::MOST_ROUNDS, 'reason' => 'the fixers still wanted more after '.self::MOST_ROUNDS.' rounds, so this batch was dropped']);

        return null;
    }

    /**
     * The fixes already made in this batch, so the studio does not set aside a
     * rule in a file where one was fixed.
     *
     * @param  list<array<string, mixed>>  $fixed
     * @return list<array{key: string, rule: string, where: string}>
     */
    private function done(array $fixed): array
    {
        return collect($fixed)
            ->map(fn (array $fix): array => ['key' => (string) $fix['key'], 'rule' => (string) ($fix['finding']['rule'] ?? ''), 'where' => (string) ($fix['finding']['where'] ?? '')])
            ->unique(fn (array $row): string => $row['key']."\0".$row['where'])
            ->values()
            ->all();
    }

    /**
     * What the studio said about where the fixers spent their time.
     *
     * @param  array<string, mixed>  $timing
     */
    private function record(int $milliseconds, array $timing): void
    {
        $merge = fn (array $known, array $rows, callable $id): array => collect([...array_values($known), ...array_values(array_filter($rows, is_array(...)))])
            ->groupBy($id)
            ->map(fn (Collection $same): array => [
                ...$same->first(),
                'ms' => (int) $same->sum('ms'),
                'findings' => (int) $same->max('findings'),
                'calls' => (int) $same->sum('calls'),
                'fixed' => (int) $same->sum('fixed'),
                'asked' => (int) $same->sum('asked'),
            ])
            ->values()
            ->all();

        $this->engine = [
            'ms' => $this->engine['ms'] + $milliseconds,
            'parsing_ms' => $this->engine['parsing_ms'] + (int) ($timing['parsing_ms'] ?? 0),
            'phases' => collect((array) ($timing['phases'] ?? []))->reduce(fn (array $phases, mixed $ms, string $name): array => [...$phases, $name => ($phases[$name] ?? 0) + (int) $ms], $this->engine['phases']),
            'rules' => $merge($this->engine['rules'], (array) ($timing['rules'] ?? []), fn (array $row): string => ($row['key'] ?? '')."\0".($row['rule'] ?? '')),
            'fixers' => $merge($this->engine['fixers'], (array) ($timing['fixers'] ?? []), fn (array $row): string => (string) ($row['fixer'] ?? '')),
        ];
    }

    /**
     * Where the studio's fixers spent their time over the whole run: by rule,
     * by fixer, by phase, and the parsing of files, which the fixers' own time
     * includes.
     *
     * @return array{ms: int, parsing_ms: int, phases: array<string, int>, rules: list<array<string, mixed>>, fixers: list<array<string, mixed>>}
     */
    public function timings(): array
    {
        return [
            ...$this->engine,
            'rules' => collect($this->engine['rules'])->sortByDesc('ms')->values()->all(),
            'fixers' => collect($this->engine['fixers'])->sortByDesc('ms')->values()->all(),
        ];
    }

    /**
     * @param  (callable(array<string, mixed>): void)|null  $onEvent
     * @param  array<string, mixed>  $event
     */
    private function tell(?callable $onEvent, array $event): void
    {
        if ($onEvent !== null) {
            $onEvent($event);
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<array{path: string, content: string, state?: string}>
     */
    private function files(array $paths): array
    {
        return array_values(collect($paths)
            ->unique()
            ->map(fn (string $path): ?array => ($text = $this->reads->readable($path)) === null ? null : [
                'path' => $path,
                'content' => base64_encode($text),
                ...(isset($this->states[$path]) ? ['state' => $this->states[$path]] : []),
            ])
            ->filter()
            ->all());
    }

    /**
     * @param  array<mixed>  $files
     */
    private function keep(array $files): void
    {
        collect($files)
            ->filter(fn (mixed $file): bool => is_array($file) && is_string($file['path'] ?? null) && is_string($file['content'] ?? null) && $this->reads->isSendable($file['path']))
            ->each(function (array $file): void {
                $content = base64_decode($file['content'], true);

                if ($content !== false) {
                    $this->changed[$file['path']] = $content;
                    $this->states[$file['path']] = (string) ($file['state'] ?? '');
                }
            });
    }

    /**
     * @return list<string>
     */
    private function write(): array
    {
        $this->changed === [] || BridgeGate::must();

        return array_values(array_keys(array_filter(
            $this->changed,
            fn (string $content, string $path): bool => is_file($this->root.'/'.$path) && @file_put_contents($this->root.'/'.$path, $content) !== false,
            ARRAY_FILTER_USE_BOTH,
        )));
    }

    /**
     * What the studio needs to know of the project to find a class's file and
     * the PHP it runs on, from its composer.json.
     *
     * @return array{php: string, autoload: array<string, list<string>>}
     */
    private function project(): array
    {
        $composer = json_decode((string) @file_get_contents($this->root.'/composer.json'), true);
        $composer = is_array($composer) ? $composer : [];

        $autoload = collect([[$composer, ''], ...$this->moduleComposers($composer)])
            ->flatMap(fn (array $module): array => $this->namespacesOf($module[0], $module[1]))
            ->groupBy(0)
            ->map(fn (Collection $rows): array => $rows->flatMap(fn (array $row): array => $row[1])->unique()->values()->all())
            ->all();

        return [
            'php' => (string) ($composer['require']['php'] ?? ''),
            'autoload' => (object) $autoload,
        ];
    }

    /**
     * @param  array<mixed>  $definition
     * @return list<array{0: string, 1: list<string>}> namespace and the folders it maps to, from the project's root
     */
    private function namespacesOf(array $definition, string $prefix): array
    {
        return collect([...(array) ($definition['autoload']['psr-4'] ?? []), ...(array) ($definition['autoload-dev']['psr-4'] ?? [])])
            ->map(fn (mixed $folders, string|int $namespace): array => [(string) $namespace, array_values(array_map(fn (string $folder): string => $prefix.$folder, array_filter((array) $folders, is_string(...))))])
            ->values()
            ->all();
    }

    /**
     * The composer.json of every module a modular project keeps outside its own
     * autoload: the ones the merge plugin includes, and the folders the usual
     * module packages use. Each comes with the folder it sits in, so its
     * namespaces map to real paths.
     *
     * @param  array<mixed>  $composer
     * @return list<array{0: array<mixed>, 1: string}>
     */
    private function moduleComposers(array $composer): array
    {
        $included = array_values(array_filter((array) ($composer['extra']['merge-plugin']['include'] ?? []), is_string(...)));

        return collect([...$included, 'Modules/*/composer.json', 'modules/*/composer.json'])
            ->flatMap(fn (string $pattern): array => glob($this->root.'/'.ltrim($pattern, '/'), GLOB_NOSORT) ?: [])
            ->unique()
            ->map(fn (string $file): array => [json_decode((string) @file_get_contents($file), true), ltrim(substr(dirname($file), strlen($this->root)), '/')])
            ->filter(fn (array $module): bool => is_array($module[0]) && $module[1] !== '')
            ->map(fn (array $module): array => [$module[0], $module[1].'/'])
            ->values()
            ->all();
    }
}
