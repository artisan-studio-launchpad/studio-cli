<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use Symfony\Component\Process\Process;
use Throwable;

abstract class Tool
{
    public const int LONGEST_MESSAGE = 380;

    abstract public function key(): string;

    abstract public function name(): string;

    /**
     * @return list<string>|null
     */
    abstract public function command(string $root): ?array;

    /**
     * @return list<array{where: string, rule: string, message: string, packages?: list<string>, major?: bool}>|null
     */
    abstract public function findings(string $output, string $root): ?array;

    /**
     * The tool's own fixing run, when it has one.
     *
     * @return list<string>|null
     */
    public function fixCommand(string $root): ?array
    {
        return null;
    }

    public function canFix(string $root): bool
    {
        return $this->fixCommand($root) !== null;
    }

    /**
     * How the tool is told to work on only some files.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function pathArguments(array $paths): array
    {
        return $paths;
    }

    /**
     * The check's command, told to look at only these files.
     *
     * @param  list<string>  $command
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function scopedTo(array $command, array $paths): array
    {
        return [...$command, ...$this->pathArguments($paths)];
    }

    /**
     * Whether a fixing run that exits with an error still changed what it could,
     * as a checker does when some of what it found is not fixable.
     */
    public function fixesEvenWhenFailing(): bool
    {
        return false;
    }

    public function timeout(): int
    {
        return 600;
    }

    public function tooSlow(): string
    {
        return 'Took longer than '.intdiv($this->timeout(), 60).' minutes, so it was stopped.';
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [];
    }

    public function missing(): string
    {
        return 'Not installed in this project.';
    }

    /**
     * @return array<string, int>|null
     */
    public function summary(): ?array
    {
        return null;
    }

    /**
     * The files and rules the run could not check, so what was found there
     * before still stands rather than reading as fixed.
     *
     * @return list<array{where: string, rule: string}>
     */
    public function unread(string $output): array
    {
        return [];
    }

    /**
     * @return array{packages: list<string>, files: array<string, string>, about: string}|null
     */
    public function install(): ?array
    {
        return null;
    }

    public function isSetUp(string $root): bool
    {
        return $this->command($root) !== null;
    }

    /**
     * @return array<mixed>|null
     */
    protected function json(string $output): ?array
    {
        $start = strpos($output, '{');
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function relative(string $path, string $root): string
    {
        $root = rtrim($root, '/').'/';

        return ltrim(str_starts_with($path, $root) ? substr($path, strlen($root)) : $path, '/');
    }

    /**
     * @return array{where: string, rule: string, message: string}
     */
    protected function finding(string $path, ?int $line, string $rule, string $message): array
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return [
            'where' => $path.($line !== null && $line > 0 ? ':'.$line : ''),
            'rule' => mb_substr(trim($rule) === '' ? $this->key() : trim($rule), 0, 160),
            'message' => mb_strlen($message) > self::LONGEST_MESSAGE ? mb_substr($message, 0, self::LONGEST_MESSAGE - 1).'…' : $message,
        ];
    }

    protected function bin(string $root, string $name): ?string
    {
        return is_file($root.'/vendor/bin/'.$name) ? 'vendor/bin/'.$name : null;
    }

    /**
     * @param  list<string>  $help
     */
    protected function offers(string $root, array $help, string $option): bool
    {
        $process = new Process($help, $root, ['PAO_DISABLE' => '1'], timeout: 30);

        try {
            $process->run();
        } catch (Throwable) {
            return false;
        }

        return $process->isSuccessful() && str_contains($process->getOutput(), $option);
    }
}
