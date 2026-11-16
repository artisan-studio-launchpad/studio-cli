<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Concerns;

use ArtisanStudio\StudioCli\AvatarPlayer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

trait HasSwiftContainer
{
    private const string CONTAINER_POSITION = 'studio-cli.presence.position';

    private mixed $containerProcess = null;

    private mixed $containerInput = null;

    private mixed $containerOutput = null;

    private string $containerHeard = '';

    public function containerIsAvailable(): bool
    {
        return config('studio-cli.presence.enabled', true)
            && PHP_OS_FAMILY === 'Darwin'
            && is_executable($this->containerBinary());
    }

    public function closeContainer(): void
    {
        $this->containerMessages();

        if (is_resource($this->containerInput)) {
            fclose($this->containerInput);
        }

        if (is_resource($this->containerOutput)) {
            fclose($this->containerOutput);
        }

        if (is_resource($this->containerProcess)) {
            proc_close($this->containerProcess);
        }

        $this->containerInput = null;
        $this->containerOutput = null;
        $this->containerProcess = null;
        $this->containerHeard = '';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function sendToContainer(array $message): void
    {
        $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if ($this->containerIsUp()) {
            fwrite($this->containerInput, $line);

            return;
        }

        $this->openContainer($line);
    }

    protected function containerIsUp(): bool
    {
        if (! is_resource($this->containerProcess)) {
            return false;
        }

        return proc_get_status($this->containerProcess)['running'];
    }

    protected function containerHasOutput(): bool
    {
        return $this->containerMessages() !== [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function containerSettings(): array
    {
        return [
            'size' => $this->containerHeight(),
            'position' => $this->containerPosition(),
            'margin' => max(0, (int) config('studio-cli.presence.window.margin', 24)),
            'draggable' => (bool) config('studio-cli.presence.window.draggable', true),
            'always_on_top' => (bool) config('studio-cli.presence.window.always_on_top', true),
            'follow_terminal' => (bool) config('studio-cli.presence.window.follow_terminal', true),
            'follow_every_seconds' => (float) config('studio-cli.presence.window.follow_every_seconds', 1.0),
            'hide_when_away' => (bool) config('studio-cli.presence.window.hide_when_away', true),
            'terminal_min_width' => (int) config('studio-cli.presence.window.terminal_min_width', 400),
            'terminal_min_height' => (int) config('studio-cli.presence.window.terminal_min_height', 300),
            'wait_for_loop' => (bool) config('studio-cli.presence.playback.wait_for_loop', true),
            'swap_seconds' => (float) config('studio-cli.presence.playback.swap_seconds', 0.08),
            'preload' => $this->containerPreload(),
        ];
    }

    protected function containerPosition(): float
    {
        $remembered = config('studio-cli.presence.window.remember_where_dragged', true) ? Cache::get(self::CONTAINER_POSITION) : null;
        $position = strtolower(trim((string) config('studio-cli.presence.window.position', 'center')));

        return match (true) {
            is_numeric($remembered) => min(1.0, max(0.0, (float) $remembered)),
            $position === 'left' => 0.0,
            $position === 'right' => 1.0,
            preg_match('/^(\d+(?:\.\d+)?)%$/', $position, $percent) === 1 => min(1.0, max(0.0, (float) $percent[1] / 100)),
            default => 0.5,
        };
    }

    /**
     * @return list<string>
     */
    protected function containerPreload(): array
    {
        return [];
    }

    protected function containerHeight(): int
    {
        $wanted = (int) config('studio-cli.presence.size', 360);

        return max(120, min(900, $wanted));
    }

    protected function containerBinary(): string
    {
        return AvatarPlayer::path();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function containerMessages(): array
    {
        if (! is_resource($this->containerOutput)) {
            return [];
        }

        $this->containerHeard .= (string) fread($this->containerOutput, 8192);
        $lines = explode("\n", $this->containerHeard);
        $this->containerHeard = (string) array_pop($lines);

        return array_values(array_map(
            fn (string $line): array => $this->containerHeardThat(trim($line)),
            array_filter($lines, fn (string $line): bool => trim($line) !== ''),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function containerHeardThat(string $line): array
    {
        $message = json_decode($line, true);
        $message = is_array($message) ? $message : ['said' => $line];

        if (isset($message['moved']) && is_numeric($message['moved']) && config('studio-cli.presence.window.remember_where_dragged', true)) {
            Cache::forever(self::CONTAINER_POSITION, min(1.0, max(0.0, (float) $message['moved'])));
        }

        return $message;
    }

    private function openContainer(string $firstLine): void
    {
        $this->clearStrayContainers();

        $process = proc_open(
            [$this->containerBinary(), json_encode($this->containerSettings(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return;
        }

        $this->containerProcess = $process;
        $this->containerInput = $pipes[0];
        $this->containerOutput = $pipes[1];
        $this->containerHeard = '';

        stream_set_blocking($this->containerOutput, false);

        fwrite($this->containerInput, $firstLine);
    }

    private function clearStrayContainers(): void
    {
        Process::run(['pkill', '-f', $this->containerBinary()]);
    }
}
