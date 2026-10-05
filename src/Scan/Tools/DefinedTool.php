<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\Load;
use ArtisanStudio\StudioCli\StateFile;
use ArtisanStudio\StudioCli\Studio;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A checking tool Artisan Studio describes: which of the project's own
 * binaries to run, when it is set up, and which part of what it prints may
 * leave. Only a binary already in vendor/bin runs, with plain arguments. The
 * studio reads what it printed into findings.
 */
final class DefinedTool extends Tool
{
    private const string STATE = 'described-tools';

    private const string KEY = '/^[a-z0-9-]{1,40}$/';

    private const string BIN = '/^[a-z0-9][a-z0-9._-]{0,60}$/';

    private const string ARGUMENT = '/^[A-Za-z0-9_.\/=:,@{}|-]{0,400}$/';

    /**
     * The only artisan commands a described tool may run: read-only checks.
     *
     * @var list<string>
     */
    private const array ARTISAN = ['config:validate'];

    private const string PATH = '/^[A-Za-z0-9_][A-Za-z0-9_.\/-]{0,200}$/';

    private const string READ = '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,80}\.(?:json|xml|dist|neon)$/';

    private const int LONGEST_OUTPUT = 16_000_000;

    private const int MOST_FOLDERS = 2000;

    /**
     * @var array<string, int>|null
     */
    private ?array $totals = null;

    /**
     * @var list<array{where: string, rule: string}>
     */
    private array $notRead = [];

    /**
     * @param  array<string, mixed>  $definition
     */
    private function __construct(private readonly array $definition) {}

    /**
     * Keeps what the studio described, for every command that runs a tool.
     *
     * @param  array<mixed>  $definitions
     */
    public static function keep(string $root, array $definitions): void
    {
        @file_put_contents(StateFile::for(self::STATE, $root), (string) json_encode(array_values(array_filter($definitions, fn (mixed $definition): bool => is_array($definition) && self::isValid($definition)))));
    }

    /**
     * @return list<self>
     */
    public static function all(string $root): array
    {
        $kept = json_decode((string) @file_get_contents(StateFile::for(self::STATE, $root)), true);

        return array_values(array_map(fn (array $definition): self => new self($definition), array_filter(is_array($kept) ? $kept : [], fn (mixed $definition): bool => is_array($definition) && self::isValid($definition))));
    }

    /**
     * @param  array<mixed>  $definition
     */
    public static function isValid(array $definition): bool
    {
        $strings = fn (mixed $list, string $pattern): bool => is_array($list) && array_is_list($list) && collect($list)->every(fn (mixed $item): bool => is_string($item) && preg_match($pattern, $item) === 1 && ! str_contains($item, '..'));
        $needs = (array) ($definition['needs'] ?? []);
        $lines = $definition['output']['lines'] ?? null;

        return is_string($definition['key'] ?? null) && preg_match(self::KEY, $definition['key']) === 1
            && is_string($definition['name'] ?? null)
            && is_string($definition['bin'] ?? null) && preg_match(self::BIN, $definition['bin']) === 1
            && (! isset($definition['args']) || $strings($definition['args'], self::ARGUMENT))
            && (! isset($definition['fix']) || $strings($definition['fix'], self::ARGUMENT))
            && $strings($needs['files'] ?? [], self::PATH)
            && $strings($needs['anyFile'] ?? [], self::PATH)
            && $strings($needs['folders'] ?? [], self::PATH)
            && $strings($needs['machine'] ?? [], self::BIN)
            && $strings($definition['reads'] ?? [], self::READ)
            && (! is_string($definition['pathPrefix'] ?? null) || preg_match(self::ARGUMENT, $definition['pathPrefix']) === 1)
            && ($lines === null || (is_string($lines) && @preg_match($lines, '') !== false));
    }

    public function key(): string
    {
        return (string) $this->definition['key'];
    }

    public function name(): string
    {
        return (string) $this->definition['name'];
    }

    public function group(): ?string
    {
        return is_string($this->definition['group'] ?? null) ? $this->definition['group'] : null;
    }

    /**
     * Whether it reads every file, so it waits for the tests.
     */
    public function isHeavy(): bool
    {
        return ($this->definition['heavy'] ?? false) === true;
    }

