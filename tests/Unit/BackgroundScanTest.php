<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\ActivityLog;
use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\PhpStanCommand;
use ArtisanStudio\StudioCli\Scan\ScanProgress;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-background-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
    file_put_contents($this->root.'/artisan', <<<'PHP'
        <?php
        file_put_contents(getenv("STUDIO_TASK_JOURNAL"), "Listed your files with git ls-files: 2,994 tracked.\nSending the counts to the studio: 214 KB.\n", FILE_APPEND);
        echo "Working…\n";
        echo in_array('studio:fail', $argv, true) ? "The studio did not take the counts.\n" : "Counted how 2,516 files are written in 4.5s: 34 conventions followed, 11 to decide.\n";
        exit(in_array('studio:fail', $argv, true) ? 1 : 0);
        PHP);
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
    @unlink((new ScanProgress($this->root))->path());
});

function waitUntilFinished(BackgroundTasks $tasks, string $key): void
{
    $until = microtime(true) + 10;

    while ($tasks->isRunning($key) && microtime(true) < $until) {
        usleep(20_000);
    }

    $tasks->tick();
}

it('shows the work in the rail the moment it starts, then its last line when it is done', function (): void {
    $log = new ActivityLog;
    $tasks = new BackgroundTasks($this->root, $log);

    $tasks->start('conventions', 'Conventions', 'Counting how your project is written…', ['studio:conventions']);

    expect($log->entries()[0])->toMatchArray(['label' => 'Conventions', 'detail' => 'Counting how your project is written…', 'colour' => 'cyan', 'kind' => 'conventions']);

    waitUntilFinished($tasks, 'conventions');

    expect(array_column($log->entries(), 'detail'))->toBe([
        'Counted how 2,516 files are written in 4.5s: 34 conventions followed, 11 to decide.',
        'Sending the counts to the studio: 214 KB.',
        'Listed your files with git ls-files: 2,994 tracked.',
    ])
        ->and($log->entries()[0]['colour'])->toBe('green')
        ->and($log->entries()[2]['colour'])->toBe('sky');
});

it('starts the tests only once the scan\'s tools have finished, so the scan never waits on them', function (): void {
    $log = new ActivityLog;
    $tasks = new BackgroundTasks($this->root, $log);

    $tasks->start('tools', 'Scan tools', 'Running your tools…', ['studio:tools'], then: ['key' => 'tests', 'label' => 'Your tests', 'working' => 'Checking whether you switched your tests on…', 'command' => ['studio:tests']]);

    expect($tasks->isRunning('tests'))->toBeFalse();

    waitUntilFinished($tasks, 'tools');

    expect(collect($log->entries())->firstWhere('kind', 'tests'))->toMatchArray(['label' => 'Your tests', 'detail' => 'Checking whether you switched your tests on…']);

    waitUntilFinished($tasks, 'tests');

    expect(collect($log->entries())->where('kind', 'tests')->first()['colour'])->toBe('green');
});

it('runs PHPStan after the scan\'s tools and the tests after PHPStan, one at a time, so neither holds up the findings', function (): void {
    $log = new ActivityLog;
    $tasks = new BackgroundTasks($this->root, $log);

    $tasks->start('tools', 'Scan tools', 'Running your tools…', ['studio:tools'], then: PhpStanCommand::afterTheTools([['key' => 'tests', 'label' => 'Your tests', 'working' => 'Checking whether you switched your tests on…', 'command' => ['studio:tests']]]));

    waitUntilFinished($tasks, 'tools');

    expect($tasks->isRunning('phpstan'))->toBeTrue()
        ->and($tasks->isRunning('tests'))->toBeFalse()
        ->and(collect($log->entries())->firstWhere('kind', 'phpstan'))->toMatchArray(['label' => 'PHPStan', 'detail' => PhpStanCommand::WORKING]);

    waitUntilFinished($tasks, 'phpstan');

    expect(collect($log->entries())->firstWhere('kind', 'tests'))->toMatchArray(['label' => 'Your tests']);

    waitUntilFinished($tasks, 'tests');

    expect(collect($log->entries())->where('kind', 'tests')->first()['colour'])->toBe('green');
});

it('marks work that failed in amber, and never starts the same work twice at once', function (): void {
    $log = new ActivityLog;
    $tasks = new BackgroundTasks($this->root, $log);

    $tasks->start('conventions', 'Conventions', 'Counting…', ['studio:fail']);
    $tasks->start('conventions', 'Conventions', 'Counting again…', ['studio:fail']);

    expect($log->entries()[0]['detail'])->toBe('Counting…');

    waitUntilFinished($tasks, 'conventions');

    expect($log->entries()[0])->toMatchArray(['detail' => 'The studio did not take the counts.', 'colour' => 'amber']);
});

it('reports the scan only while it runs, and leaves nothing behind once it ends', function (): void {
    $progress = new ScanProgress($this->root);

    expect($progress->label())->toBeNull()->and($progress->fraction())->toBeNull();

    $progress->progressed(49, 200);

    expect($progress->label())->toBeNull();

    $progress->progressed(100, 200);

    expect($progress->label())->toBe("Reading this project's files locally")
        ->and($progress->fraction())->toBe(0.5);

    $progress->finished();

    expect($progress->label())->toBeNull()
        ->and($progress->fraction())->toBeNull()
        ->and(is_file($progress->path()))->toBeFalse();
});

it('lets a detached task outlive the studio, and the next studio follows it to its last line', function (): void {
    file_put_contents($this->root.'/artisan', <<<'PHP'
        <?php
        usleep(600_000);
        file_put_contents(getenv("STUDIO_TASK_JOURNAL"), (getenv("STUDIO_DETACHED") === "1" ? "Fixed on its own.\n" : "Not detached.\n"), FILE_APPEND);
        PHP);
    $log = new ActivityLog;
    $tasks = new BackgroundTasks($this->root, $log);

    $tasks->start('fixes', 'Fix with SAMI', 'Fixing…', ['studio:fix'], detached: true);
    unset($tasks);

    $later = new BackgroundTasks($this->root, $log);

    expect($later->isRunning('fixes'))->toBeTrue();

    waitUntilFinished($later, 'fixes');

    expect($log->entries()[0])->toMatchArray(['label' => 'Fix with SAMI', 'detail' => 'Fixed on its own.'])
        ->and($later->isRunning('fixes'))->toBeFalse()
        ->and((new BackgroundTasks($this->root, $log))->isRunning('fixes'))->toBeFalse();
});
