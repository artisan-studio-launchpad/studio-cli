<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\Load;
use ArtisanStudio\StudioCli\Scan\Secrets;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

class Tests extends Tool
{
    /**
     * A file this far below fully covered is a finding.
     */
    public const int WELL_COVERED = 50;

    public const string KEY = 'tests';

    private const int LONGEST_DETAIL = 6000;

    /**
     * A longer reason keeps its start and its end, where an assertion on a
     * whole page says what it looked for.
     */
    private const int KEPT_FROM_START = 110;

    private ?string $report = null;

    /**
     * The run's TeamCity log, which keeps what a failed comparison expected
     * and what it got: the JUnit report drops both.
     */
    private ?string $compared = null;

    private ?string $clover = null;

    private bool $covering = false;

    private ?string $root = null;

    private ?string $suite = null;

    /** @var array{tests: int, failed: int, skipped: int}|null */
    private ?array $totals = null;

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Your tests';
    }

    public const int RECOMMENDED_MINUTES = 5;

    public function timeout(): int
    {
        return max(1, (int) config('studio-cli.tests.timeout_minutes', self::RECOMMENDED_MINUTES)) * 60;
    }

    public function tooSlow(): string
    {
        return 'Took longer than '.intdiv($this->timeout(), 60).' minutes, so it was stopped. A suite this slow holds up every scan and needs fixing.';
    }

    public function missing(): string
    {
        return 'This project has no Pest or PHPUnit to run its tests with.';
    }

    /**
     * Measures code coverage in the same run, when the machine has a
     * driver for it.
     */
    public function withCoverage(): static
    {
        $this->covering = true;

        return $this;
    }

    public function canCover(): bool
    {
        return extension_loaded('pcov') || extension_loaded('xdebug');
    }

    public function environment(): array
    {
        return $this->covering && ! extension_loaded('pcov') && extension_loaded('xdebug') ? ['XDEBUG_MODE' => 'coverage'] : [];
    }

    public function command(string $root): ?array
    {
        $this->root = $root;
        $this->report = sys_get_temp_dir().'/studio-tests-'.bin2hex(random_bytes(6)).'.xml';
        $this->compared = sys_get_temp_dir().'/studio-tests-'.bin2hex(random_bytes(6)).'.tc';
        $this->clover = $this->covering && $this->canCover() ? sys_get_temp_dir().'/studio-coverage-'.bin2hex(random_bytes(6)).'.xml' : null;
        $coverage = $this->clover === null ? [] : ['--coverage-clover', $this->clover];

        return match (true) {
            $this->bin($root, 'pest') !== null => ['vendor/bin/pest', '--parallel', '--processes='.Load::forTests(), ...($this->offers($root, ['vendor/bin/pest', '--help'], '--no-tia') ? ['--no-tia'] : []), '--log-junit', $this->report, '--log-teamcity', $this->compared, ...$coverage],
            $this->bin($root, 'paratest') !== null => ['vendor/bin/paratest', '--processes='.Load::forTests(), '--log-junit', $this->report, ...$coverage],
            $this->bin($root, 'phpunit') !== null => ['vendor/bin/phpunit', '--log-junit', $this->report, '--log-teamcity', $this->compared, ...$coverage],
            default => null,
        };
    }

    /**
     * Several files run in parallel through a suite of just those files,
     * since a parallel run takes a single path; one file runs on its own.
     *
     * @param  list<string>  $command
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function scopedTo(array $command, array $paths): array
    {
        $this->suite = count($paths) > 1 && in_array('--parallel', $command, true) && $this->root !== null ? TestSuite::of($this->root, $paths) : null;

        if ($this->suite !== null) {
            return [...$command, '--configuration', $this->suite];
        }

        return collect($command)
            ->reject(fn (string $part): bool => $part === '--parallel' || str_starts_with($part, '--processes='))
            ->map(fn (string $part): string => $part === 'vendor/bin/paratest' ? 'vendor/bin/phpunit' : $part)
            ->push(...$paths)
            ->values()
            ->all();
    }

    public function findings(string $output, string $root): ?array
    {
        $report = $this->report === null ? null : $this->read($this->report);
        $compared = $this->comparisons();
        $this->totals = null;
        TestSuite::remove($this->suite);
        $this->suite = null;

        if ($report === null) {
            return null;
        }

        $this->totals = [
            'tests' => $this->sum($report, 'tests'),
            'failed' => $this->sum($report, 'failures') + $this->sum($report, 'errors'),
            'skipped' => $this->sum($report, 'skipped'),
        ];

        return array_values(collect($report->xpath('//testcase[failure or error]') ?: [])
            ->map(fn (SimpleXMLElement $case): array => [
                ...$this->finding(
                    $this->where($case, $root),
                    null,
                    isset($case->error) ? 'error' : 'failed',
                    trim((string) $case['name']).' failed.',
                ),
                ...$this->why($case, $compared[self::named((string) $case['name'])] ?? null),
            ])
            ->all());
    }

    /**
     * What each failed comparison expected and got, by test, from the
     * TeamCity log.
     *
     * @return array<string, array{expected: string, actual: string}>
     */
    private function comparisons(): array
    {
        $log = $this->compared !== null && is_file($this->compared) ? (string) file_get_contents($this->compared) : '';
        $this->compared === null || @unlink($this->compared);
        $this->compared = null;
        $value = fn (string $text): string => strtr($text, ["|'" => "'", '|n' => "\n", '|r' => '', '|[' => '[', '|]' => ']', '||' => '|']);

        preg_match_all("/##teamcity\[testFailed name='((?:[^'|]|\|.)*)'.*?type='comparisonFailure' actual='((?:[^'|]|\|.)*)' expected='((?:[^'|]|\|.)*)'/s", $log, $failed, PREG_SET_ORDER);

        return collect($failed)
            ->mapWithKeys(fn (array $match): array => [self::named($value($match[1])) => ['expected' => $value($match[3]), 'actual' => $value($match[2])]])
            ->all();
    }

    /**
     * One test's name as both reports can be matched on.
     */
    private static function named(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::after($name, '__pest_evaluable_'))), '_');
    }

    /**
     * @return array{tests: int, failed: int, skipped: int}|null
     */
    public function summary(): ?array
    {
        return $this->totals;
    }

    /**
     * What the run's coverage report says: the share of statements covered,
     * and each file under {@see self::WELL_COVERED}%.
     *
     * @return array{ran: bool, reason?: string, findings?: list<array{where: string, rule: string, message: string}>, summary?: array{coverage: int}}
     */
    public function coverage(string $root): array
    {
        if (! $this->canCover()) {
            return ['ran' => false, 'reason' => 'Code coverage needs PCOV or Xdebug on this machine. Install PCOV with pecl install pcov, then scan again.'];
        }

        $clover = $this->clover === null ? null : $this->read($this->clover);

        if ($clover === null) {
            return ['ran' => false, 'reason' => 'Your tests did not write a coverage report.'];
        }

        $project = $clover->xpath('/coverage/project/metrics')[0] ?? null;
        $percent = fn (SimpleXMLElement $metrics): int => (int) $metrics['statements'] === 0 ? 100 : (int) floor(100 * (int) $metrics['coveredstatements'] / (int) $metrics['statements']);

        return [
            'ran' => true,
            'findings' => array_values(collect($clover->xpath('//file') ?: [])
                ->map(fn (SimpleXMLElement $file): array => ['path' => $this->relative((string) $file['name'], $root), 'percent' => $percent($file->metrics)])
                ->filter(fn (array $file): bool => $file['percent'] < self::WELL_COVERED)
                ->map(fn (array $file): array => $this->finding($file['path'], null, $file['percent'] === 0 ? 'uncovered' : 'low-coverage', $file['percent'] === 0 ? 'No test runs this file.' : 'Tests run '.$file['percent'].'% of this file.'))
                ->all()),
            'summary' => ['coverage' => $project === null ? 0 : $percent($project)],
        ];
    }

    private function read(string $path): ?SimpleXMLElement
    {
        try {
            $xml = is_file($path) ? simplexml_load_string((string) file_get_contents($path)) : false;
        } catch (Throwable) {
            $xml = false;
        } finally {
            @unlink($path);
        }

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }

    private function sum(SimpleXMLElement $report, string $attribute): int
    {
        return (int) collect($report->xpath('/testsuites/testsuite') ?: [])->sum(fn (SimpleXMLElement $suite): int => (int) $suite[$attribute]);
    }

    /**
     * Why the test failed, as its message says it up to the stack trace, so
     * the fix starts from the failure and not the test's name alone. An email
     * address or a key in it never leaves this machine.
     *
     * @return array{detail?: string}
     */
    private function why(SimpleXMLElement $case, ?array $comparison = null): array
    {
        $name = trim((string) $case['name']);
        $said = trim((string) ($case->failure ?? $case->error ?? ''));
        $traced = fn (string $line): bool => preg_match('/^(at \S|\d+▕|➜|[A-Za-z]:\\\\|\/[\w.\-]+\/)/u', $line) === 1;
        $message = collect(preg_split('/\R/', $name !== '' && (str_starts_with($said, $name) || str_starts_with(lcfirst($said), $name)) ? substr($said, strlen($name)) : $said) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->reject(fn (string $line): bool => $line === '' || $line === '@@ @@')
            ->skipWhile($traced)
            ->takeUntil($traced)
            ->implode(' ');
        $compact = fn (string $value): string => trim((string) preg_replace(['/Array &\d+ /', '/\s*\d+ => /', '/\s+/'], ['', ' ', ' '], $value));
        $message = $comparison === null || $message === '' || str_contains($message, '+++ Actual') ? $message : $message.' Expected: '.$compact($comparison['expected']).' Got: '.$compact($comparison['actual']);

        $masked = $this->masked($this->expectedBesideActual($message) ?? $message);

        return $message === '' ? [] : ['detail' => mb_strlen($masked) <= self::LONGEST_DETAIL ? $masked : mb_substr($masked, 0, self::KEPT_FROM_START).' … '.mb_substr($masked, -(self::LONGEST_DETAIL - self::KEPT_FROM_START - 3))];
    }

    /**
     * For an assertion that looked for text in a whole page or response, what
     * it looked for beside the nearest thing the page does say, so the fix is
     * read from the failure instead of the page.
     */
    private function expectedBesideActual(string $message): ?string
    {
        if (preg_match('/^Failed asserting that \'(.*)\'(?: \[[^\]]*\]\(length: \d+\))? (contains|does not contain) "(.*?)"(?: \[[^\]]*\]\(length: \d+\))?( in specified order)?\.*$/s', $message, $parts) !== 1) {
            return null;
        }

        [, $haystack, $how, $needle] = $parts;
        $rendered = $this->renderedIn($haystack);

        if ($rendered !== null && str_contains($rendered, $needle) === ($how === 'contains')) {
            return sprintf('Expected the output %s "%s"%s. What it checked is a Livewire JSON reply, and the HTML rendered inside that reply %s "%s": the assertion read the reply instead of the rendered HTML, so the app renders it as the test wants.', $how === 'contains' ? 'to contain' : 'not to contain', mb_substr($needle, 0, 160), $parts[4] ?? '', $how === 'contains' ? 'does contain' : 'does not contain', mb_substr($needle, 0, 160));
        }

        $escaped = collect([str_replace('/', '\\/', $needle), str_replace('/', '\\\\\\/', $needle), htmlspecialchars($needle, ENT_QUOTES)])
            ->first(fn (string $form): bool => $form !== $needle && ! str_contains($haystack, $needle) && str_contains($haystack, $form));

        if ($escaped !== null) {
            return sprintf('Expected the output %s "%s"%s. The output has it only escaped, as "%s": the assertion reads escaped output, such as a JSON response or encoded HTML.', $how === 'contains' ? 'to contain' : 'not to contain', mb_substr($needle, 0, 160), $parts[4] ?? '', mb_substr($escaped, 0, 200));
        }

        $words = preg_split('/\s+/', trim($needle)) ?: [];
        $windows = collect(range(count($words), 1))
            ->flatMap(fn (int $size): array => array_map(fn (int $from): string => implode(' ', array_slice($words, $from, $size)), range(0, count($words) - $size)))
            ->merge(collect(preg_split('/[^\w\-]+/', $needle) ?: [])->filter(fn (string $piece): bool => mb_strlen($piece) >= 4)->sortByDesc(fn (string $piece): int => mb_strlen($piece))->values())
            ->filter(fn (string $window): bool => mb_strlen($window) >= 4);
        $found = $windows->first(fn (string $window): bool => str_contains($haystack, $window));
        $at = $found === null ? false : strpos($haystack, $found);
        $nearest = $at === false ? null : trim((string) preg_replace('/\s+/', ' ', substr($haystack, max(0, $at - 70), strlen((string) $found) + 140)));

        return sprintf('Expected the output %s "%s"%s. %s', $how === 'contains' ? 'to contain' : 'not to contain', mb_substr($needle, 0, 160), $parts[4] ?? '', $nearest === null ? 'Nothing like it is in the output.' : 'The output has: …'.$nearest.'…');
    }

    /**
     * The HTML a Livewire JSON reply carries for its components, when the
     * output an assertion checked is such a reply.
     */
    private function renderedIn(string $output): ?string
    {
        $reply = str_starts_with(ltrim($output), '{"components"') ? json_decode($output, true) : null;
        $html = is_array($reply) ? collect((array) ($reply['components'] ?? []))->map(fn (mixed $component): string => is_array($component) ? (string) ($component['effects']['html'] ?? '') : '')->implode("\n") : '';

        return trim($html) === '' ? null : $html;
    }

    private function masked(string $line): string
    {
        $withoutKeys = (string) preg_replace(array_column(Secrets::KINDS, 'pattern'), '[a key]', $line);

        return (string) preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[an email]', $withoutKeys);
    }

    private function where(SimpleXMLElement $case, string $root): string
    {
        $file = $this->relative(explode('::', (string) $case['file'])[0], $root);
        $body = (string) ($case->failure ?? $case->error ?? '');
        $line = preg_match('~'.preg_quote($file, '~').':(\d+)~', $body, $match) === 1 ? ':'.$match[1] : '';

        return $file === '' ? 'tests' : $file.$line;
    }
}