    /**
     * Whether the studio fixes what it finds, at the lines it reported.
     */
    public function isFixedByStudio(): bool
    {
        return ($this->definition['fixedByStudio'] ?? false) === true;
    }

    public function command(string $root): ?array
    {
        if (! $this->isReady($root)) {
            return null;
        }

        return isset($this->definition['args']) ? [...$this->binary(), ...$this->arguments($this->definition['args'], $root)] : [];
    }

    public function fixCommand(string $root): ?array
    {
        return isset($this->definition['fix']) && $this->isReady($root) && ! $this->isArtisan() ? [...$this->binary(), ...$this->arguments($this->definition['fix'], $root)] : null;
    }

    /**
     * @return list<string>
     */
    private function binary(): array
    {
        return $this->isArtisan() ? [PHP_BINARY, 'artisan'] : ['vendor/bin/'.$this->definition['bin']];
    }

    private function isArtisan(): bool
    {
        return $this->definition['bin'] === 'artisan';
    }

    public function fixesEvenWhenFailing(): bool
    {
        return ($this->definition['fixWhenFailing'] ?? false) === true;
    }

    public function pathArguments(array $paths): array
    {
        $prefix = is_string($this->definition['pathPrefix'] ?? null) ? $this->definition['pathPrefix'] : '';

        return array_map(fn (string $path): string => $prefix.$path, $paths);
    }

    public function timeout(): int
    {
        return min(3600, max(30, (int) ($this->definition['timeout'] ?? 600)));
    }

    public function missing(): string
    {
        return $this->machineHasAll() || ! is_string($this->definition['machineMissing'] ?? null)
            ? (string) ($this->definition['missing'] ?? 'Not installed in this project.')
            : $this->definition['machineMissing'];
    }

    public function install(): ?array
    {
        $install = $this->definition['install'] ?? null;

        return is_array($install) ? [
            'packages' => array_values(array_filter((array) ($install['packages'] ?? []), is_string(...))),
            'files' => array_filter((array) ($install['files'] ?? []), fn (mixed $content, mixed $path): bool => is_string($content) && is_string($path) && preg_match(self::READ, $path) === 1, ARRAY_FILTER_USE_BOTH),
            'about' => (string) ($install['about'] ?? ''),
        ] : null;
    }

    public function summary(): ?array
    {
        return $this->totals;
    }

    public function unread(string $output): array
    {
        return $this->notRead;
    }

    /**
     * What it printed goes to the studio, which reads it into findings. Only
     * the keys or lines the studio names leave, never a code snippet.
     */
    public function findings(string $output, string $root): ?array
    {
        $read = app(Studio::class)->readToolOutput($this->key(), [
            'output' => mb_substr($this->kept(str_replace([rtrim($root, '/').'/', str_replace('/', '\/', rtrim($root, '/').'/')], '', $output)), 0, self::LONGEST_OUTPUT),
            'files' => (object) $this->files($root),
            ...(($this->definition['packages'] ?? false) === true ? ['packages' => (object) (new Toolbox($root))->packages()] : []),
            ...(($this->definition['counts'] ?? false) === true ? ['counts' => (object) $this->phpFilesPerFolder($root)] : []),
        ]);

        if (! is_array($read) || ! is_array($read['findings'] ?? null)) {
            return null;
        }

        $this->totals = is_array($read['summary'] ?? null) ? array_map(intval(...), array_filter($read['summary'], is_numeric(...))) : null;
        $this->notRead = array_values(array_filter((array) ($read['unread'] ?? []), fn (mixed $unread): bool => is_array($unread) && is_string($unread['where'] ?? null) && is_string($unread['rule'] ?? null)));

        return array_values(array_map(
            fn (array $finding): array => $this->finding((string) preg_replace('/:\d+$/', '', (string) $finding['where']), preg_match('/:(\d+)$/', (string) $finding['where'], $line) === 1 ? (int) $line[1] : null, (string) ($finding['rule'] ?? ''), (string) ($finding['message'] ?? '')),
            array_filter($read['findings'], fn (mixed $finding): bool => is_array($finding) && is_string($finding['where'] ?? null)),
        ));
    }

    public function buildsOn(): bool
    {
        return ($this->definition['builtOn'] ?? false) === true;
    }

