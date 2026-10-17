<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Tab;
use ArtisanStudio\StudioCli\Work\WorkPanel;
use Illuminate\Console\Command;

/**
 * An idea the studio builds with the developer: the card that asks for ⏎,
 * each step as it is written, checked and committed, and the one stop at the end.
 */
class BuildCommand extends Command implements ProvidesTab
{
    use OpensInStudio;

    protected $signature = 'studio:build';

    protected $description = 'Open Artisan Studio on the Build tab';

    public function tab(Tab $tab): Tab
    {
        $work = WorkPanel::builds();

        return $tab->label('Build')
            ->visibleWhen(fn (): bool => $work->isOnShow())
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->unavailable(fn (NotConnected $notConnected): array => $notConnected->panel())
            ->components([
                ...$work->speech(),
                ...$work->progress(),
            ])
            ->entersBy(
                fn (mixed $data): bool => $data instanceof DashboardSnapshot && $work->canStart($data),
                fn (DashboardSnapshot $data): string => $work->start($data),
                fn (): string => $work->label(app(SnapshotSource::class)->snapshot()) ?? Says::named('Build with {name}'),
            )
            ->enters(fn (): array => $work->questionPanel(), Says::named('Answer {name}'));
    }
}
