<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Console\FixCommand;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;

/**
 * Starts the fix run in the background when the developer presses ⏎ on
 * what the studio asked for, from whichever tab they are on.
 */
final class FixLauncher
{
    /**
     * Where a run is followed.
     */
    public const string TAB = 'insights';

    public const string TASK = 'fixes';

    public function __construct(
        private readonly BackgroundTasks $tasks,
        private readonly string $root,
    ) {}

    /**
     * @param  list<string>  $asked
     * @return list<string>
     */
    public function fixable(array $asked): array
    {
        return $asked === [] ? [] : (new AutomatedFixes(new Toolbox($this->root), $this->root))->fixable($asked);
    }

    /**
     * @param  list<string>  $asked
     */
    public function names(array $asked): string
    {
        $toolbox = new Toolbox($this->root);

        return collect($this->fixable($asked))->map($toolbox->name(...))->join(', ', ' and ');
    }

    /**
     * @param  list<string>  $asked
     */
    public function canStart(array $asked): bool
    {
        return ! $this->isRunning() && $this->fixable($asked) !== [];
    }

    /**
     * The run outlives the studio, so a studio started later still sees it
     * going, from its own process.
     */
    public function isRunning(): bool
    {
        return $this->tasks->isRunning(self::TASK) || (new FixProgress($this->root))->isAlive();
    }

    /**
     * @param  list<string>  $asked
     */
    public function start(array $asked): string
    {
        $keys = $this->fixable($asked);
        $listed = $this->names($asked);

        (new FixProgress($this->root))->forget();

        $this->tasks->start(
            self::TASK,
            Says::named('Fix with {name}'),
            'Fixing '.$listed.' on a new branch…',
            [FixCommand::SIGNATURE, ...array_map(fn (string $key): string => '--tool='.$key, $keys), '--yes'],
            detached: true,
        );

        app(ScreenRequests::class)->show(self::TAB);

        return 'Fixing '.$listed.' on a new branch. Your score moves as each fix lands.';
    }
}
