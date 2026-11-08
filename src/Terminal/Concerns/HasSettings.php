<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Settings;

trait HasSettings
{
    public const string SETTINGS = 'settings';

    /**
     * @var list<Settings>
     */
    private array $settings = [];

    /**
     * @var list<Settings>|null
     */
    private ?array $panel = null;

    private bool $settingsOpen = false;

    private int $settingsCursor = 0;

    private ?string $settingsChoosing = null;

    private int $settingsChoice = 0;

    private ?string $settingsInput = null;

    /**
     * @var list<array{action: int, choice: ?int, to: int}|null>
     */
    private array $settingsBodyTargets = [];

    /**
     * @var array<int, array{action: int, choice: ?int, from: int, to: int}>
     */
    private array $settingsTargets = [];

    /**
     * @param  list<Settings>  $settings
     */
    public function settings(array $settings): static
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * @return list<Settings>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    public function hasSettings(): bool
    {
        return $this->settings !== [];
    }

    public function settingsAreOpen(): bool
    {
        return $this->settingsOpen;
    }

    public function openSettings(): static
    {
        $this->panel = null;
        $this->settingsOpen = $this->hasSettings();
        $this->settingsCursor = 0;
        $this->settingsChoosing = null;

        return $this->refreshSettings();
    }

    /**
     * @param  list<Settings>  $panel
     */
    public function openPanel(array $panel): static
    {
        if ($panel === []) {
            return $this;
        }

        $this->panel = $panel;
        $this->settingsOpen = true;
        $this->settingsCursor = 0;

        return $this->stopChoosing()->refreshSettings();
    }

    public function showsPanel(): bool
    {
        return $this->settingsOpen && $this->panel !== null;
    }

    public function closeSettings(): static
    {
        $this->settingsOpen = false;
        $this->panel = null;

        return $this->stopChoosing();
    }

    public function isTypingInSettings(): bool
    {
        return $this->settingsOpen && $this->settingsInput !== null;
    }

    public function typeInSettings(string $text): static
    {
        $this->settingsInput = $this->settingsInput === null ? null : $this->settingsInput.$text;

        return $this;
    }

    public function eraseInSettings(): static
    {
        $this->settingsInput = $this->settingsInput === null ? null : mb_substr($this->settingsInput, 0, -1);

        return $this;
    }

    public function settleAfter(Action $done): static
    {
        $still = collect($this->settingsActions())->search(fn (array $entry): bool => $entry['action'] === $done);
        $this->settingsCursor = $still === false ? 0 : (int) $still;

        return $this;
    }

    public function continueWith(Action $done): static
    {
        $actions = $this->settingsActions();
        $next = collect($actions)->search(fn (array $entry): bool => $entry['action']->getName() === $done->getThen()
            && ($entry['action']->getAsk() !== null || count($entry['action']->getChoices($entry['settings']->getState()) ?? []) > 1));

        if ($next !== false) {
            $this->settingsCursor = $next;
            $this->startChoosing($actions[$next]['action'], $actions[$next]['settings']);
        }

        return $this;
    }

    /**
     * Open the settings on one action, already asking its question.
     */
    public function startSetting(string $name): static
    {
        $this->openSettings();

        $actions = $this->settingsActions();
        $at = collect($actions)->search(fn (array $entry): bool => $entry['action']->getName() === $name);

        if ($at === false) {
            return $this;
        }

        $this->settingsCursor = (int) $at;
        $this->startChoosing($actions[$at]['action'], $actions[$at]['settings']);

        return $this;
    }

    /**
     * Open the settings with one action chosen, without asking its question yet.
     */
    public function focusSetting(string $name): static
    {
        $this->openSettings();

        $at = collect($this->settingsActions())->search(fn (array $entry): bool => $entry['action']->getName() === $name);

        $at === false || $this->settingsCursor = (int) $at;

        return $this;
    }

    public function toggleSettings(): static
    {
        return $this->settingsOpen && $this->panel === null ? $this->closeSettings() : $this->openSettings();
    }

    public function refreshSettings(): static
    {
        collect($this->activeSettings())->each(fn (Settings $group): Settings => $group->refreshState());

        return $this;
    }

    public function refreshPanel(): static
    {
        return $this->showsPanel() ? $this->refreshSettings() : $this;
    }

