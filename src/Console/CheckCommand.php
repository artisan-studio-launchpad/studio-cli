<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Scan\AiAccess;
use ArtisanStudio\StudioCli\Scan\Secrets;
use ArtisanStudio\StudioCli\Scan\StudioChecks;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * The studio's own security checks, run inside the project so they read its
 * real files, printed as the same JSON every scan tool's findings use: file,
 * line, rule and message.
 *
 * Nothing sensitive is ever printed: the kind of secret and where, never the
 * secret.
 */
class CheckCommand extends Command
{
    public const string SIGNATURE = 'studio:check';

    private const string LIVEWIRE = 'livewire';

    protected $signature = self::SIGNATURE.'
        {check : secrets}';

    protected $description = 'Run Artisan Studio\'s own security checks read-only and print what they found as JSON';

    public function handle(): int
    {
        if ($this->argument('check') !== 'secrets') {
            $this->line((string) json_encode(['error' => 'Unknown check.']));

            return self::FAILURE;
        }

        try {
            $report = $this->secrets();
        } catch (Throwable $exception) {
            $this->line((string) json_encode(['error' => class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 200)]));

            return self::FAILURE;
        }

        $this->line((string) json_encode(['findings' => $report['findings'], ...($report['unread'] === [] ? [] : ['unread' => $report['unread']])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * The secrets and AI access checks, read here, and the Livewire checks,
     * run in the studio on the files it asks for, with the files and rules
     * they could not read this time.
     *
     * @return array{findings: list<array{where: string, rule: string, message: string}>, unread: list<array{where: string, rule: string}>}
     */
    private function secrets(): array
    {
        $livewire = (new StudioChecks(base_path(), app(Studio::class)))->run(self::LIVEWIRE)
            ?? throw new RuntimeException('Artisan Studio could not be reached for the Livewire checks.');

        return [
            'findings' => [...(new Secrets(base_path()))->findings(), ...(new AiAccess(base_path()))->findings(), ...$livewire['findings']],
            'unread' => $livewire['unread'],
        ];
    }
}
