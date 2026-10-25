<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\History\FeatureCommits;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Illuminate\Console\Command;

class HistoryCommand extends Command
{
    public const string SIGNATURE = 'studio:history';

    protected $signature = self::SIGNATURE.'
        {--json : Print what would be sent, and send nothing}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Send Artisan Studio what your project has built: the feat commits of your git history, as hash, date, message and description, never code';

    public function handle(FeatureCommits $commits, Studio $studio, TaskJournal $journal): int
    {
        if (! $this->option('json') && ! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $this->step($journal, 'Reading the feat commits of your git history.');
        $history = $commits->read();

        if ($this->option('json')) {
            $this->line((string) json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->step($journal, $history['conventional']
            ? 'Found '.trans_choice(':count feat commit|:count feat commits', count($history['commits'])).' among '.number_format($history['total']).' commits. Sending their messages to the studio, never code.'
            : 'Your commits do not use conventional messages, so nothing was read from them.');

        if ($studio->submitRepoHistory($history) === null) {
            return $this->outcome('The studio did not take the history. Check php artisan studio:settings, then try again.', self::FAILURE);
        }

        return $this->outcome($history['conventional']
            ? 'Sent '.trans_choice(':count feat commit|:count feat commits', count($history['commits'])).Says::named('. {name} sorts them into areas next.')
            : 'Your commits do not use conventional messages, so there is nothing to read. I told the studio.', self::SUCCESS);
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
