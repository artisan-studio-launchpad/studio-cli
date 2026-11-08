<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Component;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use ArtisanStudio\StudioCli\Terminal\Settings;
use ArtisanStudio\StudioCli\Terminal\Tab;

trait RendersFrame
{
    public const int MIN_WIDTH = 72;

    private const int MAX_WIDTH = 160;

    private const int PADDING = 1;

    private const int FOOTER_ROWS = 2;

    private const int TITLE_ROOM = 40;

    private const int TABS_ROW = 3;

    private const int TAB_GAP = 2;

    private const int RAIL_EDGE = 2;

    private const string COG = '⚙';

    private const string COG_SYMBOL = '≡';

    private const string CARET = '▌';

    /**
     * @return list<string>
     */
    public function lines(int $width, int $height, ?string $tab = null, bool $help = false, ?string $flash = null, bool $interactive = true, int $scroll = 0): array
    {
        $canvas = $this->canvas();
        $this->drawnAt($width);

        if ($width < self::MIN_WIDTH) {
            return [$canvas->line([$canvas->span($canvas->fit('Make the terminal at least '.self::MIN_WIDTH.' columns wide.', $width), 'amber')], $width)];
        }

        $content = min($width, self::MAX_WIDTH);
        [$header, $body, $room] = $this->layout($canvas, $content, $height, $tab, $help);
        $scroll = max(0, min($scroll, count($body) - $room));
        $this->settingsTargets = $this->settingsAreOpen() && ! $help ? $this->targetsOnScreen(count($header), $scroll, $room, intdiv($width - $content, 2)) : [];
        $this->recordTargets = $this->settingsAreOpen() || $help ? [] : array_filter(
            collect($this->recordBodyTargets)->mapWithKeys(fn (int $record, int $index): array => [self::PADDING + count($header) + $index - $scroll + 1 => $index >= $scroll && $index < $scroll + $room ? $record : -1])->all(),
            fn (int $record): bool => $record >= 0,
        );
        $this->recordColumns = [
            'from' => intdiv($width - $content, 2) + Canvas::GUTTER + 1,
            'to' => intdiv($width - $content, 2) + $this->bodyWidth($content) - Canvas::GUTTER,
        ];
        $position = count($body) > $room ? ($scroll + 1).'–'.min(count($body), $scroll + $room).' of '.count($body) : null;
        $footer = $interactive ? $this->footer($canvas, $content, $flash, $position, $tab) : $this->tabFooter($canvas, $content);
        $padding = array_fill(0, self::PADDING, $canvas->blank($content));

        return array_values(collect([
            ...$padding,
            ...$header,
            ...$this->besideTheRail($canvas, [
                ...array_slice($body, $scroll, $room),
                ...array_fill(0, max(0, $room - count($body)), $canvas->blank($this->bodyWidth($content))),
            ], $tab),
            ...$footer,
            ...$padding,
        ])->map(fn (string $line): string => $this->centreOnScreen($canvas, $line, $content, $width))->all());
    }

    public function render(int $width, int $height, ?string $tab = null, bool $help = false, ?string $flash = null, bool $interactive = true, int $scroll = 0): string
    {
        return implode("\n", $this->lines($width, $height, $tab, $help, $flash, $interactive, $scroll));
    }

    public function tabAt(int $width, int $column, int $row): ?string
    {
        $this->drawnAt($width);

        if (! $this->showsTabs() || $width < self::MIN_WIDTH || $row !== self::PADDING + self::TABS_ROW) {
            return null;
        }

        $at = $column - intdiv($width - min($width, self::MAX_WIDTH), 2) - Canvas::GUTTER;
        $tabs = collect($this->getTabs())->values();
        $widths = $tabs->map(fn (Tab $tab): int => mb_strwidth(" {$tab->getLabel()} "));

        return $tabs->first(function (Tab $tab, int $index) use ($widths, $at): bool {
            $from = (int) $widths->take($index)->sum() + self::TAB_GAP * $index + 1;

            return $at >= $from && $at < $from + $widths[$index];
        })?->getKey();
    }

