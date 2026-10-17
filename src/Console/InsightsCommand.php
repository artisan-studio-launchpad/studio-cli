<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Fix\FixPanel;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Tab;
use ArtisanStudio\StudioCli\Work\WorkPanel;
use Illuminate\Console\Command;

/**
 * The project's insights, and the fix path: what is being fixed, rule by rule,
 * as it goes, and what is being worked through with the developer, one kind of
 * problem at a time, opened from the rule it belongs to.
 */
class InsightsCommand extends Command implements ProvidesTab
{
    use OpensInStudio;

    protected $signature = 'studio:insights';

    protected $description = 'Open Artisan Studio on the Insights tab';

    public function tab(Tab $tab): Tab
    {
        $work = WorkPanel::insights();
        $fixes = FixPanel::insights()->alongside($work);

        return $tab->label('Insights')
            ->visibleWhen(fn (): bool => $fixes->isOnShow() || $work->isOnShow() || app(SnapshotSource::class)->snapshot()->hasInsights())
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->unavailable(fn (NotConnected $notConnected): array => $notConnected->panel())
            ->components([
                ...$work->speech(),
                ...$fixes->speech(),
                ...$work->progress($fixes->carries(...)),
                ...$fixes->progress(),
                Text::make(fn (DashboardSnapshot $data): string => "Health {$data->health}%")
                    ->colour(fn (DashboardSnapshot $data): string => $data->healthColour())
                    ->bold()
                    ->description(fn (DashboardSnapshot $data): string => " {$data->healthLabel} · ".trans_choice(':count open issue|:count open issues', $data->totalOpen()).' across '.trans_choice(':count ruleset|:count rulesets', count($data->rulesets)))
                    ->link('View insights', fn (DashboardSnapshot $data): ?string => $data->insightsUrl),
            ])
            ->entersBy(
                fn (mixed $data): bool => $data instanceof DashboardSnapshot && ($work->canStart($data) || $fixes->canStart($data)),
                fn (DashboardSnapshot $data): string => $work->canStart($data) ? $work->start($data) : $fixes->start($data),
                fn (): string => $work->label(app(SnapshotSource::class)->snapshot()) ?? Says::named('Fix with {name}'),
            )
            ->enters(fn (): array => $work->questionPanel(), Says::named('Answer {name}'))
            ->opens(
                fn (array $record, mixed $parent): mixed => $parent,
                [...$work->speech(), ...$work->detail()],
                fn (array $record): bool => in_array($record['key'] ?? null, $work->tools(app(SnapshotSource::class)->snapshot()), true),
            )
            ->alsoRuns()
            ->enters(fn (): array => $work->questionPanel(), Says::named('Answer {name}'));
    }
}
