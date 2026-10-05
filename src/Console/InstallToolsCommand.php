<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;

class InstallToolsCommand extends Command
{
    public const string SIGNATURE = 'studio:install-tools';

    public const int TIMEOUT = 900;

    protected $signature = self::SIGNATURE.'
        {--tool=* : Only these tools, by key}
        {--yes : Install without asking again, because you already said yes on the Dashboard}
        {--list : Only say what is missing, and install nothing}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Offer to install the checking tools you switched on for the scan that this project does not have yet, asking before each one';

    public function handle(Studio $studio, TaskJournal $journal): int
    {
        if (! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $only = array_values(array_filter((array) $this->option('tool'), is_string(...)));
        $missing = collect((new Toolbox)->missing($studio->scanTools() ?? []))
            ->filter(fn (array $tool, string $key): bool => $only === [] || in_array($key, $only, true))
            ->all();

        if ($missing === []) {
            return $this->outcome('Every tool you switched on is already set up in this project.', self::SUCCESS);
        }

        if ($this->option('list') || (! $this->option('yes') && ! $this->input->isInteractive())) {
            collect($missing)->each(fn (array $tool) => $this->components->twoColumnDetail($tool['name'], $tool['about']));

            return self::SUCCESS;
        }

        $installed = collect($missing)
            ->filter(fn (array $tool, string $key): bool => $this->option('yes') ? $this->installReported($studio, $key, $tool, $journal) : $this->offer($tool, $journal))
            ->map(fn (array $tool): string => $tool['name'])
            ->values()
            ->all();

        return $this->outcome($installed === []
            ? 'Nothing was installed.'
            : 'Installed '.implode(', ', $installed).'. It runs from your next scan.', self::SUCCESS);
    }

    /**
     * @param  array{name: string, packages: list<string>, files: array<string, string>, about: string}  $tool
     */
    private function installReported(Studio $studio, string $key, array $tool, TaskJournal $journal): bool
    {
        $studio->reportToolProgress($key, 'installing');
        $installed = $this->install($tool, $journal);

        if (! $installed) {
            $studio->reportToolProgress($key, 'skipped', 'It could not be installed. See Activity in php artisan studio.');
        }

        return $installed;
    }

    /**
     * @param  array{name: string, packages: list<string>, files: array<string, string>, about: string}  $tool
     */
    private function offer(array $tool, TaskJournal $journal): bool
    {
        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>'.$tool['name'].'</>', 'not set up yet');
        $this->line('  '.$tool['about']);
        $this->line('  <fg=gray>Runs: composer require --dev '.implode(' ', $tool['packages']).($tool['files'] === [] ? '' : ', and adds '.implode(', ', array_keys($tool['files'])).' where it is missing').'</>');

        return confirm('Install '.$tool['name'].' now?', default: false) && $this->install($tool, $journal);
    }

    /**
     * @param  array{name: string, packages: list<string>, files: array<string, string>, about: string}  $tool
     */
    private function install(array $tool, TaskJournal $journal): bool
    {
        $journal->note('Installing '.$tool['name'].': composer require --dev '.implode(' ', $tool['packages']).'.');

        $composer = new Process(['composer', 'require', '--dev', '--no-interaction', ...$tool['packages']], base_path(), timeout: self::TIMEOUT);
        $composer->run(fn (string $type, string $output) => $this->option('plain') ? null : $this->output->write($output));

        if (! $composer->isSuccessful()) {
            $journal->note('Composer could not install '.$tool['name'].'. Nothing else was changed.');

            if (! $this->option('plain')) {
                $this->components->error('Composer could not install '.$tool['name'].'. Nothing else was changed.');
            }

            return false;
        }

        $added = collect($tool['files'])
            ->reject(fn (string $contents, string $file): bool => is_file(base_path($file)))
            ->each(fn (string $contents, string $file) => file_put_contents(base_path($file), $contents))
            ->keys()
            ->all();

        $journal->note($added === [] ? $tool['name'].' is installed.' : $tool['name'].' is installed, with a starting '.implode(' and ', $added).'.');

        return true;
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
