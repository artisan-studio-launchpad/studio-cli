<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Concerns;

use ArtisanStudio\StudioCli\Console\StudioCommand;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Tab;
use Illuminate\Console\Command;

/**
 * @phpstan-require-extends Command
 *
 * @phpstan-require-implements ProvidesTab
 */
trait OpensInStudio
{
    public function handle(): int
    {
        return $this->call(StudioCommand::SIGNATURE, ['tab' => $this->tab(Tab::make())->getKey()]);
    }
}
