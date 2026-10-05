<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ToolsCommand extends Command
{
    public const string SIGNATURE = 'studio:tools';

    /**
     * @var list<string>
     */
    private const array HEAVY = ['phpstan', 'pest-type-coverage', 'pest-arch'];

    protected $signature = self::SIGNATURE.'
        {--json : Print what would be sent, and send nothing}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Run your project\'s own checking tools read-only and send Artisan Studio what they found, as file, line, rule and message, never code';

    public function handle(Studio $studio, TaskJournal $journal, ToolStatus $status): int
    {
        if (! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $asked = $studio->scanTools();

        if ($asked === null) {
            return $this->outcome('The studio did not say which tools to run. Check php artisan studio:settings.', self::FAILURE);
        }

        $status->asked($asked);

        $toolbox = new Toolbox;
        $wanted = array_values(array_diff($asked, [TestsCommand::TESTS, TestsCommand::COVERAGE]));
        $checks = $wanted;
        $this->step($journal, 'Asked the studio which tools you switched on: '.($wanted === [] ? 'none' : implode(', ', $wanted)).'. They run at the same time.');

        $heavy = array_values(array_intersect($checks, [...self::HEAVY, ...$toolbox->heavy()]));
        $status->running($checks);
        array_map(fn (string $key): mixed => $studio->reportToolProgress($key, 'waiting'), $checks);

        $commit = $this->commit();
        $results = $this->ranTogether($toolbox, array_values(array_diff($checks, $heavy)), $journal, $studio, $status, $commit);
        $waited = false;

        while (self::waitsForTheTests($heavy) && $status->testsAreRunning()) {
            $waited || $this->step($journal, 'Your tests are running, so the heavy checks wait for them. The rest are done.');
            $waited = true;
            sleep(2);
        }

        $results = [...$results, ...$this->ranTogether($toolbox, $heavy, $journal, $studio, $status, $commit)];
        $payload = ['commit' => $commit, 'packages' => $toolbox->packages(), 'namespaces' => (object) $toolbox->namespaces(), 'tools' => $results];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->step($journal, 'Sending what the tools found: file, line, rule and a one-line message for each, and your packages\' names and versions. No code.');
        $answer = $studio->submitScanToolResults($payload);

        if ($answer === null) {
            return $this->outcome('The studio did not take the results. Check php artisan studio:settings.', self::FAILURE);
        }

        is_array($answer['grouped'] ?? null) && $status->grouped($answer['grouped']);

        if (array_key_exists(PhpStanCommand::PHPSTAN, $results)) {
            $status->excluded(is_array($answer['excluded'] ?? null) ? array_values($answer['excluded']) : []);
        }

        $ran = count(array_filter($results, fn (array $result): bool => $result['ran']));
        $missing = array_column($toolbox->missing($wanted), 'name');

        return $this->outcome(sprintf('Ran %d of %d tools: %d findings sent.', $ran, count($results), (int) ($answer['findings'] ?? 0)).($missing === []
            ? ''
            : ' Not set up yet: '.implode(', ', $missing).'. Press ⏎ on the Dashboard to install them, or run php artisan '.InstallToolsCommand::SIGNATURE.'.'), self::SUCCESS);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array<string, mixed>>
     */
    private function ranTogether(Toolbox $toolbox, array $keys, TaskJournal $journal, Studio $studio, ToolStatus $status, ?string $commit): array
    {
        return $keys === [] ? [] : $toolbox->runTogether($keys, function (string $key, string $name, array $result) use ($journal, $studio, $status, $commit): void {
            $status->ran($key, $result);

            if (! $this->option('json')) {
                $sent = $studio->submitScanToolResults(['commit' => $commit, 'partial' => true, 'tools' => [$key => $result]]);
                is_array($sent['grouped'] ?? null) && $status->grouped($sent['grouped']);
            }

            $studio->reportToolProgress($key, $result['ran'] ? 'done' : 'skipped', $result['ran'] ? null : ($result['reason'] ?? null));
            $this->step($journal, $result['ran']
                ? sprintf('%s found %s in %ss. Read-only: nothing was changed.', $name, count($result['findings'] ?? []) === 1 ? '1 thing' : number_format(count($result['findings'] ?? [])).' things', round(($result['took'] ?? 0) / 1000, 1))
                : $name.' was not checked: '.($result['reason'] ?? 'it did not run.'));
        }, function (string $key) use ($studio, $status): void {
            $status->started($key);
            $studio->reportToolProgress($key, 'running');
        });
    }

    /**
     * @param  list<string>  $checks
     */
    public static function waitsForTheTests(array $checks): bool
    {
        return array_intersect($checks, [...self::HEAVY, ...(new Toolbox)->heavy()]) !== [];
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
