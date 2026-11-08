<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Closure;
use Symfony\Component\Process\Process;

final class BackgroundTasks
{
    /**
     * Set for a task started detached, so it can leave the studio's session.
     */
    public const string DETACHED = 'STUDIO_DETACHED';

    private const int TIMEOUT = 600;

    /** @var array<string, array{process: ?Process, pid: ?int, label: string, journal: string, offset: int, steps: int, then: array{key: string, label: string, working: string, command: list<string>, timeout?: int, next?: list<array{key: string, label: string, working: string, command: list<string>, timeout?: int}>}|null}> */
    private array $running = [];

    /**
     * @var array<string, true>
     */
    private array $overtime = [];

    public function __construct(
        private readonly string $root,
        private readonly ActivityLog $log,
        private readonly ?Closure $whenFinished = null,
    ) {
        $this->running = $this->remembered();
    }

    /**
     * A detached task outlives the studio: quitting, restarting or a crash of
     * `php artisan studio` leaves it running, and the next studio follows it.
     *
     * @param  list<string>  $command
     * @param  array{key: string, label: string, working: string, command: list<string>, timeout?: int, next?: list<array{key: string, label: string, working: string, command: list<string>, timeout?: int}>}|null  $then
     */
    public function start(string $key, string $label, string $working, array $command, ?array $then = null, int $timeout = self::TIMEOUT, bool $detached = false): void
    {
        if ($this->isRunning($key)) {
            return;
        }

        $journal = sys_get_temp_dir().'/studio-task-'.$key.'-'.bin2hex(random_bytes(4)).'.log';
        $arguments = [PHP_BINARY, 'artisan', ...$command, '--plain', '--no-ansi', '--no-interaction'];

        if ($detached) {
            $launch = new Process(['sh', '-c', 'nohup "$@" > /dev/null 2>&1 & echo $!', 'sh', ...$arguments], $this->root, [TaskJournal::ENV => $journal, self::DETACHED => '1']);
            $launch->run();
            $pid = (int) trim($launch->getOutput());
            $this->running[$key] = ['process' => null, 'pid' => $pid > 0 ? $pid : null, 'label' => $label, 'journal' => $journal, 'offset' => 0, 'steps' => 0, 'then' => null];
            $this->remember();
        } else {
            $process = new Process($arguments, $this->root, [TaskJournal::ENV => $journal], timeout: $timeout);
            $process->start();
            $this->running[$key] = ['process' => $process, 'pid' => null, 'label' => $label, 'journal' => $journal, 'offset' => 0, 'steps' => 0, 'then' => $then];
        }

        $this->log->add(['agent' => app(Says::class)->name(), 'label' => $label, 'detail' => $working, 'colour' => 'cyan', 'kind' => $key], $key);
    }

    /**
     * Stops the tasks and everything they started, without their follow-up:
     * for a scan that was reset, whose results nobody wants any more.
     *
     * @param  list<string>  $keys
     */
    public function stop(array $keys): void
    {
        array_map(function (string $key): void {
            $task = $this->running[$key] ?? null;

            if ($task === null) {
                return;
            }

            $pid = $task['process']?->getPid() ?? $task['pid'];

            if ($pid !== null) {
                ProcessTree::stop($pid);
            }

            unset($this->running[$key]);
            @unlink($task['journal']);
            $this->remember();
            $this->log->add(['agent' => app(Says::class)->name(), 'label' => $task['label'], 'detail' => 'Stopped, because the scan was reset.', 'colour' => 'amber', 'kind' => $key], $key);
        }, $keys);
    }

    /**
     * Stops every task this studio itself started, and what they started. A
     * detached task is left, as it is meant to outlive the studio.
     */
    public function stopAttached(): void
    {
        $this->stop(array_keys(array_filter($this->running, fn (array $task): bool => $task['process'] !== null)));
    }

    public function isRunning(string $key): bool
    {
        return isset($this->running[$key]) && $this->alive($key, $this->running[$key]);
    }

    public function tick(): void
    {
        array_map($this->follow(...), array_keys($this->running));

        $finished = array_filter($this->running, fn (array $task, string $key): bool => ! $this->alive($key, $task), ARRAY_FILTER_USE_BOTH);

        array_map($this->finish(...), array_keys($finished), $finished);
    }

    public static function isAlive(?int $pid): bool
    {
        return $pid !== null && $pid > 0 && (! function_exists('posix_kill') || posix_kill($pid, 0));
    }

