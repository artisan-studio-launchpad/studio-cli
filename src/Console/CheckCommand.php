<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\Secrets;
use Illuminate\Console\Command;
use Throwable;

/**
 * Looks for keys and tokens committed to the repository, read inside the
 * project and printed as the same JSON every scan tool's findings use: file,
 * line, rule and message.
 *
 * Nothing sensitive is ever printed: the kind of secret and where, never the
 * secret.
 */
class CheckCommand extends Command
{
    public const string SIGNATURE = 'studio:check';

    protected $signature = self::SIGNATURE.'
        {check : secrets}';

    protected $description = 'Look for keys and tokens committed to your repository, read-only, and print what was found as JSON';

    public function handle(): int
    {
        if ($this->argument('check') !== 'secrets') {
            $this->line((string) json_encode(['error' => 'Unknown check.']));

            return self::FAILURE;
        }

        try {
            $findings = (new Secrets(base_path()))->findings();
        } catch (Throwable $exception) {
            $this->line((string) json_encode(['error' => class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 200)]));

            return self::FAILURE;
        }

        $this->line((string) json_encode(['findings' => $findings], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
