<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Settings;
use Closure;

/**
 * What the setup screen and the studio screen say about the AI bridge, and
 * what picking yes or no there does.
 */
final class BridgeSetup
{
    public const string YES = 'yes';

    public const string NO = 'no';

    private const string MARKER = "\u{25C9}";

    public function __construct(
        private readonly BridgeConsent $consent,
        private readonly ClaudeAccount $account,
        private readonly BridgeKeeper $keeper,
        private readonly BridgeGate $gate,
        private readonly BridgeDetection $detection,
        private readonly Runners $runners,
    ) {}

    /**
     * Whether the developer has not said yes or no yet.
     */
    public function isUndecided(): bool
    {
        return $this->consent->choice() === null;
    }

    /**
     * What is missing for a runner to run the bridge, or null when one is
     * installed and signed in.
     */
    public function notReady(): ?string
    {
        return match (true) {
            $this->runners->hasReady() => null,
            $this->runners->hasInstalled() => __('studio-cli::bridge.setup.not_signed_in'),
            default => __('studio-cli::bridge.setup.not_installed'),
        };
    }

    /**
     * The choices the setup screen offers.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        return [self::YES => __('studio-cli::bridge.consent.yes'), self::NO => __('studio-cli::bridge.consent.no')];
    }

    /**
     * Applies the developer's choice and says what happened.
     */
    public function choose(string $choice): string
    {
        if ($choice !== self::YES) {
            $this->keeper->refuse();

            return __('studio-cli::bridge.consent.refused');
        }

        $missing = $this->notReady();

        if ($missing !== null) {
            return __('studio-cli::bridge.consent.not_ready', ['reason' => $missing]);
        }

        $this->keeper->allow();

        return __('studio-cli::bridge.consent.allowed');
    }

    /**
     * The row of the settings screen.
     *
     * @return array{label: string, value: string, status: string, colour: string}
     */
    public function row(): array
    {
        $missing = $this->notReady();
        $who = $this->account->who();

        return match (true) {
            $this->consent->choice() === true => ['label' => __('studio-cli::bridge.status.row'), 'value' => $who === null ? __('studio-cli::bridge.status.value.on') : __('studio-cli::bridge.status.value.on_as', ['who' => $who]), 'status' => $this->keeper->isRunning() ? __('studio-cli::bridge.status.running_short') : __('studio-cli::bridge.status.on'), 'colour' => 'green'],
            $this->consent->choice() === false => ['label' => __('studio-cli::bridge.status.row'), 'value' => __('studio-cli::bridge.status.value.off'), 'status' => __('studio-cli::bridge.status.off'), 'colour' => 'dim'],
            $missing !== null => ['label' => __('studio-cli::bridge.status.row'), 'value' => $missing, 'status' => __('studio-cli::bridge.status.off'), 'colour' => 'amber'],
            default => ['label' => __('studio-cli::bridge.status.row'), 'value' => $who === null ? __('studio-cli::bridge.status.value.undecided') : __('studio-cli::bridge.status.value.undecided_as', ['who' => $who]), 'status' => __('studio-cli::bridge.status.asks'), 'colour' => 'amber'],
        };
    }

    /**
     * The question the setup screen asks, with the account Claude Code is
     * signed in as when it can say, so the developer sees whose subscription
     * a yes would use.
     */
    public function question(): string
    {
        $who = $this->account->who();

        return __('studio-cli::bridge.consent.question').($who === null ? '' : "\n".__('studio-cli::bridge.consent.account', ['who' => $who]));
    }

    /**
     * The bridge's state at the right end of the key hints: a plain marker, one
     * column wide in any monospace font, and the state in words.
     *
     * @return array{text: string, colour: string}
     */
    public function footer(): array
    {
        $status = $this->gate->status();

        return [
            'text' => self::MARKER.' '.__('studio-cli::bridge.cli.bridge.'.$status),
            'colour' => match ($status) {
                BridgeGate::ON, BridgeGate::KEY => 'green',
                BridgeGate::ATTENTION => 'amber',
                default => 'dim',
            },
        ];
    }

