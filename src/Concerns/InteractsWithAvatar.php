<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Concerns;

use ArtisanStudio\StudioCli\AvatarClips;
use ArtisanStudio\StudioCli\Console\AvatarSyncCommand;
use ArtisanStudio\StudioCli\Presence;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Process;

trait InteractsWithAvatar
{
    private bool $avatarLeavesOnExit = false;

    protected function avatar(): Presence
    {
        return Container::getInstance()->make(Presence::class);
    }

    protected function avatarArrives(): void
    {
        $this->avatarLeavesWhenThisEnds();
        $this->refreshAvatarClips();
        $this->avatar()->arrive();
    }

    /**
     * The screens before a project is linked. Her idle clip is streamed from its public address, so there is
     * nothing to fetch; only with streaming off is it downloaded, in the background, into the per-user cache.
     */
    protected function avatarArrivesBeforeLinking(): void
    {
        $this->avatarLeavesWhenThisEnds();

        Container::getInstance()->make(AvatarClips::class)->streams() || $this->refreshAvatarClips();
    }

    private function avatarLeavesWhenThisEnds(): void
    {
        if (! $this->avatarLeavesOnExit) {
            $this->avatarLeavesOnExit = true;
            register_shutdown_function(fn () => $this->avatar()->dismiss());
        }
    }

    private function refreshAvatarClips(): void
    {
        if (! $this->avatar()->isAvailable() || ! Container::getInstance()->make(AvatarClips::class)->fromTheStudio()) {
            return;
        }

        Process::run(sprintf(
            'nohup %s %s %s > /dev/null 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            AvatarSyncCommand::SIGNATURE,
        ));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function avatarReacts(array $event): void
    {
        $this->avatar()->react($event);
    }

    protected function avatarSettles(): void
    {
        $this->avatar()->settle();
    }

    protected function avatarLeaves(): void
    {
        $this->avatar()->dismiss();
    }
}