    public function moveInSettings(int $step): static
    {
        $choices = count($this->choicesNow() ?? []);
        $actions = count($this->settingsActions());

        match (true) {
            $this->settingsChoosing !== null && $choices > 0 => $this->settingsChoice = ($this->settingsChoice + $step + $choices) % $choices,
            $this->settingsChoosing === null && $actions > 0 => $this->settingsCursor = ($this->settingsCursor + $step + $actions) % $actions,
            default => null,
        };

        return $this;
    }

    /**
     * @return array{0: Action, 1: ?string}|null
     */
    public function selectInSettings(): ?array
    {
        $selected = $this->selectedSetting();

        if ($selected === null) {
            return null;
        }

        return $this->settingsChoosing === null
            ? $this->startChoosing($selected['action'], $selected['settings'])
            : $this->chosen($selected['action'], $selected['settings']);
    }

    public function hasSettingAt(int $column, int $row): bool
    {
        return $this->settingAt($column, $row) !== null;
    }

    /**
     * @return array{0: Action, 1: ?string}|null
     */
    public function clickInSettings(int $column, int $row): ?array
    {
        $target = $this->settingAt($column, $row);

        if ($target === null) {
            return null;
        }

        $alreadyOpen = $this->settingsChoosing !== null && $this->settingsCursor === $target['action'];

        if ($target['choice'] === null) {
            $this->stopChoosing();
            $this->settingsCursor = $target['action'];

            return $alreadyOpen ? null : $this->selectInSettings();
        }

        $this->settingsChoice = $target['choice'];

        return $this->selectInSettings();
    }

    public function backInSettings(): static
    {
        return $this->settingsChoosing === null ? $this->closeSettings() : $this->stopChoosing();
    }

    /**
     * @return array{0: Action, 1: ?string}|null
     */
    private function startChoosing(Action $action, Settings $group): ?array
    {
        if ($action->getAsk() === null && $action->getChoices($group->getState()) === null) {
            return [$action, null];
        }

        $this->settingsChoosing = $action->getName();
        $this->settingsChoice = 0;
        $this->settingsInput = $action->getAsk() === null ? null : '';

        return null;
    }

    /**
     * @return array{0: Action, 1: ?string}|null
     */
    private function chosen(Action $action, Settings $group): ?array
    {
        return $action->getAsk() === null ? $this->picked($action, $group) : $this->answered($action);
    }

    /**
     * @return array{0: Action, 1: ?string}|null
     */
    private function picked(Action $action, Settings $group): ?array
    {
        $choice = array_keys($action->getChoices($group->getState()) ?? [])[$this->settingsChoice] ?? null;
        $this->stopChoosing();

        return match (true) {
            $choice === null, $action->getConfirmation() !== null && $choice === Action::CANCEL => null,
            $action->getConfirmation() !== null => [$action, null],
            default => [$action, (string) $choice],
        };
    }

    /**
     * @return array{0: Action, 1: string}|null
     */
    private function answered(Action $action): ?array
    {
        $answer = trim((string) $this->settingsInput);

        if ($answer === '' && ! $action->isOptional()) {
            return null;
        }

        $this->stopChoosing();

        return [$action, $answer];
    }

    private function stopChoosing(): static
    {
        $this->settingsChoosing = null;
        $this->settingsChoice = 0;
        $this->settingsInput = null;

        return $this;
    }

    /**
     * @return array<string, string>|null
     */
    private function choicesNow(): ?array
    {
        $selected = $this->selectedSetting();

        return $selected === null ? null : $selected['action']->getChoices($selected['settings']->getState());
    }

    /**
     * @return list<array{settings: Settings, action: Action}>
     */
    private function settingsActions(): array
    {
        return array_values(collect($this->activeSettings())
            ->flatMap(fn (Settings $group): array => array_map(fn (Action $action): array => ['settings' => $group, 'action' => $action], $group->visibleActions()))
            ->all());
    }

    /**
     * @return list<Settings>
     */
    private function activeSettings(): array
    {
        return $this->panel ?? $this->settings;
    }

    /**
     * @return array{action: int, choice: ?int, from: int, to: int}|null
     */
    private function settingAt(int $column, int $row): ?array
    {
        $target = $this->settingsOpen ? ($this->settingsTargets[$row] ?? null) : null;

        return $target !== null && $column >= $target['from'] && $column <= $target['to'] ? $target : null;
    }

    /**
     * @return array{settings: Settings, action: Action}|null
     */
    private function selectedSetting(): ?array
    {
        $actions = $this->settingsActions();

        return $actions === [] ? null : $actions[min($this->settingsCursor, count($actions) - 1)];
    }
}