    public function settingsAt(int $width, int $column, int $row): bool
    {
        $content = min($width, self::MAX_WIDTH);
        $at = $column - intdiv($width - $content, 2) - Canvas::GUTTER;
        $inner = $content - 2 * Canvas::GUTTER;

        return $this->hasSettings()
            && $width >= self::MIN_WIDTH
            && $row === self::PADDING + self::TABS_ROW
            && $at > $inner - mb_strwidth($this->settingsLabel(true))
            && $at <= $inner;
    }

    public function scrollLimit(int $width, int $height, ?string $tab = null, bool $help = false): int
    {
        $this->drawnAt($width);

        if ($width < self::MIN_WIDTH) {
            return 0;
        }

        [, $body, $room] = $this->layout($this->canvas(), min($width, self::MAX_WIDTH), $height, $tab, $help);

        return max(0, count($body) - $room);
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: int}
     */
    private function layout(Canvas $canvas, int $content, int $height, ?string $tab, bool $help): array
    {
        $header = $this->header($canvas, $content, $tab);
        $width = $this->bodyWidth($content);
        $body = match (true) {
            $help => $this->help($canvas, $width),
            $this->settingsAreOpen() => $this->settingsBody($canvas, $width),
            default => $this->body($canvas, $width, $this->tab($tab)),
        };

        return [$header, $body, max(0, $height - count($header) - self::FOOTER_ROWS - 2 * self::PADDING)];
    }

    private function bodyWidth(int $content): int
    {
        return $content - ($this->shownRail()?->getWidth() ?? 0);
    }

    /**
     * @param  list<string>  $all
     * @return list<string>
     */
    private function railWindow(Canvas $canvas, array $all, int $room): array
    {
        $heading = array_slice($all, 0, 1);
        $rest = array_slice($all, 1);
        $fits = max(0, $room - count($heading));
        $this->railScroll = min($this->railScroll, max(0, count($rest) - $fits));
        $window = array_slice($rest, $this->railScroll, $fits);
        $moreBelow = $this->railScroll + $fits < count($rest);

        return [
            ...$heading,
            ...($moreBelow && $fits > 0 ? [...array_slice($window, 0, $fits - 1), $canvas->span('↓ scroll for earlier', 'dim')] : $window),
        ];
    }

    /**
     * @param  list<string>  $rows
     * @return list<string>
     */
    private function besideTheRail(Canvas $canvas, array $rows, ?string $tab): array
    {
        $rail = $this->shownRail();

        if ($rail === null) {
            return $rows;
        }

        $inner = $rail->getWidth() - self::RAIL_EDGE - Canvas::GUTTER;
        $lines = $this->railWindow($canvas, $rail->render($canvas, $inner, $rail->hasOwnState() ? $rail->stateFor($this->tab($tab)) : $this->getState()), count($rows));
        $edge = $canvas->span('│'.str_repeat(' ', self::RAIL_EDGE - 1), 'edge');
        $gutter = $canvas->span(str_repeat(' ', Canvas::GUTTER), 'ink');

        return array_values(collect(array_pad($lines, count($rows), ''))
            ->map(fn (string $line, int $index): string => $rows[$index].$canvas->line([$edge, $canvas->cell($line, $inner), $gutter], $rail->getWidth()))
            ->all());
    }

    /**
     * @return list<string>
     */
    private function header(Canvas $canvas, int $width, ?string $tab): array
    {
        $inner = $width - 2 * Canvas::GUTTER;
        $aside = ($this->getStatus() === '' ? '' : $canvas->span($this->getStatus(), $this->getStatusColour(), bold: true).$canvas->span('   ', 'dim')).$canvas->span($this->getAside(), 'dim');
        $heading = $canvas->gradient($canvas->fit($this->getHeading(), self::TITLE_ROOM), bold: true);
        $room = $inner - Canvas::visibleWidth($heading) - 5 - Canvas::visibleWidth($aside) - 1;
        $description = $this->getDescription() === null ? '' : $canvas->span('  ·  ', 'dim').$canvas->span($canvas->fit((string) $this->getDescription(), max(1, $room)), 'soft');

        return array_values(array_filter([
            $canvas->row($canvas->spread($heading.$description, $aside, $inner), $width),
            $canvas->row($canvas->gradient(str_repeat('─', $inner)), $width),
            $this->getNotice() === '' ? null : $canvas->row($canvas->span('● '.$canvas->fit($this->getNotice(), $inner - 2), $this->getNoticeColour()), $width),
            $this->showsTabs() || $this->hasSettings() ? $canvas->row($canvas->spread($this->tabsRow($canvas, $tab), $this->settingsTab($canvas), $inner), $width) : null,
            $canvas->blank($width),
        ]));
    }

