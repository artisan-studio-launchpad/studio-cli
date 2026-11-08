<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Bridge\BridgeKeeper;
use ArtisanStudio\StudioCli\Console\BridgeCommand;
use ArtisanStudio\StudioCli\Studio;

beforeEach(function (): void {
    $this->started = 0;
    $this->tasks = new BackgroundTasks(sys_get_temp_dir(), app(ActivityLog::class));
    $this->keeper = fn (bool $linked) => new BridgeKeeper(
        Mockery::mock(Studio::class, fn ($mock) => $mock->shouldReceive('isLinked')->andReturn($linked)),
        $this->tasks,
        function (): void {
            $this->started++;
        },
    );
});

it('starts the bridge once the studio is open and linked', function (): void {
    ($this->keeper)(true)->keep();

    expect($this->started)->toBe(1);
});

it('starts nothing for a project that is not linked to the studio', function (): void {
    ($this->keeper)(false)->keep();

    expect($this->started)->toBe(0);
});

it('does not start the bridge again within the minute, so a machine with no Claude or Codex is not retried in a loop', function (): void {
    $keeper = ($this->keeper)(true);

    $keeper->keep();
    $keeper->keep();
    $keeper->keep();

    expect($this->started)->toBe(1);
});

it('starts a command that accepts the flags every background task is started with', function (): void {
    $options = (new BridgeCommand)->getDefinition();

    expect($options->hasOption('plain'))->toBeTrue();
});
