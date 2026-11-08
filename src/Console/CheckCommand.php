<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\Secrets;
use ArtisanStudio\StudioCli\Scan\StudioChecks;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs one check and prints what it found as the same JSON every scan tool's
 * findings use: file, line, rule and message.
 *
 * `secrets` looks for keys and tokens committed to the repository, read inside
 * the project; nothing sensitive is ever printed, only the kind of secret and
 * where. Any other check is one Artisan Studio describes: it asks for the
 * files it reads, through the one filter, and sends back where it found
 * something. `--only` names the files it should check.
 */
class CheckCommand extends Command
{
    public const string SIGNATURE = 'studio:check';

    protected $signature = self::SIGNATURE.'
        {check? : secrets, or a check Artisan Studio describes}
        {--only=* : Check only these files, by path from the project root}
        {--list : Print the checks Artisan Studio describes}';

    protected $description = 'Run a check on your project, read-only, and print what was found as JSON';

    public function handle(Studio $studio): int
    {
        if ($this->option('list')) {
            return $this->print(['checks' => $studio->scanChecks() ?? []]);
        }

        $check = (string) $this->argument('check');

        try {
            $report = match (true) {
                $check === 'secrets' => ['findings' => (new Secrets(base_path()))->findings()],
                in_array($check, array_column(StudioChecks::described(base_path()), 'key'), true) => $this->studioCheck($studio, $check),
                default => ['error' => 'Unknown check.'],
            };
        } catch (Throwable $exception) {
            $report = ['error' => class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 200)];
        }

        return $this->print($report ?? ['error' => 'The check could not be run.']);
    }

    /**
     * @return array{findings: list<array{where: string, rule: string, message: string, ruleset: string}>, unread: list<array{where: string, rule: string}>}|null
     */
    private function studioCheck(Studio $studio, string $check): ?array
    {
        $only = array_values(array_filter((array) $this->option('only'), is_string(...)));

        return (new StudioChecks(base_path(), $studio))->run($check, $only === [] ? null : $only);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function print(array $report): int
    {
        $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return isset($report['error']) ? self::FAILURE : self::SUCCESS;
    }
}
