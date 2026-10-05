<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\Tools\Tests;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class TestsCommand extends Command
{
    public const string SIGNATURE = 'studio:tests';

    public const string TESTS = 'tests';

    public const string COVERAGE = 'pest-coverage';

    public const string COVERAGE_TASK = 'coverage';

    public const int TIMEOUT = 1900;

    protected $signature = self::SIGNATURE.'
        {--json : Print what would be sent, and send nothing}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Run your test suite in parallel, if you switched it on for the scan, and send Artisan Studio only the counts and the failing tests\' names and lines';

    public function handle(Studio $studio, TaskJournal $journal, ToolStatus $status): int
    {
        if (! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $asked = $studio->scanTools();

        if ($asked === null) {
            return $this->outcome('The studio did not say whether to run your tests. Check php artisan studio:settings.', self::FAILURE);
        }

        if (array_intersect([self::TESTS, self::COVERAGE], $asked) === []) {
            $status->testsOff();

            return $this->outcome('Your tests are switched off for this scan. You can switch them on when you authorize the next one.', self::SUCCESS);
        }

        $toolbox = new Toolbox;
        $tests = $toolbox->tool(self::TESTS);
        $covering = $tests instanceof Tests && in_array(self::COVERAGE, $asked, true);

        while ($covering && $status->testsAreRunning()) {
            sleep(2);
        }

        $quiet = $covering && ($status->tests()['state'] ?? null) === ToolStatus::RAN;

        if ($quiet) {
            $this->step($journal, 'Measuring code coverage, as you asked. This runs your tests once more, quietly in the background; your test results above stay as they are.');
            $status->coverageRunning();
        } else {
            $this->step($journal, 'Running your tests in parallel, as you asked. Your scan results are already in; this can take a few minutes.');
            $status->testsRunning();
        }

        if ($covering) {
            $tests->withCoverage();
        }

        $result = $toolbox->run([self::TESTS])[self::TESTS];

        if (! $quiet) {
            $status->testsFinished($result);
        }

        $coverage = $covering ? $tests->coverage(base_path()) : null;

        if ($coverage !== null) {
            $status->coverageFinished($coverage);
        }

        $payload = ['commit' => $this->commit(), 'tools' => [...($quiet ? [] : [self::TESTS => $result]), ...($coverage === null ? [] : [self::COVERAGE => $coverage])]];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($studio->submitScanToolResults($payload) === null) {
            return $this->outcome('The studio did not take the test results. Check php artisan studio:settings.', self::FAILURE);
        }

        return $this->outcome($quiet ? $this->coverageSummary($coverage ?? ['ran' => false]) : $this->summary($result), self::SUCCESS);
    }

    /**
     * @param  array{ran: bool, reason?: string, findings?: list<mixed>, summary?: array<string, int>}  $coverage
     */
    private function coverageSummary(array $coverage): string
    {
        return $coverage['ran']
            ? sprintf('Code coverage measured: %d%% of your code is covered, %s files barely tested. Only file names and percentages were sent.', (int) ($coverage['summary']['coverage'] ?? 0), number_format(count($coverage['findings'] ?? [])))
            : 'Code coverage was not measured: '.($coverage['reason'] ?? 'it did not run.');
    }

    /**
     * @param  array{ran: bool, reason?: string, took?: int, summary?: array<string, int>}  $result
     */
    private function summary(array $result): string
    {
        if (! $result['ran']) {
            return 'Your tests were not run: '.($result['reason'] ?? 'they did not start.');
        }

        $summary = [...['tests' => 0, 'failed' => 0, 'skipped' => 0], ...($result['summary'] ?? [])];

        return sprintf(
            'Your tests ran in %ss: %d passed, %d failed, %d skipped. Only the counts and the failing tests\' names and lines were sent.',
            round(($result['took'] ?? 0) / 1000),
            max(0, $summary['tests'] - $summary['failed'] - $summary['skipped']),
            $summary['failed'],
            $summary['skipped'],
        );
    }

    private function commit(): ?string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    private function step(TaskJournal $journal, string $line): void
    {
        $journal->note($line);

        if (! $this->option('plain') && ! $this->option('json')) {
            $this->components->twoColumnDetail($line);
        }
    }

    private function outcome(string $line, int $code): int
    {
        match (true) {
            (bool) $this->option('plain') => $this->line($line),
            $code === self::SUCCESS => $this->components->info($line),
            default => $this->components->error($line),
        };

        return $code;
    }
}
