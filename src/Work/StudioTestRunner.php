<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Scan\Load;
use ArtisanStudio\StudioCli\Scan\ProjectReads;
use ArtisanStudio\StudioCli\Scan\StudioChecks;
use ArtisanStudio\StudioCli\Scan\Tools\TestSuite;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use Closure;
use SimpleXMLElement;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The tests of a project, for a work or build run. Which tests cover some
 * files, and which test to follow when writing one, are for Artisan Studio to
 * say: it is asked, and only paths go back and forth. This machine runs the
 * tests the studio names with the project's own Pest or PHPUnit and reads
 * which failed.
 */
final class StudioTestRunner implements TestRunner
{
    private const int TIMEOUT = 900;

    private const int ALONE_UP_TO = 4;

    private const int LARGEST_EXAMPLE = 20_000;

    /**
     * @var array<string, list<string>>
     */
    private array $covering = [];

    public function __construct(
        private readonly string $root,
        private readonly StudioChecks $studio,
        private readonly Toolbox $toolbox,
    ) {}

    public function for(array $paths): array
    {
        $key = (string) json_encode(collect($paths)->unique()->sort()->values()->all());

        if (isset($this->covering[$key])) {
            return $this->covering[$key];
        }

        $answer = $this->studio->ask('covering-tests', $paths);
        $tests = $this->paths($answer['tests'] ?? null, fn (string $path): bool => str_ends_with($path, '.php'));

        return $answer === null ? [] : $this->covering[$key] = $tests;
    }

    public function example(array $paths): ?array
    {
        $reads = new ProjectReads($this->root);
        $examples = $this->paths($this->studio->ask('test-example', $paths)['examples'] ?? null, fn (string $path): bool => str_ends_with($path, '.php'));

        foreach ($examples as $path) {
            $content = $reads->readable($path);

            if (is_string($content) && strlen($content) <= self::LARGEST_EXAMPLE) {
                return ['path' => $path, 'content' => $content];
            }
        }

        return null;
    }

    public function failing(array $tests): ?array
    {
        $failures = $this->failures($tests);

        return $failures === null ? null : array_keys($failures);
    }

    public function failingLater(array $tests): Closure
    {
        $run = $this->start($tests);

        return function () use ($run, $tests): ?array {
            if ($run === null) {
                return $this->failing($tests);
            }

            $failures = $this->finish($run);

            return $failures === null ? null : array_keys($failures);
        };
    }

    public function failures(array $tests): ?array
    {
        return $this->finish($this->start($tests));
    }

    /**
     * Starts the tests and gives control back at once, so the checks of the
     * same change can run while they do. Null when they cannot be run at all.
     *
     * @param  list<string>  $tests
     * @return array{process: Process, report: string, suite: ?string}|null
     */
    private function start(array $tests): ?array
    {
        $report = sys_get_temp_dir().'/studio-work-tests-'.bin2hex(random_bytes(6)).'.xml';
        $pest = is_file($this->root.'/vendor/bin/pest');
        $runner = $pest ? 'vendor/bin/pest' : 'vendor/bin/phpunit';

        if ($tests === [] || ! is_file($this->root.'/'.$runner)) {
            return null;
        }

        $suite = $pest && count($tests) > self::ALONE_UP_TO ? TestSuite::of($this->root, $tests) : null;
        $which = $suite === null ? $tests : ['--parallel', '--processes='.Load::forTests(), '--configuration', $suite];
        $process = new Process([$runner, ...$which, '--log-junit', $report], $this->root, $this->toolbox->environment(), timeout: self::TIMEOUT);

        try {
            $process->start();
        } catch (Throwable) {
            self::remove($report);
            self::remove($suite);

            return null;
        }

        return ['process' => $process, 'report' => $report, 'suite' => $suite];
    }

    /**
     * Waits for tests started with start() and reads which failed, each with
     * what it said. Null when they could not run.
     *
     * @param  array{process: Process, report: string, suite: ?string}|null  $run
     * @return array<string, string>|null
     */
    private function finish(?array $run): ?array
    {
        if ($run === null) {
            return null;
        }

        try {
            $run['process']->wait();
        } catch (Throwable) {
            self::remove($run['report']);

            return null;
        } finally {
            self::remove($run['suite']);
        }

        return $this->read($run['report']);
    }

    /**
     * @return array<string, string>|null
     */
    private function read(string $report): ?array
    {
        try {
            $xml = is_file($report) && filesize($report) > 0 ? simplexml_load_string((string) file_get_contents($report)) : false;
        } catch (Throwable) {
            $xml = false;
        } finally {
            self::remove($report);
        }

        if (! $xml instanceof SimpleXMLElement) {
            return null;
        }

        return collect($xml->xpath('//testcase[failure or error]') ?: [])
            ->mapWithKeys(fn (SimpleXMLElement $case): array => [ltrim(str_replace($this->root.'/', '', explode('::', (string) $case['file'])[0]), '/').' › '.trim((string) $case['name']) => trim((string) ($case->failure ?? $case->error))])
            ->all();
    }

    /**
     * The well-formed paths of the project in an answer, and no others.
     *
     * @param  (Closure(string): bool)  $suits
     * @return list<string>
     */
    private function paths(mixed $answer, Closure $suits): array
    {
        $reads = new ProjectReads($this->root);

        return collect(is_array($answer) ? $answer : [])
            ->filter(fn (mixed $path): bool => is_string($path) && $reads->isProjectPath($path) && $suits($path))
            ->values()
            ->all();
    }

    private static function remove(?string $file): void
    {
        if ($file !== null && is_file($file)) {
            unlink($file);
        }
    }
}
