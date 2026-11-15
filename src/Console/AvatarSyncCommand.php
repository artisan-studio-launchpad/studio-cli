<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\AvatarClips;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Console\Command;

class AvatarSyncCommand extends Command
{
    public const string SIGNATURE = 'studio:avatar-sync';

    protected $signature = self::SIGNATURE;

    protected $description = 'Download your avatar\'s clips from Artisan Studio, where they have changed';

    public function handle(AvatarClips $clips, Studio $studio): int
    {
        if (! $clips->fromTheStudio()) {
            $this->components->info('The avatar\'s clips come from '.$clips->folder().', so there is nothing to download.');

            return self::SUCCESS;
        }

        if (! $studio->isLinked() && $clips->streams()) {
            $this->components->info('Before linking, her idle clip is streamed from its public address, so there is nothing to download.');

            return self::SUCCESS;
        }

        $synced = $studio->isLinked() ? $clips->sync() : $clips->syncBeforeLinking();

        $this->components->twoColumnDetail('Downloaded', $synced['downloaded'] === [] ? 'nothing new' : implode(', ', $synced['downloaded']));
        $this->components->twoColumnDetail('Already up to date', $synced['kept'] === [] ? '—' : implode(', ', $synced['kept']));

        if ($synced['failed'] !== []) {
            $this->components->warn('Could not download '.implode(', ', $synced['failed']).'. They will be tried again next time.');
        }

        return self::SUCCESS;
    }
}