    /**
     * Whether the task is still going. A process started to run alongside the
     * studio is only stopped for taking too long when someone asks, so this
     * asks: past its timeout it is stopped with everything it started, the
     * workers of a test run included, and no longer counted as running.
     *
     * @param  array{process: ?Process, pid: ?int}  $task
     */
    private function alive(string $key, array $task): bool
    {
        $process = $task['process'];

        if ($process === null) {
            return self::isAlive($task['pid']);
        }

        if (! $process->isRunning()) {
            return false;
        }

        $timeout = $process->getTimeout();

        if ($timeout === null || microtime(true) - $process->getStartTime() <= $timeout) {
            return true;
        }

        $this->overtime[$key] = true;

        if ($process->getPid() !== null) {
            ProcessTree::stop($process->getPid());
        }

        $process->stop(0);

        return false;
    }

    private function follow(string $key): void
    {
        $task = $this->running[$key];
        $read = TaskJournal::readFrom($task['journal'], $task['offset']);

        array_map(fn (string $line, int $index) => $this->log->add([
            'agent' => app(Says::class)->name(),
            'label' => $task['label'],
            'detail' => $line,
            'colour' => 'sky',
            'kind' => $key,
        ], $key.':'.($task['steps'] + $index)), $read['lines'], array_keys($read['lines']));

        $this->running[$key] = [...$task, 'offset' => $read['offset'], 'steps' => $task['steps'] + count($read['lines'])];

        if ($task['process'] === null && $read['lines'] !== []) {
            $this->remember();
        }
    }

    /**
     * @param  array{process: ?Process, pid: ?int, label: string, journal: string, offset: int, steps: int, then: array{key: string, label: string, working: string, command: list<string>, timeout?: int, next?: list<array{key: string, label: string, working: string, command: list<string>, timeout?: int}>}|null}  $task
     */
    private function finish(string $key, array $task): void
    {
        $this->follow($key);
        $lastLine = $this->lastLine($task['journal']);
        unset($this->running[$key]);
        @unlink($task['journal']);

        if ($task['process'] === null) {
            $this->remember();
        }

        $succeeded = $task['process'] === null || $task['process']->isSuccessful();
        $lines = $task['process'] === null ? [] : array_values(array_filter(array_map(trim(...), explode("\n", $task['process']->getOutput()))));

        $this->log->add([
            'agent' => app(Says::class)->name(),
            'label' => $task['label'],
            'detail' => match (true) {
                isset($this->overtime[$key]) => 'Took longer than '.intdiv((int) $task['process']?->getTimeout(), 60).' minutes, so it was stopped.',
                $lines !== [] => $lines[count($lines) - 1],
                $lastLine !== null => $lastLine,
                default => $succeeded ? 'Done.' : 'Stopped before it finished.',
            },
            'colour' => $succeeded ? 'green' : 'amber',
            'kind' => $key,
        ], $key);

        unset($this->overtime[$key]);

        if ($this->whenFinished !== null) {
            ($this->whenFinished)($key);
        }

        if ($task['then'] !== null) {
            $next = $task['then']['next'] ?? [];

            $this->start(
                $task['then']['key'],
                $task['then']['label'],
                $task['then']['working'],
                $task['then']['command'],
                then: $next === [] ? null : [...$next[0], 'next' => array_slice($next, 1)],
                timeout: $task['then']['timeout'] ?? self::TIMEOUT,
            );
        }
    }

    private function lastLine(string $journal): ?string
    {
        $lines = is_file($journal) ? array_values(array_filter(array_map(trim(...), explode("\n", (string) file_get_contents($journal))))) : [];

        return $lines === [] ? null : $lines[count($lines) - 1];
    }

    /**
     * The detached tasks, written down so a studio started later follows them.
     */
    private function remember(): void
    {
        $detached = array_map(
            fn (array $task): array => ['pid' => $task['pid'], 'label' => $task['label'], 'journal' => $task['journal'], 'offset' => $task['offset'], 'steps' => $task['steps']],
            array_filter($this->running, fn (array $task): bool => $task['process'] === null),
        );

        $detached === [] ? @unlink($this->path()) : @file_put_contents($this->path(), (string) json_encode($detached), LOCK_EX);
    }

    /**
     * @return array<string, array{process: null, pid: int, label: string, journal: string, offset: int, steps: int, then: null}>
     */
    private function remembered(): array
    {
        $json = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;

        return collect(is_array($json) ? $json : [])
            ->filter(fn (mixed $task): bool => is_array($task) && is_int($task['pid'] ?? null) && is_string($task['journal'] ?? null) && self::isAlive($task['pid']))
            ->mapWithKeys(fn (array $task, int|string $key): array => [(string) $key => [
                'process' => null,
                'pid' => (int) $task['pid'],
                'label' => (string) ($task['label'] ?? $key),
                'journal' => (string) $task['journal'],
                'offset' => (int) ($task['offset'] ?? 0),
                'steps' => (int) ($task['steps'] ?? 0),
                'then' => null,
            ]])
            ->all();
    }

    private function path(): string
    {
        return StateFile::for('detached', $this->root);
    }
}
