<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Blueprint;
use ArtisanStudio\StudioCli\LocalChanges;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Illuminate\Console\Command;

class BlueprintCommand extends Command
{
    public const string SIGNATURE = 'studio:blueprint';

    protected $signature = self::SIGNATURE.'
        {--json : Print what would be sent, and send nothing}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Send Artisan Studio the map of your models: names, tables, column types and relationships, never code or rows';

    public function handle(Blueprint $blueprint, Studio $studio, LocalChanges $changes, TaskJournal $journal): int
    {
        if (! $this->option('json') && ! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $this->step($journal, 'Looking for your models under app/.');
        $mapped = $blueprint->map();
        $this->step($journal, 'Read the structure of '.trans_choice(':count model|:count models', count($mapped['models'])).' with Laravel\'s model inspector: tables, columns, types and relationships. No rows.');
        $sha = $changes->currentSha();

        $payload = [
            'commit' => $sha === '' ? null : $sha,
            'models' => $mapped['models'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($mapped['skipped'] !== [] && ! $this->option('plain')) {
            $this->components->warn('Could not read '.implode(', ', array_map(class_basename(...), $mapped['skipped'])).', so they are left out.');
        }

        if ($mapped['models'] === []) {
            return $this->outcome('No models were found under app/, so there is nothing to send.', self::FAILURE);
        }

        $this->step($journal, 'Sending the map to the studio: '.number_format(strlen((string) json_encode($payload)) / 1024).' KB of names, tables, column types and relationships.');

        if ($studio->submitBlueprint($payload) === null) {
            return $this->outcome('The studio did not take the blueprint. Check php artisan studio:settings, then try again.', self::FAILURE);
        }

        app(ToolStatus::class)->blueprintMapped(count($mapped['models']), (int) collect($mapped['models'])->sum(fn (array $model): int => count($model['relationships'])));

        return $this->outcome('Sent the map of '.trans_choice(':count model|:count models', count($mapped['models'])).Says::named('. {name} labels them next.'), self::SUCCESS);
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
