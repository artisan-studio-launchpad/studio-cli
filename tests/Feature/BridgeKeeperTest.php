<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Bridge\BridgeConsent;
use ArtisanStudio\StudioCli\Bridge\BridgeKeeper;
use ArtisanStudio\StudioCli\Console\BridgeCommand;
use ArtisanStudio\StudioCli\Studio;

beforeEach(function (): void {
    $this->started = 0;
    $this->told = [];
    $this->tasks = new BackgroundTasks(sys_get_temp_dir(), app(ActivityLog::class));
    $this->consent = BridgeConsent::make();
    $this->keeper = fn (bool $linked) => new BridgeKeeper(
        Mockery::mock(Studio::class, function ($mock) use ($linked): void {
            $mock->shouldReceive('isLinked')->andReturn($linked);
            $mock->shouldReceive('reportBridge')->andReturnUsing(function (string $state): void {
                $this->told[] = $state;
            });
        }),
        $this->tasks,
        $this->consent,
        function (): void {
            $this->started++;
        },
    );
});

afterEach(fn () => @unlink($this->consent->path()));

it('starts the bridge once the studio is open, linked, and the developer said yes', function (): void {
    $this->consent->allow();

    ($this->keeper)(true)->keep();

    expect($this->started)->toBe(1);
});

it('starts nothing until the developer has said yes, and nothing after a no', function (?bool $choice): void {
    $choice === null || ($choice ? $this->consent->allow() : $this->consent->refuse());

    ($this->keeper)(true)->keep();

    expect($this->started)->toBe(0);
})->with(['no answer yet' => [null], 'a no' => [false]]);

it('starts nothing for a project that is not linked to the studio', function (): void {
    $this->consent->allow();

    ($this->keeper)(false)->keep();

    expect($this->started)->toBe(0);
});

it('does not start the bridge again within the minute, so a machine with no Claude Code is not retried in a loop', function (): void {
    $this->consent->allow();
    $keeper = ($this->keeper)(true);

    $keeper->keep();
    $keeper->keep();
    $keeper->keep();

    expect($this->started)->toBe(1);
});

it('remembers a yes and starts, and remembers a no and tells the studio it is off', function (): void {
    $keeper = ($this->keeper)(true);

    $keeper->allow();
    $afterYes = [$this->consent->choice(), $this->started, $this->told];
    $keeper->refuse();

    expect($afterYes)->toBe([true, 1, []])
        ->and($this->consent->choice())->toBeFalse()
        ->and($this->told)->toBe(['off']);
});

it('starts a command that accepts the flags every background task is started with', function (): void {
    $options = (new BridgeCommand)->getDefinition();

    expect($options->hasOption('plain'))->toBeTrue()
        ->and($options->hasOption('start'))->toBeTrue()
        ->and($options->hasOption('stop'))->toBeTrue();
});
