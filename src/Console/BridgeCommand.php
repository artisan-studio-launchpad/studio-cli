<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Bridge\BridgeLog;
use ArtisanStudio\StudioCli\Bridge\BridgeWorker;
use ArtisanStudio\StudioCli\Bridge\BridgeWorkers;
use ArtisanStudio\StudioCli\Bridge\ClaudeRunner;
use ArtisanStudio\StudioCli\Bridge\CodexRunner;
use ArtisanStudio\StudioCli\Bridge\Runner;
use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class BridgeCommand extends Command
{
    public const string SIGNATURE = 'studio:bridge';

    protected $signature = self::SIGNATURE.'
        {--runner=auto : claude, codex, or auto to use the first one signed in}
        {--plain : Accepted so the studio can start it like its other background tasks}
        {--workers=4 : How many tasks to run at the same time}
        {--once : Run one task and stop, or stop when none comes}
        {--wait=60 : With --once, how many seconds to wait for a task}
        {--interval=2 : Seconds between asking the studio for work}';

    protected $description = 'Let Artisan Studio use the Claude or Codex you are already signed in to on this machine, for this project only, and log every task it runs';

    public function handle(Studio $studio, Shell $shell): int
    {
        if (! $studio->isLinked()) {
            $this->components->error('This project is not linked yet. Run php artisan studio:settings, then try again.');

            return self::FAILURE;
        }

        $runner = $this->runner($shell);

        if ($runner === null) {
            return self::FAILURE;
        }

        $log = new BridgeLog(storage_path('studio/bridge'));

        if ($this->hasSeveralWorkers()) {
            return $this->superviseWorkers($runner, $log);
        }

        $worker = new BridgeWorker($studio, $runner, $log);

        $this->components->info('Listening for AI tasks from Artisan Studio for this project, through '.$runner->name().' on this machine. Press Ctrl-C to stop.');
        $this->components->twoColumnDetail('Log of every task', $log->path());

        return $this->loop($worker);
    }

    private function hasSeveralWorkers(): bool
    {
        return ! $this->option('once') && $this->workers() > 1;
    }

    private function workers(): int
    {
        return max(1, min(8, (int) $this->option('workers')));
    }

    /**
     * Runs the workers as processes of this same command, one task each at a
     * time, and prints what they print.
     */
    private function superviseWorkers(Runner $runner, BridgeLog $log): int
    {
        $workers = new BridgeWorkers($this->workers(), fn (): Process => new Process(
            [PHP_BINARY, 'artisan', self::SIGNATURE, '--workers=1', '--runner='.$this->option('runner'), '--interval='.$this->option('interval'), '--plain', '--no-ansi', '--no-interaction'],
            base_path(),
            timeout: null,
        ));

        register_shutdown_function($workers->stop(...));

        $this->components->info('Listening for AI tasks from Artisan Studio for this project, through '.$runner->name().' on this machine, '.$this->workers().' at a time. Press Ctrl-C to stop.');
        $this->components->twoColumnDetail('Log of every task', $log->path());

        while (true) {
            array_map($this->line(...), $workers->keep());

            sleep(1);
        }
    }

    private function loop(BridgeWorker $worker): int
    {
        $startedAt = microtime(true);
        $interval = max(1, (int) $this->option('interval'));

        while (true) {
            $ran = $worker->tick();

            if ($ran !== null) {
                $this->components->twoColumnDetail(
                    ($ran['ok'] ? 'Answered ' : 'Could not answer ').substr($ran['id'], -8),
                    $ran['ms'].' ms'.($ran['ok'] ? '' : ' — '.$ran['error']).($ran['reported'] ? '' : ' (the studio did not take the answer)'),
                );

                if ($this->option('once')) {
                    return $ran['ok'] && $ran['reported'] ? self::SUCCESS : self::FAILURE;
                }

                continue;
            }

            if ($this->option('once') && microtime(true) - $startedAt >= (int) $this->option('wait')) {
                $this->components->warn('No task came.');

                return self::SUCCESS;
            }

            sleep($interval);
        }
    }

    private function runner(Shell $shell): ?Runner
    {
        $choice = (string) $this->option('runner');

        $candidates = match ($choice) {
            'claude' => [new ClaudeRunner($shell)],
            'codex' => [new CodexRunner($shell)],
            'auto' => [new ClaudeRunner($shell), new CodexRunner($shell)],
            default => [],
        };

        if ($candidates === []) {
            $this->components->error('The runner is claude, codex or auto.');

            return null;
        }

        $reasons = [];

        foreach ($candidates as $candidate) {
            $reason = $candidate->notReady();

            if ($reason === null) {
                return $candidate;
            }

            $reasons[] = $reason;
        }

        foreach ($reasons as $reason) {
            $this->components->error($reason);
        }

        return null;
    }
}
