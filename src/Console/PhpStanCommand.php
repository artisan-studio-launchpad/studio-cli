<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * PHPStan reads every file, so it runs on its own after the other tools, in
 * the background, the way the tests do. The findings and the health score
 * land first; PHPStan's join them when it is done.
 */
class PhpStanCommand extends Command
{
    public const string SIGNATURE = 'studio:phpstan';

    public const string PHPSTAN = 'phpstan';

    public const int TIMEOUT = 1200;

    public const string WORKING = 'PHPStan reads every file, so it runs last, in the background. Your other findings are already in.';

    protected $signature = self::SIGNATURE.'
        {--json : Print what would be sent, and send nothing}
        {--plain : Print one plain line with the outcome}
        {--apart : Check again for a fix run, leaving the Scan tab as the scan left it}';

    protected $description = 'Run PHPStan read-only, after the other checking tools and in the background, and send Artisan Studio what it found, as file, line, rule and message, never code';

    /**
     * @param  list<array{key: string, label: string, working: string, command: list<string>, timeout?: int}>  $next
     * @return array{key: string, label: string, working: string, command: list<string>, timeout: int, next: list<array{key: string, label: string, working: string, command: list<string>, timeout?: int}>}
     */
    public static function afterTheTools(array $next = []): array
    {
        return [...self::chained(), 'next' => $next];
    }

    /**
     * @return array{key: string, label: string, working: string, command: list<string>, timeout: int}
     */
    public static function chained(): array
    {
        return ['key' => self::PHPSTAN, 'label' => 'PHPStan', 'working' => self::WORKING, 'command' => [self::SIGNATURE], 'timeout' => self::TIMEOUT];
    }

    public function handle(Studio $studio, TaskJournal $journal, ToolStatus $status): int
    {
        if (! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $asked = $studio->scanTools();

        if ($asked === null) {
            return $this->outcome('The studio did not say whether to run PHPStan. Check php artisan studio:settings.', self::FAILURE);
        }

        if (! in_array(self::PHPSTAN, $asked, true)) {
            return $this->outcome('PHPStan is not switched on for this scan.', self::SUCCESS);
        }

        $apart = (bool) $this->option('apart');

        $this->step($journal, self::WORKING);

        if (! $apart) {
            $status->behind(self::PHPSTAN, ToolStatus::RUNNING);
        }

        $studio->reportToolProgress(self::PHPSTAN, 'running');

        $result = (new Toolbox)->run([self::PHPSTAN])[self::PHPSTAN];

        if ($apart) {
            app(FixProgress::class)->left(self::PHPSTAN, $result['ran'] ? count($result['findings'] ?? []) : null);
        } else {
            $status->caughtUp(self::PHPSTAN, $result);
        }

        $studio->reportToolProgress(self::PHPSTAN, $result['ran'] ? 'done' : 'skipped', $result['ran'] ? null : ($result['reason'] ?? null));
        $payload = ['commit' => $this->commit(), 'namespaces' => (object) (new Toolbox)->namespaces(), 'tools' => [self::PHPSTAN => $result]];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $taken = $studio->submitScanToolResults($payload);

        if ($taken === null) {
            return $this->outcome('The studio did not take what PHPStan found. Check php artisan studio:settings.', self::FAILURE);
        }

        if (! $apart) {
            $status->excluded(is_array($taken['excluded'] ?? null) ? array_values($taken['excluded']) : []);
        }

        return $this->outcome($this->summary($result), self::SUCCESS);
    }

    /**
     * @param  array{ran: bool, reason?: string, took?: int, findings?: list<mixed>}  $result
     */
    private function summary(array $result): string
    {
        $found = count($result['findings'] ?? []);

        return $result['ran']
            ? sprintf('PHPStan landed in %ss: %s. Your health score has been updated. Read-only: nothing was changed.', round(($result['took'] ?? 0) / 1000), $found === 0 ? 'all clear' : number_format($found).' '.($found === 1 ? 'finding' : 'findings'))
            : 'PHPStan was not checked: '.($result['reason'] ?? 'it did not run.');
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