    private function tabsRow(Canvas $canvas, ?string $tab): string
    {
        $current = $this->settingsAreOpen() && ! $this->showsPanel() ? null : $this->tab($tab)->getKey();

        return ! $this->showsTabs() ? '' : collect($this->getTabs())
            ->map(fn (Tab $each): string => $each->getKey() === $current
                ? $canvas->span(" {$each->getLabel()} ", 'cyan', 'cyan-bg', bold: true)
                : $canvas->span(" {$each->getLabel()} ", 'dim'))
            ->implode($canvas->span(str_repeat(' ', self::TAB_GAP), 'ink'));
    }

    private function settingsTab(Canvas $canvas): string
    {
        return match (true) {
            ! $this->hasSettings() => '',
            $this->settingsAreOpen() && ! $this->showsPanel() => $canvas->span($this->settingsLabel($canvas->emoji), 'cyan', 'cyan-bg', bold: true),
            default => $canvas->span($this->settingsLabel($canvas->emoji), 'dim'),
        };
    }

    private function settingsLabel(bool $emoji): string
    {
        return ' '.($emoji ? self::COG : self::COG_SYMBOL).' Settings ';
    }

    /**
     * @return list<string>
     */
    private function settingsBody(Canvas $canvas, int $width): array
    {
        $entries = $this->settingsEntries($canvas, $width - 2 * Canvas::GUTTER);
        $this->settingsBodyTargets = array_map(fn (array $entry): ?array => $entry['target'], $entries);

        return array_map(fn (array $entry): string => $canvas->row($entry['line'], $width), $entries);
    }

