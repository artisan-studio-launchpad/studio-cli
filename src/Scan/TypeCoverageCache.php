<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\Scan\Tools\TypeCoverage;
use ParseError;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Pest's type coverage keeps what it found in one file, by the hash of each
 * source file, and never forgets one. It reads the whole file again for every
 * file it checks, so a cache grown over months, with every version of every
 * file, makes a run that finds nothing new take about a minute.
 *
 * Before a scan, the entries for files that no longer exist as they were, and
 * for files of another project, are dropped. It is only a cache: whatever is
 * dropped is worked out again if it is ever needed.
 */
final class TypeCoverageCache
{
    private const string ENTRY = '/^(?=  \'[a-f0-9]{32}\' => )/m';

    public function __construct(private readonly string $root) {}

    /**
     * How many entries were dropped.
     */
    public function prune(): int
    {
        try {
            $files = glob($this->root.'/'.TypeCoverage::PLUGIN.'/.temp/v*.php') ?: [];
            $live = $files === [] ? [] : $this->liveHashes();

            return $live === [] ? 0 : array_sum(array_map(fn (string $file): int => $this->pruneFile($file, $live), $files));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, true>  $live
     */
    private function pruneFile(string $file, array $live): int
    {
        $lock = @fopen($file.'.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }

        try {
            return $this->rewrite($file, $live);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, true>  $live
     */
    private function rewrite(string $file, array $live): int
    {
        $content = (string) file_get_contents($file);
        $chunks = preg_split(self::ENTRY, $content) ?: [];

        if (count($chunks) < 3 || preg_match('/\);\s*$/', $content) !== 1) {
            return 0;
        }

        $head = (string) array_shift($chunks);
        $chunks[array_key_last($chunks)] = (string) preg_replace('/\);\s*$/', '', $chunks[array_key_last($chunks)]);
        $kept = array_values(array_filter($chunks, fn (string $chunk): bool => isset($live[substr($chunk, 3, 32)]) && $this->isOurs($chunk)));

        if (count($kept) * 1.5 > count($chunks)) {
            return 0;
        }

        $pruned = $head.implode('', $kept).');';

        if (! $this->isValid($pruned)) {
            return 0;
        }

        $temporary = $file.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, $pruned) === false || ! @rename($temporary, $file)) {
            @unlink($temporary);

            return 0;
        }

        @chmod($file, 0666);

        return count($chunks) - count($kept);
    }

    private function isOurs(string $chunk): bool
    {
        return preg_match('/=> \s*array \(\s*0 => \'([^\']*)\'/', $chunk, $found) !== 1 || str_starts_with($found[1], $this->root.'/');
    }

    private function isValid(string $code): bool
    {
        try {
            token_get_all($code, TOKEN_PARSE);

            return true;
        } catch (ParseError) {
            return false;
        }
    }

    /**
     * The hash of every source file the type coverage looks at.
     *
     * @return array<string, true>
     */
    private function liveHashes(): array
    {
        $folders = array_values(array_filter(array_map(fn (string $folder): string => $this->root.'/'.trim($folder, '/'), $this->sources()), is_dir(...)));

        return $folders === [] ? [] : collect(iterator_to_array(Finder::create()->in($folders)->name('*.php')->notName('*.blade.php')->files(), false))
            ->mapWithKeys(fn (mixed $file): array => [(string) md5_file($file->getRealPath()) => true])
            ->all();
    }

    /**
     * The folders phpunit.xml names as source, or app.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $xml = collect(['phpunit.xml', 'phpunit.xml.dist'])->map(fn (string $name): string => $this->root.'/'.$name)->first(fn (string $path): bool => is_file($path));
        $document = $xml === null ? false : @simplexml_load_file($xml);
        $folders = $document === false ? [] : array_map(strval(...), (array) ($document->xpath('//source/include/directory') ?: []));

        return $folders === [] ? ['app'] : array_values($folders);
    }
}
