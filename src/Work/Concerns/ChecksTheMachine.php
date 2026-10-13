<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work\Concerns;

use ArtisanStudio\StudioCli\Console\RenderCommand;
use ArtisanStudio\StudioCli\Says;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Whether this machine is ready to be worked on, and whether the app still
 * boots once it has been.
 *
 * Needs from the class: $root, $git.
 */
trait ChecksTheMachine
{
    /**
     * @param  list<string>  $theirs
     */
    private function refusal(array $theirs = []): ?string
    {
        return match (true) {
            ! $this->git->isRepository() => 'This project is not a git repository, so there is no safe branch to work on.',
            ! $this->git->isClean() && ($theirs === [] || $this->git->changed([], $theirs) !== [] || $this->git->staged() !== []) => Says::named('You have uncommitted changes. Commit or stash them first, so {name}\'s changes stay apart from yours.'),
            default => null,
        };
    }

    private function boots(): ?string
    {
        if (! is_file($this->root.'/artisan')) {
            return null;
        }

        $process = new Process([PHP_BINARY, 'artisan', 'about', '--json'], $this->root, timeout: 120);

        try {
            $process->run();
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        return $process->isSuccessful() ? null : (string) (collect(explode("\n", trim($process->getErrorOutput()."\n".$process->getOutput())))->map(trim(...))->filter()->first() ?? 'it stopped without saying why.');
    }

    /**
     * Opens a page of the app as its latest user, in a fresh process so new
     * routes and classes are read from disk.
     *
     * @return array{error: ?string, redirect: ?string, missing: bool, path: string}
     */
    private function renders(string $path): array
    {
        $none = ['error' => null, 'redirect' => null, 'missing' => false, 'path' => $path];

        if (! is_file($this->root.'/artisan')) {
            return $none;
        }

        $process = new Process([PHP_BINARY, 'artisan', RenderCommand::SIGNATURE, $path], $this->root, timeout: 120);

        try {
            $process->run();
        } catch (Throwable $exception) {
            return [...$none, 'error' => $exception->getMessage()];
        }

        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $process->getOutput())), fn (string $line): bool => str_starts_with($line, '{')));
        $result = json_decode((string) end($lines), true);

        if (! is_array($result)) {
            return [...$none, 'error' => $process->isSuccessful() ? null : (string) (collect(explode("\n", trim($process->getErrorOutput())))->map(fn (string $line): string => trim($line))->filter()->first() ?? 'it stopped without saying why.')];
        }

        return [
            'error' => is_string($result['error'] ?? null) ? $result['error'].(is_string($result['where'] ?? null) ? ' ('.$result['where'].')' : '') : null,
            'redirect' => is_string($result['redirect'] ?? null) ? $result['redirect'] : null,
            'missing' => (int) ($result['status'] ?? 0) === 404,
            'path' => is_string($result['path'] ?? null) ? $result['path'] : $path,
        ];
    }
}
