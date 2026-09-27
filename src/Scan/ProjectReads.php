<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * What this machine sends Artisan Studio when it asks to read more of the
 * project: the files it names, and the files whose text matches a search.
 * Never a vendor folder or a .env, never a file holding a secret, and never
 * more than a set amount at a time. The studio keeps none of it.
 */
final class ProjectReads
{
    private const int MOST_FOUND = 2000;

    private const int LARGEST_FILE = 1_500_000;

    private const int LONGEST_PATTERN = 2000;

    /**
     * @var list<string>
     */
    private const array EXTENSIONS = ['php', 'js', 'ts', 'vue', 'jsx', 'tsx', 'mjs', 'cjs'];

    /**
     * Files other than code the studio may read: the PHPStan config it
     * fixes, and the lockfile's package versions.
     *
     * @var list<string>
     */
    private const array NAMED = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon', 'composer.lock'];

    /**
     * @var list<string>
     */
    private const array SKIPPED = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache'];

    /**
     * @var array<string, list<string>>
     */
    private array $found = [];

    /**
     * @var array<string, true>
     */
    private array $missing = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $texts = [];

    public function __construct(
        private readonly string $root,
        private readonly int $mostBytesEachTime = 2_000_000,
    ) {}

    /**
     * What each search found, by its id.
     *
     * @return array<string, list<string>>
     */
    public function found(): array
    {
        return $this->found;
    }

    /**
     * The files asked for that are not here, or are never sent.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_keys($this->missing);
    }

    /**
     * Reads and searches what the studio asked for. Null when it asked for
     * nothing this machine has not already answered.
     *
     * @param  array<mixed>  $needs
     * @param  list<string>  $sent
     * @return list<string>|null
     */
    public function answer(array $needs, array $sent): ?array
    {
        $reads = array_values(array_filter((array) ($needs['read'] ?? []), is_string(...)));
        $searches = array_values(array_filter((array) ($needs['search'] ?? []), fn (mixed $search): bool => is_array($search) && is_string($search['id'] ?? null) && ! isset($this->found[$search['id']])));

        if ($reads === [] && $searches === []) {
            return null;
        }

        $searched = collect($searches)->flatMap(function (array $search): array {
            $this->found[$search['id']] = $this->search($search);

            return ($search['read'] ?? false) === true ? array_filter($this->found[$search['id']], $this->isSendable(...)) : [];
        });

        $wanted = $searched->merge($reads)->unique()->reject(fn (string $path): bool => in_array($path, $sent, true) || isset($this->missing[$path]))->values();
        $wanted->reject($this->isSendable(...))->each(function (string $path): void {
            $this->missing[$path] = true;
        });

        $budget = $this->mostBytesEachTime;
        $more = $wanted->filter($this->isSendable(...))->filter(function (string $path) use (&$budget): bool {
            $budget -= (int) filesize($this->root.'/'.$path);

            return $budget >= 0;
        })->values()->all();

        return $searches === [] && $wanted->isEmpty() ? null : $more;
    }

    /**
     * A file's text to send, or null when it is not here or never sent.
     */
    public function readable(string $path): ?string
    {
        if (! $this->isSendable($path)) {
            $this->missing[$path] = true;

            return null;
        }

        $text = (string) @file_get_contents($this->root.'/'.$path);

        if ($this->holdsSecret($text)) {
            $this->missing[$path] = true;

            return null;
        }

        return $text;
    }

    public function isSendable(string $path): bool
    {
        $full = $this->root.'/'.$path;

        return $this->isProjectPath($path)
            && (in_array(pathinfo($path, PATHINFO_EXTENSION), self::EXTENSIONS, true) || in_array($path, self::NAMED, true))
            && ! $this->isSkipped($path)
            && is_file($full)
            && filesize($full) <= self::LARGEST_FILE;
    }

    public function isProjectPath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '..') && preg_match('/[\r\n\0]/', $path) !== 1;
    }

    /**
     * Files under the folders asked whose text matches, as this machine has
     * them: never a vendor folder, never a file holding a secret.
     *
     * @param  array<mixed>  $search
     * @return list<string>
     */
    private function search(array $search): array
    {
        $pattern = $search['pattern'] ?? null;
        $extensions = array_values(array_intersect((array) ($search['ext'] ?? []), self::EXTENSIONS));
        $folders = array_values(array_filter((array) ($search['in'] ?? []), fn (mixed $folder): bool => is_string($folder) && $this->isProjectPath($folder)));

        if (! is_string($pattern) || strlen($pattern) > self::LONGEST_PATTERN || @preg_match($pattern, '') === false) {
            return [];
        }

        return array_values(collect($folders)
            ->flatMap(fn (string $folder): array => $this->texts($folder))
            ->filter(fn (string $text, string $path): bool => in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true) && preg_match($pattern, $text) === 1)
            ->keys()
            ->unique()
            ->sort()
            ->take(self::MOST_FOUND)
            ->all());
    }

    /**
     * Every searchable file under a folder, read once for the whole run.
     *
     * @return array<string, string>
     */
    private function texts(string $folder): array
    {
        $folder = trim($folder, '/');
        $full = $this->root.'/'.$folder;

        return $this->texts[$folder] ??= ! is_dir($full) ? [] : collect(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)), false))
            ->filter(fn (SplFileInfo $file): bool => $file->isFile() && in_array($file->getExtension(), self::EXTENSIONS, true) && $file->getSize() <= self::LARGEST_FILE)
            ->mapWithKeys(fn (SplFileInfo $file): array => [substr($file->getPathname(), strlen($this->root) + 1) => (string) @file_get_contents($file->getPathname())])
            ->reject(fn (string $text, string $path): bool => $this->isSkipped($path) || $this->holdsSecret($text))
            ->all();
    }

    private function isSkipped(string $path): bool
    {
        return collect(self::SKIPPED)->contains(fn (string $folder): bool => $path === $folder || str_starts_with($path, $folder.'/'))
            || preg_match('#(^|/)\.env#', $path) === 1;
    }

    private function holdsSecret(string $text): bool
    {
        return collect(Secrets::KINDS)->contains(fn (array $kind): bool => preg_match($kind['pattern'], $text) === 1);
    }
}
