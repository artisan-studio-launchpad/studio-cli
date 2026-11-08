<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Contracts\HasScreen;
use ArtisanStudio\StudioCli\Terminal\Contracts\RunsInBackground;
use ArtisanStudio\StudioCli\Terminal\ScreenContainer;
use ArtisanStudio\StudioCli\Terminal\ScreenRequests;
use ArtisanStudio\StudioCli\Terminal\Theme;
use ArtisanStudio\StudioCli\Terminal\VendorWatch;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Laravel\Prompts\Terminal;
use Symfony\Component\Console\Input\InputOption;

/**
 * @phpstan-require-implements HasScreen
 */
trait InteractsWithScreen
{
    private const array SCREEN_KEYS = [
        'q' => 'quit',
        'Q' => 'quit',
        "\x03" => 'quit',
        'r' => 'refresh',
        'R' => 'refresh',
        "\e[C" => 'next',
        "\t" => 'next',
        "\e[D" => 'previous',
        "\e[Z" => 'previous',
        'o' => 'open',
        'O' => 'open',
        '?' => 'help',
        "\e[A" => 'scroll:-1',
        'k' => 'scroll:-1',
        "\e[B" => 'scroll:1',
        'j' => 'scroll:1',
        "\e[5~" => 'page-up',
        "\e[6~" => 'page-down',
        ' ' => 'page-down',
        'g' => 'top',
        'G' => 'bottom',
        's' => 'settings',
        'S' => 'settings',
    ];

    private const array SCREEN_SETTINGS_KEYS = [
        "\e[A" => 'settings:up',
        'k' => 'settings:up',
        "\e[B" => 'settings:down',
        'j' => 'settings:down',
        "\n" => 'settings:select',
        "\r" => 'settings:select',
        "\e" => 'settings:back',
    ];

    private const array SCREEN_ROW_KEYS = [
        "\e[A" => 'row:up',
        'k' => 'row:up',
        "\e[B" => 'row:down',
        'j' => 'row:down',
        "\n" => 'row:open',
        "\r" => 'row:open',
    ];

    private const array SCREEN_TYPING_KEYS = [
        "\n" => 'settings:select',
        "\r" => 'settings:select',
        "\e" => 'settings:back',
        "\x7f" => 'settings:erase',
        "\x08" => 'settings:erase',
        "\x03" => 'quit',
    ];

    private const int SCREEN_FLASH_SECONDS = 3;

    private const int SCREEN_WHEEL_LINES = 3;

    private const int SCREEN_TICK_MILLISECONDS = 250;

    private const string SCREEN_MOUSE_ON = "\e[?1000h\e[?1006h";

    private const string SCREEN_MOUSE_OFF = "\e[?1000l\e[?1006l";

    private ?ScreenContainer $screenContainer = null;

    private ?string $screenTab = null;

    private int $screenScroll = 0;

    private int $screenRowsNow = 24;

    private int $screenWidthNow = 80;

    private bool $screenHelp = false;

    /**
     * @var list<string>
     */
    private array $screenLines = [];

    private bool $screenQuit = false;

    private ?string $screenFlash = null;

    private int $screenFlashUntil = 0;

    /**
     * @var list<RunsInBackground>
     */
    private array $screenRunners = [];

    protected function showScreen(?string $tab = null): int
    {
        $this->screenTab = $tab === ScreenContainer::SETTINGS ? null : $tab;

        if ($tab === ScreenContainer::SETTINGS) {
            $this->getScreen()->openSettings();
        }

        return match (true) {
            (bool) $this->option('tab') => $this->streamScreenTab(),
            $this->option('once') || ! $this->canTakeOverTheTerminal() => $this->drawScreenOnce(),
            default => $this->runScreen(),
        };
    }

    protected function getScreen(): ScreenContainer
    {
        $theme = Theme::mode(config('studio-cli.theme.mode'));

        return $this->screenContainer ??= $this->screen(ScreenContainer::make()
            ->trueColour(getenv('TERM_PROGRAM') !== 'Apple_Terminal' || (new Terminal)->supportsTrueColor())
            ->emoji(getenv('TERMINAL_EMULATOR') !== 'JetBrains-JediTerm')
            ->theme($theme)
            ->background($this->screenBackground($theme))
            ->palette((array) config("studio-cli.theme.{$theme}.colours", []))
            ->commandName((string) $this->getName()));
    }