    /**
     * @return list<array{line: string, target: array{action: int, choice: ?int, to: int}|null}>
     */
    private function settingsEntries(Canvas $canvas, int $width): array
    {
        $selected = $this->selectedSetting()['action'] ?? null;

        return array_merge(...array_map(fn (Settings $group, int $index): array => [
            ...($index === 0 ? [] : [$this->entry('')]),
            $this->entry($canvas->span($group->getLabel(), 'cyan', bold: true)),
            ...array_map($this->entry(...), $group->render($canvas, $width, $group->getState())),
            ...$this->actionEntries($canvas, $group, $selected, $width),
            ...array_map($this->entry(...), $this->spacedOut($group->renderBelowActions($canvas, $width, $group->getState()))),
        ], $this->activeSettings(), array_keys($this->activeSettings())));
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function spacedOut(array $lines): array
    {
        return $lines === [] ? [] : ['', ...$lines];
    }

    /**
     * @param  array{action: int, choice: ?int, to: int}|null  $target
     * @return array{line: string, target: array{action: int, choice: ?int, to: int}|null}
     */
    private function entry(string $line, ?array $target = null): array
    {
        return ['line' => $line, 'target' => $target];
    }

    /**
     * @return list<array{line: string, target: array{action: int, choice: ?int, to: int}|null}>
     */
    private function actionEntries(Canvas $canvas, Settings $group, ?Action $selected, int $width): array
    {
        $actions = $group->visibleActions();
        $onlyTheFooters = $this->showsPanel() && count($actions) === 1 && $this->settingsChoosing === null;

        return $actions === [] || $onlyTheFooters ? [] : [
            $this->entry(''),
            ...collect($actions)->flatMap(fn (Action $action): array => [
                $this->entry($this->actionLine($canvas, $action, $action === $selected, $width), ['action' => $this->actionIndex($action), 'choice' => null, 'to' => 2 + mb_strwidth($action->getLabel())]),
                ...match (true) {
                    $action !== $selected || $this->settingsChoosing === null => [],
                    $action->getAsk() !== null => array_map($this->entry(...), $this->inputLines($canvas, $action, $width)),
                    default => $this->choiceEntries($canvas, $action, $group->getState(), $width),
                },
            ])->all(),
        ];
    }

    private function actionIndex(Action $action): int
    {
        return (int) collect($this->settingsActions())->search(fn (array $entry): bool => $entry['action'] === $action);
    }

    /**
     * @return array<int, array{action: int, choice: ?int, from: int, to: int}>
     */
    private function targetsOnScreen(int $headerRows, int $scroll, int $room, int $left): array
    {
        return collect($this->settingsBodyTargets)
            ->filter(fn (?array $target, int $index): bool => $target !== null && $index >= $scroll && $index < $scroll + $room)
            ->mapWithKeys(fn (array $target, int $index): array => [self::PADDING + $headerRows + $index - $scroll + 1 => [
                ...$target,
                'from' => $left + Canvas::GUTTER + 1,
                'to' => $left + Canvas::GUTTER + $target['to'],
            ]])
            ->all();
    }

    private function actionLine(Canvas $canvas, Action $action, bool $selected, int $width): string
    {
        $label = $canvas->fit($action->getLabel(), $width - 2);

        return match (true) {
            $selected && $this->settingsChoosing === null => $canvas->span('▸ '.$label, 'cyan', bold: true),
            $selected => $canvas->span('  '.$label, 'cyan'),
            default => $canvas->span('  '.$label, 'soft'),
        };
    }

    /**
     * @return list<string>
     */
    private function inputLines(Canvas $canvas, Action $action, int $width): array
    {
        $typed = (string) $this->settingsInput;
        $room = $width - 7;
        $masked = str_repeat('•', mb_strlen($typed));
        $lines = $action->isSecret()
            ? [mb_strlen($masked) > $room ? '…'.mb_substr($masked, 1 - $room) : $masked]
            : $canvas->wrap($typed, $room);
        $last = array_key_last($lines);

        return [
            ...array_map(fn (string $line): string => $canvas->span('    '.$line, 'dim'), $canvas->wrap((string) $action->getAsk(), $width - 4)),
            ...array_map(
                fn (string $line, int $index): string => $canvas->span($index === 0 ? '    › ' : '      ', 'cyan', bold: true)
                    .$canvas->span($line, 'soft')
                    .($index === $last ? $canvas->span(self::CARET, 'cyan') : ''),
                $lines,
                array_keys($lines),
            ),
        ];
    }

    /**
     * @return list<array{line: string, target: array{action: int, choice: ?int, to: int}|null}>
     */
    private function choiceEntries(Canvas $canvas, Action $action, mixed $state, int $width): array
    {
        $question = $action->getConfirmation() ?? $action->getQuestion();
        $choices = array_values($action->getChoices($state) ?? []);
        $label = fn (string $choice): string => $canvas->fit($choice, $width - 6);

        return [
            ...($question === null ? [] : array_map(fn (string $line): array => $this->entry($canvas->span('    '.$line, 'amber')), $canvas->wrap($question, $width - 4))),
            ...array_map(
                fn (string $choice, int $index): array => $this->entry(
                    $index === $this->settingsChoice
                        ? $canvas->span('    ▸ '.$label($choice), 'cyan', bold: true)
                        : $canvas->span('      '.$label($choice), 'soft'),
                    ['action' => $this->actionIndex($action), 'choice' => $index, 'to' => 6 + mb_strwidth($label($choice))],
                ),
                $choices,
                array_keys($choices),
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function body(Canvas $canvas, int $width, Tab $tab): array
    {
        $state = $tab->visibleState($tab->hasOwnState() ? $tab->getState() : $this->getState());
        $body = array_values(collect($tab->visibleComponents())
            ->map(fn (Component $component): array => ['tight' => $component->isTight(), 'lines' => array_map(fn (string $line): string => $canvas->row($line, $width), $component->render($canvas, $width - 2 * Canvas::GUTTER, $state))])
            ->reject(fn (array $block): bool => $block['lines'] === [])
            ->reduce(fn (array $lines, array $block): array => $lines === [] ? $block['lines'] : [...$lines, ...($block['tight'] ? [] : [$canvas->blank($width)]), ...$block['lines']], []));
        $this->recordBodyTargets = $this->recordLines($tab->listLines($canvas, $width - 2 * Canvas::GUTTER), $body);

        return $body;
    }

    /**
     * @param  list<string>  $list
     * @param  list<string>  $body
     * @return array<int, int>
     */
    private function recordLines(array $list, array $body): array
    {
        $start = count($list) < 3 ? false : collect($body)->search(fn (string $line, int $index): bool => str_contains($line, $list[0]) && str_contains($body[$index + 1] ?? '', $list[1]));

        return $start === false ? [] : collect(range(0, count($list) - 4))->mapWithKeys(fn (int $record): array => [$start + 2 + $record => $record])->all();
    }

    /**
     * @return list<string>
     */
    private function help(Canvas $canvas, int $width): array
    {
        $keys = Section::make('Keys')->components([
            Table::make($this->helpKeys())->columns([
                Column::make('key')->width(10)->colour(fn (array $record): string => $record['colour'])->bold(),
                Column::make('description')->label('What it does')->colour('soft'),
            ]),
        ]);

        return array_values(collect($keys->render($canvas, $width - 2 * Canvas::GUTTER, $this->getState()))
            ->map(fn (string $line): string => $canvas->row($line, $width))
            ->all());
    }

    /**
     * @return list<string>
     */
    private function footer(Canvas $canvas, int $width, ?string $flash, ?string $position, ?string $tab): array
    {
        $inner = $width - 2 * Canvas::GUTTER;
        $aside = match (true) {
            $flash !== null => $canvas->span($flash, 'green', bold: true),
            $position !== null => $canvas->span('↑↓ ', 'cyan', bold: true).$canvas->span($position, 'dim'),
            default => '',
        };
        $chips = fn (bool $all): string => collect($this->footerKeys($tab))
            ->filter(fn (array $key): bool => $all || ! ($key['optional'] ?? false))
            ->map(fn (array $key): string => $this->chip($canvas, $key))
            ->implode($canvas->span('   ', 'ink'));
        $keys = Canvas::visibleWidth($chips(true)) <= $inner ? $chips(true) : $chips(false);

        return [
            $canvas->row($canvas->span(str_repeat('─', $inner), 'edge'), $width),
            $canvas->row($canvas->spread($keys, $aside, $inner), $width),
        ];
    }

    /**
     * @param  array{key: string, label: string, colour: string, background?: string, optional?: bool, button?: bool, action?: string}  $key
     */
    private function chip(Canvas $canvas, array $key): string
    {
        $chip = ($key['button'] ?? false)
            ? $canvas->span(" {$key['key']} {$key['label']} ", $key['colour'], "{$key['colour']}-bg", bold: true)
            : $canvas->span(" {$key['key']} ", $key['colour'], $key['background'] ?? "{$key['colour']}-bg", bold: true).$canvas->span(" {$key['label']}", 'soft');

        return isset($key['action']) ? $canvas->link($chip, Canvas::ACTION.$key['action']) : $chip;
    }

    /**
     * @return list<string>
     */
    private function tabFooter(Canvas $canvas, int $width): array
    {
        return [
            $canvas->row($canvas->span(str_repeat('─', $width - 2 * Canvas::GUTTER), 'track'), $width),
            $canvas->row($canvas->span('Updates on its own. Keys and tabs: ', 'dim').$canvas->span("php artisan {$this->getCommandName()}", 'cyan', bold: true), $width),
        ];
    }

    private function centreOnScreen(Canvas $canvas, string $line, int $content, int $width): string
    {
        $left = intdiv($width - $content, 2);

        return $width === $content
            ? $line
            : $canvas->span(str_repeat(' ', $left), 'ink').$line.$canvas->span(str_repeat(' ', $width - $content - $left), 'ink')."\e[0m";
    }
}
