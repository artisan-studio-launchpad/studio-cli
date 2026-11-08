<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\BridgeWorkers;
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

it('runs four tasks at a time unless told otherwise', function (): void {
    expect((new BridgeCommand)->getDefinition()->getOption('workers')->getDefault())->toBe('4');
});