    protected function screenRefreshSeconds(): int
    {
        return 2;
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Draw one frame and exit');
        $this->addOption('tab', null, InputOption::VALUE_NONE, 'Run as a tab inside `php artisan dev`, redrawing when something changes');
        $this->addOption('width', null, InputOption::VALUE_REQUIRED, 'Columns to draw with --once');
        $this->addOption('height', null, InputOption::VALUE_REQUIRED, 'Rows to draw with --once');
    }

    protected function runScreen(): int
    {
        $terminal = new Terminal;
        $screen = $this->getScreen();
        $this->screenRunners = $screen->runners();
        $terminal->setTty('-icanon -isig -echo');
        $this->redrawOnResize();
        $this->quitOnHangUp();
        $this->output->write("\e[?1049h\e[?25l".self::SCREEN_MOUSE_ON.$screen->takeOver());
        $vendor = new VendorWatch(base_path());
        $restart = false;

        try {
            array_map(fn (RunsInBackground $runner) => $runner->start(), $this->screenRunners);
            $refreshedAt = time();
            $drawnAt = [0, 0];
            $shown = '';

            while (! $this->screenQuit) {
                [$rows, $columns] = $this->screenSize();
                $this->screenRowsNow = $rows;
                $this->screenWidthNow = $columns - 1;
                $this->screenScroll = max(0, min($this->screenScroll, $screen->scrollLimit($columns - 1, $rows, $this->screenTab, $this->screenHelp)));
                $frame = $this->screenFrame($screen, $rows, $columns);

                if ($frame !== $shown || $drawnAt !== [$rows, $columns]) {
                    $this->output->write(($drawnAt === [$rows, $columns] ? "\e[H" : $screen->takeOver()).$frame);
                    $drawnAt = [$rows, $columns];
                    $shown = $frame;
                }

                $action = $this->screenActionFor($this->readScreenKey(self::SCREEN_TICK_MILLISECONDS));

                if ($action === 'quit') {
                    $this->screenQuit = true;
                }

                $this->handleScreenAction($action);
                array_map(fn (RunsInBackground $runner) => $runner->tick(), $this->screenRunners);
                $this->tickTheScreen();
                $this->followScreenRequests();
                $screen->refreshRunningTabs();

                if ($action === 'refresh' || time() - $refreshedAt >= $this->screenRefreshSeconds()) {
                    $screen->refreshState();
                    $refreshedAt = time();
                }

                if ($vendor->hasChanged()) {
                    $restart = true;
                    $this->screenQuit = true;
                }
            }
        } finally {
            array_map(fn (RunsInBackground $runner) => $runner->stop(), $this->screenRunners);
            $this->output->write(self::SCREEN_MOUSE_OFF."\e[?25h\e[?1049l");
            $terminal->restoreTty();
            $this->stopRedrawingOnResize();
        }

        if ($restart) {
            $this->restartScreen();
        }

        return self::SUCCESS;
    }

    /**
     * Starts the same command again in this process, on the new packages.
     */
    private function restartScreen(): void
    {
        $arguments = array_values(array_filter((array) ($_SERVER['argv'] ?? []), is_string(...)));

        $this->screenEnding();

        if (function_exists('pcntl_exec') && $arguments !== []) {
            @pcntl_exec(PHP_BINARY, $arguments);
        }

        $this->output->writeln('Your packages changed, so the studio needs a fresh start: run php artisan studio again.');
    }

    protected function streamScreenTab(): never
    {
        $screen = $this->getScreen();
        $runners = $screen->runners();
        array_map(fn (RunsInBackground $runner) => $runner->start(), $runners);
        $refreshedAt = 0;
        $shown = null;

        while (true) {
            array_map(fn (RunsInBackground $runner) => $runner->tick(), $runners);
            $this->tickTheScreen();
            $screen->refreshRunningTabs();

            if (time() - $refreshedAt >= $this->screenRefreshSeconds()) {
                $screen->refreshState();
                $refreshedAt = time();
            }

            if ($screen->fingerprint() !== $shown) {
                $this->output->write($screen->takeOver().$screen->render($this->screenColumns(), $this->screenRows(), $this->screenTab, interactive: false).$screen->clearBelow());
                $shown = $screen->fingerprint();
            }

            Sleep::for(self::SCREEN_TICK_MILLISECONDS)->milliseconds();
        }
    }

