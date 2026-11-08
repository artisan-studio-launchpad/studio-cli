<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Bridge\BridgeBeat;
use ArtisanStudio\StudioCli\Bridge\BridgeConsent;
use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Bridge\BridgeKeeper;
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
        {--stop : Say no to the AI bridge for this project, and stop it}
        {--runner=claude : claude. codex is refused unless studio-cli.bridge.codex is turned on}
        {--parent= : The process id of the studio that started this; the bridge stops when it is gone}
        {--worker : One of the bridge\'s own workers, which the bridge starts and stops}
        {--plain : Accepted so the studio can start it like its other background tasks}
        {--workers=4 : How many tasks to run at the same time}
        {--once : Run one task and stop, or stop when none comes}
        {--wait=60 : With --once, how many seconds to wait for a task}
        {--interval=2 : Seconds between asking the studio for work}';

    protected $description = 'Let Artisan Studio use the Claude Code you are already signed in to on this machine, for this project only, and log every task it runs';

    public function handle(Studio $studio, Shell $shell): int
    {
        if (! $studio->isLinked()) {
            $this->components->error('This project is not linked yet. Run php artisan studio:settings, then try again.');

            return self::FAILURE;
        }

        if ($this->option('stop')) {
            app(BridgeKeeper::class)->refuse();
            $this->components->info(__('studio-cli::bridge.consent.refused'));

            return self::SUCCESS;
        }

        $consent = app(BridgeConsent::class);

        if (! $consent->isAllowed()) {
            $this->components->error(__('studio-cli::bridge.consent.not_given'));

            return self::FAILURE;
        }

        $runner = $this->runner($shell);

        if ($runner === null) {
            return self::FAILURE;
        }

        Shell::abortWhen($this->orphaned(...));

        if (! $this->option('worker')) {
            if (! BridgeBeat::mark()) {
                return self::SUCCESS;
            }

            register_shutdown_function(BridgeBeat::unmark(...));
            $studio->reportBridge(BridgeGate::ON);
        }

        $log = new BridgeLog(storage_path('studio/bridge'), (bool) config('studio-cli.bridge.debug'));

        try {
            return $this->hasSeveralWorkers() ? $this->superviseWorkers($log) : $this->loop(new BridgeWorker($studio, $runner, $log));
        } finally {
            BridgeBeat::unmark();
            Shell::abortWhen(null);
        }
    }

    /**
     * Whether the studio that started this is gone, so nothing is left running
     * on the machine after a crash or a kill of the screen.
     */
    private function orphaned(): bool
    {
        $parent = (int) $this->option('parent');

        return $parent > 0 && ! BackgroundTasks::isAlive($parent);
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
     * time. They follow this process: if it goes, they stop. Nothing is printed
     * while listening; the studio screen shows what it already shows.
     */
    private function superviseWorkers(BridgeLog $log): int
    {
        $workers = new BridgeWorkers($this->workers(), fn (): Process => new Process(
            [PHP_BINARY, 'artisan', self::SIGNATURE, '--workers=1', '--worker', '--parent='.getmypid(), '--runner='.$this->option('runner'), '--interval='.$this->option('interval'), '--plain', '--no-ansi', '--no-interaction'],
            base_path(),
            timeout: null,
        ));

        register_shutdown_function($workers->stop(...));

        while (! $this->orphaned()) {
            $workers->keep();

            sleep(1);
        }

        $workers->stop();

        return self::SUCCESS;
    }

    private function loop(BridgeWorker $worker): int
    {
        $startedAt = microtime(true);
        $interval = max(1, (int) $this->option('interval'));

        while (! $this->orphaned()) {
            $ran = $worker->tick();

            if ($ran !== null) {
                if ($this->option('once')) {
                    $this->components->twoColumnDetail(
                        ($ran['ok'] ? 'Answered ' : 'Could not answer ').substr($ran['id'], -8),
                        $ran['ms'].' ms'.($ran['ok'] ? '' : ' — '.$ran['error']).($ran['reported'] ? '' : ' (the studio did not take the answer)'),
                    );

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

        return self::SUCCESS;
    }

    private function runner(Shell $shell): ?Runner
    {
        $choice = (string) $this->option('runner');

        if ($choice === 'codex' && config('studio-cli.bridge.codex') !== true) {
            $this->components->error(__('studio-cli::bridge.runner.codex_off'));

            return null;
        }

        $runner = match ($choice) {
            'claude' => new ClaudeRunner($shell),
            'codex' => new CodexRunner($shell),
            default => null,
        };

        if ($runner === null) {
            $this->components->error(__('studio-cli::bridge.runner.unknown'));

            return null;
        }

        $reason = $runner->notReady();

        if ($reason !== null) {
            $this->components->error($reason);

            return null;
        }

        return $runner;
    }
}
