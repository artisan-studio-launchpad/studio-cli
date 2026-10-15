<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\WorkCommand;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;

/**
 * Starts working through a job in the background when the developer
 * presses ⏎ on its card: the press is the consent. The run outlives the
 * studio, so a studio started later still follows it.
 */
final class WorkLauncher
{
    public const string TASK = 'work';

    public const string RECHECKS = 'work-rechecks';

    public const string SENDS = 'work-sends';

    public function __construct(
        private readonly BackgroundTasks $tasks,
        private readonly WorkProgress $progress,
    ) {}

    public function isRunning(): bool
    {
        return $this->tasks->isRunning(self::TASK) || $this->progress->isAlive();
    }

    /**
     * @param  list<array{id: int, job: string, name: string, mode: string, files: int, state: string}>  $asked
     */
    public function canStart(array $asked): bool
    {
        return $asked !== [] && ! $this->isRunning();
    }

    /**
     * @param  list<array{id: int, job: string, name: string, mode: string, files: int, state: string}>  $asked
     */
    public function start(array $asked): string
    {
        $ask = $asked[0];
        $line = match (true) {
            $ask['mode'] === 'app' => 'Applying what you agreed in the app, on a safety branch.',
            $ask['job'] === WorkRun::TESTS => 'Writing a test for each of the '.$ask['files'].' files no test covers. Failing tests runs them last.',
            $ask['job'] === BuildRun::JOB => 'Building '.$ask['name'].' on a new branch, '.$ask['files'].' files step by step. I commit each step once it is written and checked, and stop once at the end for you to try your app.',
            default => 'Working through '.$ask['name'].' on a new branch, '.$ask['files'].' files, one kind of problem at a time. I commit each kind that passes, and stop once at the end for you to try your app.',
        };

        $building = $ask['job'] === BuildRun::JOB;
        $this->progress->forget();
        $this->tasks->start(self::TASK, Says::named($building ? 'Build with {name}' : 'Work with {name}'), $line, [WorkCommand::SIGNATURE, '--session='.$ask['id'], '--yes'], detached: true);

        app(ScreenRequests::class)->show($building ? 'build' : 'insights');

        return $line;
    }

    public function checkPlaces(): void
    {
        $this->tasks->start(self::RECHECKS, 'Check again', 'Checking the places you marked as done…', [WorkCommand::SIGNATURE, '--rechecks'], timeout: 900);
    }

    public function send(): void
    {
        $this->tasks->start(self::SENDS, 'Send for a PR', Says::named('Sending the fix branch to the studio, so {name} can open your PR…'), [WorkCommand::SIGNATURE, '--send'], timeout: 180);
    }
}