    protected function drawScreenOnce(): int
    {
        $width = $this->option('width');
        $height = $this->option('height');

        $this->output->writeln($this->getScreen()->render(
            $width === null ? $this->screenColumns() : (int) $width,
            $height === null ? $this->screenRows() : (int) $height,
            $this->screenTab,
        ));

        return self::SUCCESS;
    }

    protected function screenBackground(string $theme): ?string
    {
        $background = ltrim((string) config("studio-cli.theme.{$theme}.background"), '#');

        return match (true) {
            $background === 'terminal' => null,
            preg_match('/^[0-9a-fA-F]{6}$/', $background) === 1 => strtolower($background),
            default => Theme::background($theme),
        };
    }

    protected function canTakeOverTheTerminal(): bool
    {
        return $this->input->isInteractive() && stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    protected function screenColumns(): int
    {
        return (new Terminal)->cols();
    }

    protected function screenRows(): int
    {
        return max(24, (new Terminal)->lines());
    }

    private function handleScreenAction(?string $action): void
    {
        match (true) {
            $action === null => null,
            in_array($action, ['next', 'previous'], true) || str_starts_with($action, 'tab:') => $this->getScreen()->showsTabs() ? $this->showScreenTab(match ($action) {
                'next' => $this->screenTabAfter(1),
                'previous' => $this->screenTabAfter(-1),
                default => substr($action, 4),
            }) : null,
            str_starts_with($action, 'row:') => $this->browseScreenTab(substr($action, 4)),
            str_starts_with($action, 'rail-scroll:') => $this->getScreen()->scrollRail((int) substr($action, 12)),
            str_starts_with($action, 'scroll:') => $this->scrollScreen((int) substr($action, 7)),
            str_starts_with($action, 'click:') => $this->clickScreenAt(...array_map(intval(...), explode(':', substr($action, 6)))),
            $action === 'page-up' => $this->scrollScreen(-$this->screenPage()),
            $action === 'page-down' => $this->scrollScreen($this->screenPage()),
            $action === 'top' => $this->screenScroll = 0,
            $action === 'bottom' => $this->screenScroll = PHP_INT_MAX,
            $action === 'help' => $this->toggleScreenHelp(),
            $action === 'open' => $this->openScreenUrl(),
            $action === 'refresh' => $this->refreshScreenNow(),
            str_starts_with($action, 'settings:type:') => $this->getScreen()->typeInSettings(substr($action, 14)),
            $action === 'settings:erase' => $this->getScreen()->eraseInSettings(),
            $action === 'settings' => $this->toggleScreenSettings(),
            $action === 'bridge' => $this->openScreenBridge(),
            $action === 'settings:up' => $this->getScreen()->moveInSettings(-1),
            $action === 'settings:down' => $this->getScreen()->moveInSettings(1),
            $action === 'settings:select' => $this->runScreenSetting($this->getScreen()->selectInSettings()),
            $action === 'settings:back' => $this->getScreen()->backInSettings(),
            default => null,
        };
    }

    private function screenActionFor(string $input): ?string
    {
        $key = $input === "\r\n" ? "\n" : $input;

        return $this->getScreen()->isTypingInSettings() ? $this->typingActionFor($key) : $this->keyActionFor($key);
    }

    private function typingActionFor(string $input): ?string
    {
        $text = (string) preg_replace('/\e\[20[01]~/', '', $input);
        $typed = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $text);

        return match (true) {
            isset(self::SCREEN_TYPING_KEYS[$input]) => self::SCREEN_TYPING_KEYS[$input],
            str_starts_with($text, "\e"), $typed === '' => null,
            default => 'settings:type:'.$typed,
        };
    }