    private function isReady(string $root): bool
    {
        $needs = (array) ($this->definition['needs'] ?? []);
        $anyFile = (array) ($needs['anyFile'] ?? []);

        return ($this->isArtisan() ? is_file($root.'/artisan') && in_array($this->definition['args'][0] ?? null, self::ARTISAN, true) : is_file($root.'/vendor/bin/'.$this->definition['bin']))
            && collect((array) ($needs['files'] ?? []))->every(fn (string $file): bool => is_file($root.'/'.$file))
            && ($anyFile === [] || collect($anyFile)->contains(fn (string $file): bool => is_file($root.'/'.$file)))
            && collect((array) ($needs['folders'] ?? []))->every(fn (string $folder): bool => is_dir($root.'/'.$folder))
            && $this->machineHasAll();
    }

    private function machineHasAll(): bool
    {
        return collect((array) ($this->definition['needs']['machine'] ?? []))->every(function (string $binary): bool {
            $which = new Process(['which', $binary]);

            try {
                $which->run();
            } catch (Throwable) {
                return false;
            }

            return $which->isSuccessful();
        });
    }

    /**
     * The arguments with `{workers}` as the cores a tool may take, and
     * `{first:a|b}` as the first of those paths the project has.
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function arguments(array $arguments, string $root): array
    {
        return array_map(fn (string $argument): string => (string) preg_replace_callback(
            '/\{first:([^}]+)\}/',
            fn (array $paths): string => collect(explode('|', $paths[1]))->first(fn (string $path): bool => file_exists($root.'/'.$path)) ?? '',
            str_replace('{workers}', (string) Load::workers(), $argument),
        ), $arguments);
    }

    private function kept(string $output): string
    {
        $output = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\]8;[^\e\x07]*(?:\e\\\\|\x07)/', '', $output);
        $output = mb_check_encoding($output, 'UTF-8') ? $output : mb_convert_encoding($output, 'UTF-8', 'UTF-8');
        $kind = $this->definition['output']['kind'] ?? 'json';
        $lines = $this->definition['output']['lines'] ?? null;

        return match (true) {
            $kind === 'json' => $this->keptJson($output),
            is_string($lines) => implode("\n", array_values(array_filter(preg_split('/\R/', $output) ?: [], fn (string $line): bool => preg_match($lines, $line) === 1))),
            default => $output,
        };
    }

    /**
     * The JSON the tool printed, holding only the keys the studio reads, at
     * every depth.
     */
    private function keptJson(string $output): string
    {
        $start = strcspn($output, '[{');
        $decoded = $start === strlen($output) ? null : json_decode(substr($output, $start), true);
        $keep = array_values(array_filter((array) ($this->definition['output']['keep'] ?? []), is_string(...)));

        return is_array($decoded) ? (string) json_encode($this->only($decoded, $keep), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $keep
     * @return array<mixed>
     */
    private function only(array $value, array $keep): array
    {
        return collect($value)
            ->filter(fn (mixed $item, int|string $key): bool => is_int($key) || in_array($key, $keep, true))
            ->map(fn (mixed $item): mixed => is_array($item) ? $this->only($item, $keep) : $item)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function files(string $root): array
    {
        return collect((array) ($this->definition['reads'] ?? []))
            ->filter(fn (string $file): bool => is_file($root.'/'.$file) && filesize($root.'/'.$file) < 2_000_000)
            ->mapWithKeys(fn (string $file): array => [$file => (string) file_get_contents($root.'/'.$file)])
            ->all();
    }

    /**
     * How many PHP files each of the project's folders holds, three deep, so
     * the studio can count the files a tool read and found nothing in.
     *
     * @return array<string, int>
     */
    private function phpFilesPerFolder(string $root): array
    {
        $skipped = fn (SplFileInfo $file): bool => ! $file->isDir() || ! in_array($file->getFilename(), ['vendor', 'node_modules', 'storage', '.git'], true);
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), $skipped));

        return collect(iterator_to_array($files, false))
            ->map(fn (SplFileInfo $file): string => substr($file->getPathname(), strlen(rtrim($root, '/')) + 1))
            ->filter(fn (string $path): bool => str_ends_with($path, '.php') && str_contains($path, '/'))
            ->flatMap(fn (string $path): array => array_map(fn (int $depth): string => implode('/', array_slice(explode('/', $path), 0, $depth)), range(1, min(3, substr_count($path, '/')))))
            ->countBy()
            ->take(self::MOST_FOLDERS)
            ->all();
    }
}