    /**
     * What the state means and what to do about it, for the bridge section of
     * the settings.
     */
    public function explain(): string
    {
        return __('studio-cli::bridge.cli.explain.'.$this->detection->state());
    }

    /**
     * What is missing when the developer has not decided and no runner can
     * run the bridge, so the setup says so once and the studio goes on.
     */
    public function missing(): ?string
    {
        return $this->isUndecided() ? $this->notReady() : null;
    }

    /**
     * Whether neither a runner nor an API key is there, so the screen shows
     * the two ways to get one.
     */
    public function isBlocked(): bool
    {
        return $this->detection->isBlocked();
    }

    /**
     * The block screen as a panel, for when the studio opens with no AI
     * connected.
     */
    public function blockPanel(): Settings
    {
        return Settings::make(__('studio-cli::bridge.block.title'))
            ->components($this->blockTexts())
            ->actions([Action::make('understood')->label(__('studio-cli::bridge.block.understood'))->closes()->action(fn (): ?string => null)]);
    }

    /**
     * The two ways forward, as lines of text that say nothing while a runner
     * or a key is there: the recommended one, a runner on this machine, then
     * an API key saved in the app, then what is not supported.
     *
     * @param  (Closure(mixed): bool)|null  $visible  asked with the screen's state; null always shows
     * @return list<Text>
     */
    public function blockTexts(?Closure $visible = null): array
    {
        $lines = fn (): array => $this->blockLines();

        return array_map(
            fn (int $index): Text => Text::make(fn (mixed $state): string => $this->isBlocked() && ($visible === null || $visible($state)) ? ($lines()[$index]['text'] ?? '') : '')
                ->colour(fn (): string => $lines()[$index]['colour'] ?? 'soft')
                ->bold(($lines()[$index]['bold'] ?? false) === true)
                ->wrap(),
            array_keys($this->blockLines()),
        );
    }

    /**
     * @return list<array{text: string, colour: string, bold?: bool}>
     */
    private function blockLines(): array
    {
        $names = $this->runnerNames();

        return [
            ['text' => __('studio-cli::bridge.block.lead'), 'colour' => 'amber', 'bold' => true],
            ['text' => __('studio-cli::bridge.block.recommended', ['runners' => $names]), 'colour' => 'cyan', 'bold' => true],
            ...array_map(
                fn (string $runner, array $status): array => ['text' => $this->runnerLine($runner, $status), 'colour' => 'soft'],
                array_keys($this->runners->statuses()),
                array_values($this->runners->statuses()),
            ),
            ['text' => __('studio-cli::bridge.block.reopen'), 'colour' => 'soft'],
            ['text' => __('studio-cli::bridge.block.key_title'), 'colour' => 'cyan', 'bold' => true],
            ['text' => __('studio-cli::bridge.block.key_cost'), 'colour' => 'soft'],
            ['text' => __('studio-cli::bridge.block.key_where', ['url' => rtrim((string) config('studio-cli.url'), '/').'/settings/claude']), 'colour' => 'soft'],
            ['text' => __('studio-cli::bridge.block.key_route'), 'colour' => 'soft'],
            ['text' => __('studio-cli::bridge.block.unsupported'), 'colour' => 'dim'],
            ...($this->runners->isComing(Runners::CODEX) ? [['text' => __('studio-cli::bridge.block.coming', ['runner' => __('studio-cli::bridge.runner.names.'.Runners::CODEX)]), 'colour' => 'dim']] : []),
        ];
    }

    /**
     * @param  array{installed: bool, signed_in: bool}  $status
     */
    private function runnerLine(string $runner, array $status): string
    {
        return __('studio-cli::bridge.block.runner.'.($status['installed'] ? 'signed_out' : 'not_installed'), [
            'runner' => __('studio-cli::bridge.runner.names.'.$runner),
            'command' => __('studio-cli::bridge.runner.commands.'.$runner),
        ]);
    }

    /**
     * The enabled runners by name, for a sentence: Claude Code, or Claude Code
     * or Codex.
     */
    private function runnerNames(): string
    {
        return implode(' '.__('studio-cli::bridge.runner.or').' ', array_map(fn (string $runner): string => __('studio-cli::bridge.runner.names.'.$runner), $this->runners->enabled()));
    }
}