    private function keyActionFor(string $input): ?string
    {
        $wheel = preg_match_all('/\e\[<(64|65);(\d+);\d+[Mm]/', $input, $events);
        $tabs = $this->getScreen()->tabKeys();
        $current = $this->getScreen()->tab($this->screenTab);
        $browsing = ! $this->screenHelp && ! $this->getScreen()->settingsAreOpen();
        $lines = collect($events[1])->sum(fn (string $button): int => $button === '64' ? -self::SCREEN_WHEEL_LINES : self::SCREEN_WHEEL_LINES);

        return match (true) {
            $wheel > 0 && $this->getScreen()->railAt($this->screenWidthNow, (int) $events[2][0]) => 'rail-scroll:'.$lines,
            $wheel > 0 => 'scroll:'.$lines,
            preg_match('/\e\[<0;(\d+);(\d+)M/', $input, $click) === 1 => "click:{$click[1]}:{$click[2]}",
            $this->getScreen()->settingsAreOpen() && isset(self::SCREEN_SETTINGS_KEYS[$input]) => self::SCREEN_SETTINGS_KEYS[$input],
            $browsing && $current->isNavigable() && isset(self::SCREEN_ROW_KEYS[$input]) => self::SCREEN_ROW_KEYS[$input],
            $browsing && in_array($input, ["\n", "\r"], true) && $current->canEnter() => 'row:enter',
            $browsing && $current->isOpen() && $input === "\e" => 'row:back',
            preg_match('/^[1-9]$/', $input) === 1 && isset($tabs[(int) $input - 1]) => 'tab:'.$tabs[(int) $input - 1],
            default => self::SCREEN_KEYS[$input] ?? null,
        };
    }

    protected function screenRefreshed(): void {}

    protected function screenTicked(): void {}

    /**
     * Called when the screen is about to end or start again, for the command
     * to put away whatever it started.
     */
    protected function screenEnding(): void {}

    private function tickTheScreen(): void
    {
        Container::getInstance()->make(BackgroundTasks::class)->tick();
        $this->screenTicked();
    }

    private function followScreenRequests(): void
    {
        $request = Container::getInstance()->make(ScreenRequests::class)->take();

        if ($request === null || ! in_array($request['tab'], $this->getScreen()->tabKeys(), true)) {
            return;
        }

        $this->showScreenTab($request['tab']);

        if ($request['then'] !== null) {
            ($request['then'])($this->getScreen()->tab($request['tab']));
            $this->getScreen()->resetRailScroll();
        }

        if ($request['enter']) {
            $this->getScreen()->openPanel($this->getScreen()->tab($request['tab'])->enter());
        }
    }

    private function openScreenRecord(int $record): void
    {
        $this->getScreen()->tab($this->screenTab)->select($record)->open();
        $this->getScreen()->resetRailScroll();
        $this->screenScroll = 0;
    }

    private function browseScreenTab(string $how): void
    {
        $tab = $this->getScreen()->tab($this->screenTab);

        match ($how) {
            'up' => $tab->move(-1),
            'down' => $tab->move(1),
            'open' => $tab->open(),
            'enter' => $this->getScreen()->openPanel($tab->enter()),
            default => $tab->close(),
        };

        $this->screenScroll = in_array($how, ['open', 'back'], true) ? 0 : $this->screenScroll;

        if (in_array($how, ['open', 'back'], true)) {
            $this->getScreen()->resetRailScroll();
        }
    }

    private function refreshScreenNow(): void
    {
        $this->screenRefreshed();
        $this->flashScreen('Refreshed');
    }

    private function toggleScreenSettings(): void
    {
        $this->getScreen()->toggleSettings();
        $this->screenHelp = false;
        $this->screenScroll = 0;
    }

    /**
     * The settings, from wherever the screen is, with the bridge row chosen.
     */
    private function openScreenBridge(): void
    {
        $this->getScreen()->focusSetting('bridge');
        $this->screenHelp = false;
        $this->screenScroll = 0;
    }

    /**
     * @param  array{0: Action, 1: ?string}|null  $chosen
     */
    private function runScreenSetting(?array $chosen): void
    {
        if ($chosen === null) {
            return;
        }

        [$action, $choice] = $chosen;
        $fromSettings = ! $this->getScreen()->showsPanel();
        $said = $action->run($choice);

        if ($fromSettings) {
            array_map(fn (RunsInBackground $runner) => $runner->stop(), $this->screenRunners);
            array_map(fn (RunsInBackground $runner) => $runner->start(), $this->screenRunners);
        }

        if ($action->shouldClose()) {
            $this->getScreen()->closeSettings();
        }

        if ($action->shouldGoBack()) {
            $this->browseScreenTab('back');
        }

        $this->getScreen()->refreshState()->refreshSettings()->settleAfter($action);

        if ($said !== null) {
            $this->flashScreen($said);
            $this->getScreen()->continueWith($action);
        }
    }

