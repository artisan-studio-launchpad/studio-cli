<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\ScanProgress;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Scan\Walk;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Illuminate\Console\Command;

class ConventionsCommand extends Command
{
    public const string SIGNATURE = 'studio:conventions';

    protected $signature = self::SIGNATURE.'
        {--json : Print what would be sent, and send nothing}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Count how your project is written and send Artisan Studio the counts, with file and line pointers, never code';

    public function handle(Walk $walk, Studio $studio, ScanProgress $progress, TaskJournal $journal): int
    {
        if (! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $detectors = $studio->conventionDetectors();

        if ($detectors === null) {
            return $this->outcome('The studio did not hand over what to look for. Check php artisan studio:settings.', self::FAILURE);
        }

        $scan = $studio->scanDetectors()['checks'] ?? [];
        $checks = count($detectors['checks']);
        $this->step($journal, "Asked the studio what to look for: {$checks} conventions and ".count($scan).' known problems.');

        $facts = $walk->facts(['checks' => [...$detectors['checks'], ...$scan]], function (int $done, int $total) use ($progress, $journal): void {
            if ($done === 0) {
                $this->step($journal, 'Listed your files with git ls-files: '.number_format($total).' tracked, .gitignore respected.');
            }

            $progress->progressed($done, $total);
        });

        $this->step($journal, sprintf('Read %s of %s files in %ss. Read-only: nothing on your machine was changed.', number_format($facts['read']), number_format($facts['files']), round($facts['took'] / 1000, 1)));

        if ($this->option('json')) {
            $progress->finished();
            $this->line((string) json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $conventions = [...$facts, 'checks' => array_diff_key($facts['checks'], $scan)];
        $flags = ['commit' => $facts['commit'], 'files' => $facts['files'], 'took' => $facts['took'], 'checks' => array_intersect_key($facts['checks'], $scan)];

        $this->step($journal, 'Sending the counts to the studio: '.number_format(strlen((string) json_encode($conventions)) / 1024).' KB of counts, file paths and line numbers. No code.');
        $answer = $studio->submitConventionFacts($conventions);
        $flagged = $scan === [] ? null : $this->sendFlags($studio, $journal, $flags);
        $progress->finished();

        if ($answer === null) {
            return $this->outcome('The studio did not take the counts. Check php artisan studio:settings.', self::FAILURE);
        }

        $counted = (array) ($answer['counted'] ?? []);
        app(ToolStatus::class)->conventionsCounted((int) ($counted['followed'] ?? 0), (int) ($counted['undecided'] ?? 0), (int) $facts['read'], $flagged);

        return $this->outcome(sprintf(
            'Counted how %s files are written: %d conventions followed, %d to decide%s.',
            number_format($facts['read']),
            (int) ($counted['followed'] ?? 0),
            (int) ($counted['undecided'] ?? 0),
            $flagged === null ? '' : ', '.number_format($flagged).' files worth a closer look',
        ), self::SUCCESS);
    }

    /**
     * @param  array{commit: string, files: int, took: int, checks: array<string, array<string, array{files: int, hits: int, where: list<string>}>>}  $flags
     */
    private function sendFlags(Studio $studio, TaskJournal $journal, array $flags): ?int
    {
        $lines = collect($flags['checks'])->sum(fn (array $sides): int => (int) ($sides['flagged']['hits'] ?? 0));
        $this->step($journal, 'Sending the scan flags: '.number_format($lines).' lines that match a known problem, as file paths and line numbers. No code.');
        $answer = $studio->submitScanFlags($flags);

        return $answer === null ? null : (int) ($answer['flagged'] ?? 0);
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
