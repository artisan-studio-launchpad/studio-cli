<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use ArtisanStudio\StudioCli\StateFile;
use Illuminate\Support\Carbon;

final class ScanProgress
{
    public const int EVERY = 50;

    private const int STALE_AFTER_SECONDS = 60;

    public function __construct(private readonly string $root) {}

    public function progressed(int $done, int $total): void
    {
        if ($done % self::EVERY === 0 || $done === $total) {
            $this->write(['done' => $done, 'total' => $total, 'at' => Carbon::now()->toIso8601String()]);
        }
    }

    public function finished(): void
    {
        @unlink($this->path());
    }

    /**
     * @return array{done: int, total: int, at: string}|null
     */
    public function read(): ?array
    {
        $json = is_file($this->path()) ? file_get_contents($this->path()) : false;
        $progress = $json === false ? null : json_decode($json, true);

        return is_array($progress)
            && isset($progress['done'], $progress['total'], $progress['at'])
            && Carbon::parse($progress['at'])->diffInSeconds(Carbon::now()) < self::STALE_AFTER_SECONDS
                ? $progress
                : null;
    }

    public function label(): ?string
    {
        return $this->read() === null ? null : "Reading this project's files locally";
    }

    public function fraction(): ?float
    {
        $progress = $this->read();

        return $progress === null ? null : ($progress['total'] > 0 ? $progress['done'] / $progress['total'] : 0.0);
    }

    public function path(): string
    {
        return StateFile::for('scan', $this->root);
    }

    /**
     * @param  array{done: int, total: int, at: string}  $progress
     */
    private function write(array $progress): void
    {
        @file_put_contents($this->path(), (string) json_encode($progress), LOCK_EX);
    }
}
