<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\Scan\ProjectReads;
use ArtisanStudio\StudioCli\Studio;

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
    private const int MOST_BYTES = 1_000_000;

    private const int MOST_FILES = 150;

    private const int MOST_ROUNDS = 4;

    /**
     * @var array<string, string>
     */
    private array $states = [];

    /**
     * @var array<string, string>
     */
    private array $changed = [];

    private readonly ProjectReads $reads;

    private int $requests = 0;

    private int $sent = 0;

    public function __construct(
        private readonly string $root,
        private readonly Studio $studio,
    ) {
        $this->reads = new ProjectReads($root);
    }

    /**
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     * @param  (callable(string, string): void)|null  $onFixed  told the rule and the file of each fix, batch by batch
     * @return array{fixed: list<array{key: string, label: string, path: string, finding: array{where: string, rule: string, message: string}}>, swept: list<array{where: string, rule: string, message: string}>, saved: list<string>, failed: int, requests: int, sent: int}
     */
    public function fix(array $findings, bool $sweep = false, ?callable $onFixed = null): array
    {
        $paths = array_values(array_unique(array_map(fn (array $finding): string => self::pathOf($finding['where']), array_merge(...array_values($findings)))));
        $batches = $this->batches($paths);
        $answers = collect($batches === [] && $sweep ? [[]] : $batches)
            ->map(function (array $batch, int $index) use ($findings, $sweep, $onFixed): ?array {
                $answer = $this->batch($batch, [
                    'findings' => array_map(fn (array $found): array => array_values(array_filter($found, fn (array $finding): bool => in_array(self::pathOf($finding['where']), $batch, true))), $findings),
                    'sweep' => $sweep && $index === 0,
                ]);

                collect($answer['fixed'] ?? [])->each(fn (array $fix): mixed => $onFixed === null ? null : $onFixed((string) $fix['key'], (string) $fix['path']));

                return $answer;
            });

        return [
            'fixed' => array_values($answers->filter()->flatMap(fn (array $answer): array => array_values(array_filter((array) ($answer['fixed'] ?? []), is_array(...))))->all()),
            'swept' => array_values(array_filter((array) ($answers->first()['swept'] ?? []), is_array(...))),
            'saved' => $this->write(),
            'failed' => $answers->filter(fn (?array $answer): bool => $answer === null)->count(),
            'requests' => $this->requests,
            'sent' => $this->sent,
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
     * One batch, sent again with what the fixers needed until they need
     * nothing more.
     *
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $asked
     * @return array<mixed>|null
     */
    private function batch(array $paths, array $asked): ?array
    {
        $extra = [];

        for ($round = 1; $round <= self::MOST_ROUNDS; $round++) {
            $files = $this->files([...$paths, ...$extra]);
            $this->requests++;
            $this->sent += array_sum(array_map(fn (array $file): int => strlen($file['content']), $files));
            $answer = $this->studio->fixScanFiles([...$this->project(), ...$asked, 'files' => $files, 'missing' => $this->reads->missing(), 'found' => (object) $this->reads->found()]);

            if ($answer === null) {
                return null;
            }

            $more = $round === self::MOST_ROUNDS ? [] : $this->reads->answer((array) ($answer['needs'] ?? []), $extra);

            if ($more === null) {
                $this->keep((array) ($answer['files'] ?? []));

                return $answer;
            }

            $extra = [...$extra, ...$more];
        }

        return null;
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

        return [
            'php' => (string) ($composer['require']['php'] ?? ''),
            'autoload' => (object) collect([...(array) ($composer['autoload']['psr-4'] ?? []), ...(array) ($composer['autoload-dev']['psr-4'] ?? [])])
                ->map(fn (mixed $folders): array => array_values(array_filter((array) $folders, is_string(...))))
                ->all(),
        ];
    }
}
