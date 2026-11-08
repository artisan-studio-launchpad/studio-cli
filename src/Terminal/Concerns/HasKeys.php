<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\Terminal\Tab;
use Closure;

trait HasKeys
{
    private const array QUIT = ['key' => 'q', 'label' => 'Quit', 'colour' => 'soft', 'background' => 'band'];

    private const array SETTINGS_KEYS = [
        self::QUIT,
        ['key' => '↑↓', 'label' => 'Choose', 'colour' => 'cyan'],
        ['key' => '⏎', 'label' => 'Select', 'colour' => 'cyan'],
        ['key' => 'esc', 'label' => 'Back', 'colour' => 'amber'],
        ['key' => 's', 'label' => 'Close', 'colour' => 'cyan'],
    ];

    private const array TYPING_KEYS = [
        ['key' => '⏎', 'label' => 'Done', 'colour' => 'cyan'],
        ['key' => 'esc', 'label' => 'Cancel', 'colour' => 'amber'],
    ];

    private string|Closure|null $openUrl = null;

    private string $commandName = 'studio';

    private ?Closure $footerStatus = null;

    private string $footerStatusAction = '';

    /**
     * A state shown at the right end of the key hints, which a click on it
     * answers with the action. Hidden when the line has no room for it beside
     * the keys.
     *
     * @param  Closure(): array{text: string, colour: string}  $status
     */
    public function footerStatus(Closure $status, string $action): static
    {
        $this->footerStatus = $status;
        $this->footerStatusAction = $action;

        return $this;
    }

    /**
     * @return array{text: string, colour: string, action: string}|null
     */
    public function getFooterStatus(): ?array
    {
        return $this->footerStatus === null ? null : [...($this->footerStatus)(), 'action' => $this->footerStatusAction];
    }

    public function openUrl(string|Closure|null $url): static
    {
        $this->openUrl = $url;

        return $this;
    }

    public function commandName(string $name): static
    {
        $this->commandName = $name;

        return $this;
    }

    public function getOpenUrl(): ?string
    {
        $url = $this->evaluate($this->openUrl);

        return $url === null || $url === '' ? null : rtrim((string) $url, '/');
    }

    public function getCommandName(): string
    {
        return $this->commandName;
    }

    /**
     * @return list<array{key: string, label: string, colour: string, background?: string, optional?: bool, button?: bool, action?: string}>
     */
    public function footerKeys(?string $tab = null): array
    {
        return match (true) {
            $this->isTypingInSettings() => self::TYPING_KEYS,
            $this->showsPanel() => $this->panelKeys(),
            $this->settingsAreOpen() => self::SETTINGS_KEYS,
            default => $this->screenKeys($this->tab($tab)),
        };
    }

    /**
     * @return list<array{key: string, label: string, colour: string, background?: string, button?: bool, action?: string}>
     */
    private function panelKeys(): array
    {
        $selected = $this->selectedSetting()['action'] ?? null;

        return array_values(array_filter([
            self::QUIT,
            count($this->settingsActions()) > 1 ? ['key' => '↑↓', 'label' => 'Choose', 'colour' => 'cyan'] : null,
            $selected === null ? null : ['key' => '⏎', 'label' => $selected->getLabel(), 'colour' => 'cyan', 'button' => true, 'action' => 'settings:select'],
            ['key' => 'esc', 'label' => 'Not now', 'colour' => 'amber'],
        ]));
    }

    /**
     * @return list<array{key: string, label: string, colour: string, background?: string, optional?: bool}>
     */
    private function screenKeys(Tab $tab): array
    {
        return array_values(array_filter([
            self::QUIT,
            $this->canMoveBetweenTabs() ? ['key' => '←→', 'label' => 'Tabs', 'colour' => 'cyan'] : null,
            $tab->isNavigable() ? ['key' => '⏎', 'label' => 'Details', 'colour' => 'cyan', 'optional' => true] : null,
            ! $tab->isNavigable() && $tab->enterLabel() !== null && $tab->canEnter() ? ['key' => '⏎', 'label' => $tab->enterLabel(), 'colour' => 'amber'] : null,
            $tab->isOpen() ? ['key' => 'esc', 'label' => 'Back', 'colour' => 'amber'] : null,
            ['key' => 'r', 'label' => 'Refresh', 'colour' => 'blue', 'optional' => true],
            $this->openUrl === null ? null : ['key' => 'o', 'label' => 'Open', 'colour' => 'sky', 'optional' => true],
            $this->hasSettings() ? ['key' => 's', 'label' => 'Settings', 'colour' => 'cyan'] : null,
            ['key' => '?', 'label' => 'Help', 'colour' => 'amber'],
        ]));
    }

    /**
     * @return list<array{key: string, description: string, colour: string}>
     */
    public function helpKeys(): array
    {
        $labels = collect($this->getTabs())->map(fn (Tab $tab): string => $tab->getLabel())->values();

        return array_values(array_filter([
            ['key' => 'q', 'description' => 'Quit and give the terminal back', 'colour' => 'soft'],
            $this->canMoveBetweenTabs() ? ['key' => '← →', 'description' => 'Move between '.$labels->join(', ', ' and ').'. Tab works too', 'colour' => 'cyan'] : null,
            $this->canMoveBetweenTabs() ? ['key' => implode(' ', range(1, $labels->count())), 'description' => 'Jump straight to a tab', 'colour' => 'cyan'] : null,
            ['key' => '↑ ↓', 'description' => 'Scroll. The trackpad and mouse wheel work too, PgUp PgDn and Space by half a screen', 'colour' => 'cyan'],
            ['key' => '⏎ esc', 'description' => 'In a list, ↑ ↓ choose a row and Enter opens it. Esc goes back', 'colour' => 'cyan'],
            ['key' => 'g G', 'description' => 'Jump to the top or the bottom', 'colour' => 'cyan'],
            ['key' => 'r', 'description' => 'Refresh now. It also refreshes on its own every few seconds', 'colour' => 'blue'],
            $this->openUrl === null ? null : ['key' => 'o', 'description' => 'Open this project in Artisan Studio in your browser', 'colour' => 'sky'],
            $this->hasSettings() ? ['key' => 's', 'description' => 'Open or close Settings, to link this project, switch project or unlink. ↑ ↓ and Enter choose, Esc goes back', 'colour' => 'cyan'] : null,
            ['key' => '?', 'description' => 'Show or hide this help', 'colour' => 'amber'],
        ]));
    }
}
