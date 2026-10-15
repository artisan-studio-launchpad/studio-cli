<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use ArtisanStudio\StudioCli\TestSuite;
use ArtisanStudio\StudioCli\Work\BuildRun;
use ArtisanStudio\StudioCli\Work\CoveringTests;
use ArtisanStudio\StudioCli\Work\StepChecks;
use ArtisanStudio\StudioCli\Work\WorkProgress;
use ArtisanStudio\StudioCli\Work\WorkRun;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

/**
 * Works through what the developer asked for in Insights: a big job one
 * kind of problem at a time on a new local branch, the changes they agreed in
 * the app, or the places they marked as done checked again.
 */
class WorkCommand extends Command
{
    public const string SIGNATURE = 'studio:work';

    protected $signature = self::SIGNATURE.'
        {--session= : Work through this ask}
        {--rechecks : Only check the places marked as done, and change nothing}
        {--send : Only send the studio the fix branches the developer asked a pull request for}
        {--yes : Work without asking again, because you already said yes in the studio}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Work through an Insights job on a new local branch, one kind of problem at a time, checked by the tool that found it and committed when you say it works';

    public function handle(Studio $studio, TaskJournal $journal, WorkProgress $progress, ToolStatus $status): int
    {
        if (getenv(BackgroundTasks::DETACHED) === '1' && function_exists('posix_setsid')) {
            @posix_setsid();
        }

        if (! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $work = $studio->scanWork();

        if ($work === null) {
            return $this->outcome('The studio did not say what to work on. Check php artisan studio:settings.', self::FAILURE);
        }

        $toolbox = new Toolbox;
        $testsOn = ($status->tests()['state'] ?? null) === ToolStatus::RAN && (new TestSuite(base_path()))->problem() === null;
        $run = new WorkRun(base_path(), $studio, $toolbox, new LocalBranch(base_path()), $progress, $journal, tests: $testsOn ? new CoveringTests(base_path(), $toolbox) : null);

        if ($this->option('rechecks')) {
            return $this->outcome($run->recheck($work['rechecks']), self::SUCCESS);
        }

        if ($this->option('send')) {
            return $this->outcome($run->sendBranches($work['sends']), self::SUCCESS);
        }

        $ask = collect($work['sessions'])->first(fn (array $session): bool => $this->option('session') === null || (int) $session['id'] === (int) $this->option('session'));

        if ($ask === null) {
            return $this->outcome('Nothing was asked of me.', self::SUCCESS);
        }

        if (! $this->option('yes') && (! $this->input->isInteractive() || ! confirm('Work through this on a new branch? Nothing is committed until you say it works, and nothing is pushed.', default: true))) {
            return $this->outcome('Nothing was changed.', self::SUCCESS);
        }

        $built = ($ask['job'] ?? null) === BuildRun::JOB
            ? new BuildRun(base_path(), $studio, new LocalBranch(base_path()), $progress, $journal, new StepChecks(base_path(), $toolbox), tests: $testsOn ? new CoveringTests(base_path(), $toolbox) : null)
            : null;

        return $this->outcome($built?->run($ask) ?? $run->run($ask), self::SUCCESS);
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
