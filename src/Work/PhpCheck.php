<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Process\Process;
use Throwable;

final class PhpCheck
{
    /**
     * @return array{content: string, error: ?string}
     */
    public static function of(string $path, string $content): array
    {
        if (str_ends_with($path, '.blade.php')) {
            return ['content' => $content, 'error' => self::bladeError($content)];
        }

        if (! str_ends_with($path, '.php')) {
            return ['content' => $content, 'error' => null];
        }

        $tidy = self::withoutRepeatedImports($content);

        return ['content' => $tidy, 'error' => self::compileError($tidy)];
    }

    private static function withoutRepeatedImports(string $content): string
    {
        $seen = [];

        return implode("\n", array_filter(explode("\n", $content), function (string $line) use (&$seen): bool {
            if (str_starts_with($line, 'namespace ')) {
                $seen = [];

                return true;
            }

            if (preg_match('/^use [^;]+;\s*$/', $line) !== 1) {
                return true;
            }

            $repeated = isset($seen[trim($line)]);
            $seen[trim($line)] = true;

            return ! $repeated;
        }));
    }

    private static function bladeError(string $content): ?string
    {
        try {
            $compiled = Blade::compileString($content);
        } catch (Throwable $exception) {
            return 'Blade could not compile it: '.$exception->getMessage();
        }

        $error = self::compileError($compiled);

        return $error === null ? null : 'the view does not compile: '.$error;
    }

    private static function compileError(string $content): ?string
    {
        $lint = new Process([PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-l'], input: $content, timeout: 30);
        $lint->run();

        if ($lint->isSuccessful()) {
            return null;
        }

        return preg_match('/(?:Parse|Fatal) error:\s*(.+?) in Standard input code on line (\d+)/', $lint->getOutput().$lint->getErrorOutput(), $match) === 1
            ? $match[1].' on line '.$match[2]
            : 'PHP could not compile it';
    }
}
