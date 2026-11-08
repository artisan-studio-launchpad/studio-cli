<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Bridge\BridgeWorkers;
use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\Console\BridgeCommand;
use Symfony\Component\Process\Process;

afterEach(fn () => isset($this->workers) && $this->workers->stop());

it('keeps as many workers running as it was asked for', function (): void {
    $this->workers = new BridgeWorkers(3, fn (): Process => new Process([PHP_BINARY, '-r', 'sleep(30);']));

    $this->workers->keep();

    expect($this->workers->running())->toBe(3);

    $this->workers->stop();

    expect($this->workers->running())->toBe(0);
});

it('does not start a worker that stopped again straight away, so one that cannot run is not a loop', function (): void {
    $made = 0;
    $this->workers = new BridgeWorkers(2, function () use (&$made): Process {
        $made++;

        return new Process([PHP_BINARY, '-r', 'exit(1);']);
    });

    $this->workers->keep();
    usleep(300_000);
    $this->workers->keep();
    $this->workers->keep();

    expect($made)->toBe(2);
});

it('gives back what the workers print', function (): void {
    $this->workers = new BridgeWorkers(1, fn (): Process => new Process([PHP_BINARY, '-r', 'echo "Answered abc123\n";']));

    $this->workers->keep();
    usleep(500_000);

    expect($this->workers->keep())->toBe(['Answered abc123']);
});

it('stops what a worker started along with the worker, so no program is left running', function (): void {
    $marker = sys_get_temp_dir().'/studio-worker-child-'.bin2hex(random_bytes(4));
    $this->workers = new BridgeWorkers(1, fn (): Process => new Process(['sh', '-c', 'sleep 30 & echo $! > '.escapeshellarg($marker).'; wait']));

    $this->workers->keep();

    $deadline = microtime(true) + 5;

    while (! is_file($marker) && microtime(true) < $deadline) {
        usleep(50_000);
    }

    $child = (int) trim((string) file_get_contents($marker));

    expect(BackgroundTasks::isAlive($child))->toBeTrue();

    $this->workers->stop();
    usleep(300_000);

    @unlink($marker);

    expect(BackgroundTasks::isAlive($child))->toBeFalse();
});

it('stops a program it started when the studio that started the bridge is gone', function (): void {
    $marker = sys_get_temp_dir().'/studio-shell-child-'.bin2hex(random_bytes(4));
    $gone = false;
    Shell::abortWhen(function () use (&$gone, $marker): bool {
        return $gone = is_file($marker);
    });

    $started = microtime(true);
    $result = (new Shell)->run(['sh', '-c', 'sleep 30 & echo $! > '.escapeshellarg($marker).'; wait'], '', sys_get_temp_dir(), 60);

    $child = (int) trim((string) file_get_contents($marker));
    usleep(300_000);
    @unlink($marker);

    expect($gone)->toBeTrue()
        ->and($result['exit'])->toBe(143)
        ->and(microtime(true) - $started)->toBeLessThan(10)
        ->and(BackgroundTasks::isAlive($child))->toBeFalse();
});

it('runs four tasks at a time unless told otherwise', function (): void {
    expect((new BridgeCommand)->getDefinition()->getOption('workers')->getDefault())->toBe('4');
});