    private function scrollScreen(int $lines): void
    {
        $this->screenScroll = max(0, min(PHP_INT_MAX - $lines, $this->screenScroll) + $lines);
    }

    private function screenPage(): int
    {
        return max(1, intdiv($this->screenRowsNow, 2));
    }

    private function screenFrame(ScreenContainer $screen, int $rows, int $columns): string
    {
        $this->screenLines = $screen->lines($columns - 1, $rows, $this->screenTab, $this->screenHelp, $this->screenFlashNow(), scroll: $this->screenScroll);

        return implode($screen->eraseToEdge()."\n", $this->screenLines).$screen->eraseToEdge().$screen->clearBelow();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function screenSize(): array
    {
        $size = trim(Process::run('stty size < /dev/tty 2>/dev/null')->output());

        return preg_match('/^(\d+) (\d+)$/', $size, $measured) === 1 && (int) $measured[2] > 0
            ? [(int) $measured[1], (int) $measured[2]]
            : [$this->screenRows(), $this->screenColumns()];
    }

    private function redrawOnResize(): void
    {
        if (function_exists('pcntl_signal') && defined('SIGWINCH')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGWINCH, fn (): null => null);
        }
    }

    private function stopRedrawingOnResize(): void
    {
        if (function_exists('pcntl_signal') && defined('SIGWINCH')) {
            pcntl_signal(SIGWINCH, SIG_DFL);
        }
    }

    private function quitOnHangUp(): void
    {
        if (function_exists('pcntl_signal') && defined('SIGHUP') && defined('SIGTERM')) {
            pcntl_signal(SIGHUP, fn (): bool => $this->screenQuit = true);
            pcntl_signal(SIGTERM, fn (): bool => $this->screenQuit = true);
        }
    }

    private function toggleScreenHelp(): void
    {
        $this->screenHelp = ! $this->screenHelp;
        $this->screenScroll = 0;
        $this->getScreen()->closeSettings();
    }

    private function showScreenTab(string $tab): void
    {
        $this->screenTab = $tab;
        $this->screenHelp = false;
        $this->screenScroll = 0;
        $this->getScreen()->closeSettings()->resetRailScroll();
    }

    private function screenTabAfter(int $step): string
    {
        $tabs = $this->getScreen()->tabKeys();
        $at = (int) array_search($this->getScreen()->tab($this->screenTab)->getKey(), $tabs, true);

        return $tabs[($at + $step + count($tabs)) % count($tabs)];
    }

    private function clickScreenAt(int $column, int $row): void
    {
        $tab = $this->getScreen()->tabAt($this->screenWidthNow, $column, $row);
        $url = Canvas::linkAt($this->screenLines[$row - 1] ?? '', $column);

        match (true) {
            $tab !== null => $this->showScreenTab($tab),
            $this->getScreen()->settingsAt($this->screenWidthNow, $column, $row) => $this->toggleScreenSettings(),
            $this->getScreen()->hasSettingAt($column, $row) => $this->runScreenSetting($this->getScreen()->clickInSettings($column, $row)),
            $url !== null && str_starts_with($url, Canvas::ACTION) => $this->handleScreenAction(substr($url, strlen(Canvas::ACTION))),
            $url !== null => $this->openInBrowser($url),
            $this->getScreen()->recordAt($column, $row) !== null => $this->openScreenRecord((int) $this->getScreen()->recordAt($column, $row)),
            default => null,
        };
    }

    private function openScreenUrl(): void
    {
        $url = $this->getScreen()->getOpenUrl();

        if ($url !== null) {
            $this->openInBrowser($url);
        }
    }

    private function openInBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['xdg-open', $url],
        };

        Process::run($command);
        $this->flashScreen("Opened {$url}");
    }

    private function flashScreen(string $message): void
    {
        $this->screenFlash = $message;
        $this->screenFlashUntil = time() + self::SCREEN_FLASH_SECONDS;
    }

    private function screenFlashNow(): ?string
    {
        return time() < $this->screenFlashUntil ? $this->screenFlash : null;
    }

    private function readScreenKey(int $milliseconds): string
    {
        $read = [STDIN];
        $write = null;
        $except = null;

        return @stream_select($read, $write, $except, 0, $milliseconds * 1000) > 0 ? (string) fread(STDIN, 256) : '';
    }
}
