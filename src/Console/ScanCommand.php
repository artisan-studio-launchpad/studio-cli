<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Concerns\OpensInStudio;
use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\NotConnected;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Scan\ScanRules;
use ArtisanStudio\StudioCli\Terminal\Components\Alert;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\BarColumn;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Contracts\ProvidesTab;
use ArtisanStudio\StudioCli\Terminal\Spinner;
use ArtisanStudio\StudioCli\Terminal\Tab;
use Illuminate\Console\Command;

/**
 * The scan as it happens: every rule that is checked, in the order of its
 * group, and how many issues each caught. The first group, Initial, runs for
 * everyone; the rest are whatever the developer picked in the app.
 */
class ScanCommand extends Command implements ProvidesTab
{
    use OpensInStudio;

    public const string TAB = 'scan';

    protected $signature = 'studio:scan';

    protected $description = 'Open Artisan Studio on the Scan tab';

    public function tab(Tab $tab): Tab
    {
        return $tab->label('Scan')
            ->key(self::TAB)
            ->visibleWhen(fn (): bool => app(SnapshotSource::class)->snapshot()->scanHasStarted())
            ->state(fn (SnapshotSource $source): DashboardSnapshot => $source->snapshot())
            ->unavailable(fn (NotConnected $notConnected): array => $notConnected->panel())
            ->components([
                Alert::make(fn (DashboardSnapshot $data): string => app(Says::class)->line(self::TAB, $this->says($data))['title'])
                    ->colour(fn (DashboardSnapshot $data): string => app(Says::class)->line(self::TAB, $this->says($data))['colour'])
                    ->description(fn (DashboardSnapshot $data): string => app(Says::class)->line(self::TAB, $this->says($data))['line'])
                    ->descriptionColour('soft'),
                Text::make(fn (DashboardSnapshot $data): string => $this->headline($data))
                    ->colour(fn (DashboardSnapshot $data): string => $this->rules($data)->caught() > 0 ? 'amber' : 'green')
                    ->bold()
                    ->description(fn (DashboardSnapshot $data): string => $this->explanation($data)),
                Section::make('Rules checked')
                    ->aside(fn (DashboardSnapshot $data): string => number_format($this->rules($data)->caught()).' '.trans_choice('issue|issues', $this->rules($data)->caught()))
                    ->asideColour(fn (DashboardSnapshot $data): string => $this->rules($data)->caught() > 0 ? 'amber' : 'dim')
                    ->components([
                        Table::make(fn (DashboardSnapshot $data): array => $this->rules($data)->rows())
                            ->columns([
                                Column::make('group')->label('Group')->width(10)->colour('soft'),
                                Column::make('name')->label('Rule')->width(26),
                                Column::make('caught')
                                    ->label('Issues')
                                    ->width(8)
                                    ->bold()
                                    ->colour(fn (array $row): string => $row['state'] === ScanRules::DONE ? ($row['caught'] > 0 ? 'amber' : 'green') : 'dim')
                                    ->formatStateUsing(fn (mixed $caught): string => $caught === null || $caught === '' ? '—' : number_format((int) $caught)),
                                BarColumn::make('bar')
                                    ->label('')
                                    ->colour('amber')
                                    ->fraction(fn (array $row, DashboardSnapshot $data): ?float => $row['state'] === ScanRules::DONE && $row['caught'] > 0 ? $row['caught'] / $this->mostCaught($data) : null)
                                    ->placeholder(''),
                                Column::make('note')
                                    ->label('')
                                    ->width(24)
                                    ->colour(fn (array $row): string => match ($row['state']) {
                                        ScanRules::RUNNING => 'cyan',
                                        ScanRules::DONE => $row['caught'] === 0 ? 'green' : 'amber',
                                        ScanRules::FAILED, ScanRules::INSTALL => 'amber',
                                        default => 'dim',
                                    })
                                    ->formatStateUsing(fn (mixed $note, array $row): string => ($row['state'] === ScanRules::RUNNING ? Spinner::frame().' ' : '').$note),
                                Column::make('elapsed')
                                    ->label('Time')
                                    ->width(9)
                                    ->colour(fn (array $row): string => $row['state'] === ScanRules::RUNNING ? 'cyan' : 'soft')
                                    ->formatStateUsing(fn (mixed $elapsed): string => $elapsed === null || $elapsed === '' ? '—' : $this->duration((int) $elapsed)),
                            ]),
                    ]),
            ]);
    }

    private function duration(int $milliseconds): string
    {
        $seconds = intdiv(max(0, $milliseconds), 1000);

        return match (true) {
            $milliseconds < 10_000 => number_format($milliseconds / 1000, 1).'s',
            $seconds < 60 => $seconds.'s',
            default => intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s',
        };
    }

    private function rules(DashboardSnapshot $data): ScanRules
    {
        return app(ScanRules::class)->forStudio($data->hasScanned());
    }

    /**
     * The count in the headline: what Insights will list once the studio has
     * said how it groups the issues, otherwise the issues themselves.
     */
    private function headline(DashboardSnapshot $data): string
    {
        $grouped = $this->rules($data)->grouped();
        $count = $grouped === null ? $this->rules($data)->caught() : $grouped['open'];

        return number_format($count).' '.trans_choice($grouped === null ? 'issue|issues' : 'problem|problems', $count);
    }

    /**
     * What the headline counts, and why it may differ from the issues the
     * rules caught, so Insights' number is no surprise.
     */
    private function explanation(DashboardSnapshot $data): string
    {
        $rules = trans_choice(':count rule|:count rules', count($this->rules($data)->rows()));

        return $this->rules($data)->grouped() === null
            ? ' detected across '.$rules
            : ' in Insights, from '.number_format($this->rules($data)->caught()).' issues across '.$rules.', repeats grouped';
    }

    private function says(DashboardSnapshot $data): string
    {
        $rows = collect($this->rules($data)->rows());

        return match (true) {
            $rows->every(fn (array $row): bool => $row['state'] === ScanRules::WAITING) => 'idle',
            $this->rules($data)->isChecking() => 'checking',
            default => 'done',
        };
    }

    private function mostCaught(DashboardSnapshot $data): int
    {
        return max(1, (int) collect($this->rules($data)->rows())->max('caught'));
    }
}
