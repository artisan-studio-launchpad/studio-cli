<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The checks a built step passes before it is committed, on only its own
 * files: Pint formats them, and PHPStan, as the project sets it up, reports
 * anything new in them since before the step.
 */
final class StepChecks
{
    private const int TIMEOUT = 600;

    public function __construct(private readonly string $root, private readonly Toolbox $toolbox) {}

    /**
     * @param  list<string>  $paths
     */
    public function format(array $paths): bool
    {
        $php = self::phpOf($paths);

        return $php === [] || ! $this->toolbox->canFix('pint') || $this->toolbox->fix('pint', $php)['ran'];
    }

    public function canCheckTypes(): bool
    {
        return $this->toolbox->tool('phpstan')?->command($this->root) !== null;
    }

    /**
     * What PHPStan reports in these files now, each as "line: message", or
     * null when it could not run.
     *
     * @param  list<string>  $paths
     * @return array<string, list<string>>|null
     */
    public function types(array $paths): ?array
    {
        $tool = $this->toolbox->tool('phpstan');
        $command = $tool?->command($this->root);
        $php = array_values(array_filter(self::phpOf($paths), fn (string $path): bool => ! str_ends_with($path, '.blade.php') && is_file($this->root.'/'.$path)));

        if ($tool === null || $command === null) {
            return null;
        }

        if ($php === []) {
            return [];
        }

        $process = new Process([...$command, ...$tool->pathArguments($php)], $this->root, $this->toolbox->environment(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $findings = $tool->findings($process->getOutput(), $this->root);

        return $findings === null ? null : collect($findings)
            ->groupBy(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']))
            ->map(fn (Collection $found): array => array_values($found->map(fn (array $finding): string => (preg_match('/:(\d+)$/', $finding['where'], $line) === 1 ? 'line '.$line[1].': ' : '').$finding['message'])->all()))
            ->all();
    }

    /**
     * What PHPStan reports now that it did not before, file by file, by the
     * message alone, so lines moved by the change are not counted as new.
     *
     * @param  array<string, list<string>>  $before
     * @param  array<string, list<string>>  $after
     * @return array<string, list<string>>
     */
    public static function added(array $before, array $after): array
    {
        return collect($after)->map(fn (array $now, string $path): array => self::newIn($before[$path] ?? [], $now))->filter()->all();
    }

    /**
     * @param  list<string>  $was
     * @param  list<string>  $now
     * @return list<string>
     */
    private static function newIn(array $was, array $now): array
    {
        $left = array_count_values(array_map(self::bare(...), $was));

        return array_values(array_filter($now, function (string $finding) use (&$left): bool {
            $key = self::bare($finding);
            $seen = ($left[$key] ?? 0) > 0;
            $seen && $left[$key]--;

            return ! $seen;
        }));
    }

    private static function bare(string $finding): string
    {
        return (string) preg_replace('/^line \d+: /', '', $finding);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function phpOf(array $paths): array
    {
        return array_values(array_filter($paths, fn (string $path): bool => str_ends_with($path, '.php')));
    }
}
