<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

readonly class DashboardSnapshot
{
    public const string NOT_SCANNED = 'Not scanned yet';

    private const int SAMPLE_FILES = 2172;

    private const int SAMPLE_SCAN_SECONDS = 90;

    /**
     * @param  list<array{name: string, open: int}>  $rulesets
     * @param  list<string>  $fixesAsked
     * @param  list<array{id: int, job: string, name: string, mode: string, files: int, state: string}>  $workAsked
     * @param  list<string>  $filesWanted
     * @param  array{session: int, open: ?string, watch: list<string>}|null  $workTogether
     * @param  list<array{id: string, pattern: string, in: ?string}>  $searchesWanted
     */
    public function __construct(
        public string $project,
        public ?string $repository,
        public int $health,
        public string $healthLabel,
        public int $insightsOpen,
        public string $scanStatus,
        public int $scanFilesDone,
        public int $scanFilesTotal,
        public ?string $scannedAgo,
        public array $rulesets,
        public ?string $insightsUrl,
        public Carbon $takenAt,
        public bool $healthScored = true,
        public array $fixesAsked = [],
        public array $workAsked = [],
        public int $workRechecks = 0,
        public int $workSends = 0,
        public array $filesWanted = [],
        public ?array $workTogether = null,
        public array $searchesWanted = [],
        public ?string $bridgeAsked = null,
    ) {}

    public static function fresh(?Carbon $at = null): self
    {
        return new self(
            project: (string) config('app.name', 'Your project'),
            repository: null,
            health: 0,
            healthLabel: 'Not scanned',
            insightsOpen: 0,
            scanStatus: self::NOT_SCANNED,
            scanFilesDone: 0,
            scanFilesTotal: 0,
            scannedAgo: null,
            rulesets: [],
            insightsUrl: null,
            takenAt: $at ?? Carbon::now(),
            healthScored: false,
        );
    }

    public static function sample(?Carbon $at = null, ?string $studio = null): self
    {
        $at ??= Carbon::now();
        $studio = rtrim($studio ?? (string) config('studio-cli.url'), '/');
        $progress = ($at->getTimestamp() % self::SAMPLE_SCAN_SECONDS) / self::SAMPLE_SCAN_SECONDS;
        $filesDone = (int) round($progress * self::SAMPLE_FILES);
        $found = (int) round($progress * 41);

        return new self(
            project: 'Artisan Studio',
            repository: 'artisan-studio-projects/artisan-studio',
            health: 68,
            healthLabel: 'Fair',
            insightsOpen: 36 + $found,
            scanStatus: 'Scanning',
            scanFilesDone: $filesDone,
            scanFilesTotal: self::SAMPLE_FILES,
            scannedAgo: null,
            rulesets: [
                ['name' => 'Security Vulnerabilities', 'open' => 4 + intdiv($found, 8)],
                ['name' => 'Performance Bottlenecks', 'open' => 9 + intdiv($found, 4)],
                ['name' => 'Code Quality', 'open' => 14 + intdiv($found, 3)],
                ['name' => 'Model Coverage', 'open' => 2],
                ['name' => 'Test Coverage Gaps', 'open' => 7],
                ['name' => 'Laravel Best Practices', 'open' => 0],
                ['name' => 'Livewire Components', 'open' => 5],
            ],
            insightsUrl: "{$studio}/insights",
            takenAt: $at,
        );
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromApi(array $data, ?Carbon $at = null): self
    {
        $at ??= Carbon::now();
        $scannedAt = data_get($data, 'scan.scanned_at');

        return new self(
            project: (string) data_get($data, 'project.name', config('app.name', 'Your project')),
            repository: is_string($repository = data_get($data, 'project.repository')) && $repository !== '' ? $repository : null,
            health: (int) data_get($data, 'health.percent', 0),
            healthLabel: (string) data_get($data, 'health.label', 'Not scanned'),
            insightsOpen: (int) data_get($data, 'health.open', 0),
            scanStatus: (string) data_get($data, 'scan.status', self::NOT_SCANNED),
            scanFilesDone: (int) data_get($data, 'scan.files_done', 0),
            scanFilesTotal: (int) data_get($data, 'scan.files_total', 0),
            scannedAgo: is_string($scannedAt) ? self::ago($scannedAt, $at).' ago' : null,
            rulesets: array_values(collect(array_filter((array) data_get($data, 'insights.rulesets', []), is_array(...)))
                ->map(fn (array $ruleset): array => ['name' => (string) ($ruleset['name'] ?? ''), 'open' => (int) ($ruleset['open'] ?? 0)])
                ->all()),
            insightsUrl: is_string($url = data_get($data, 'insights.url')) ? $url : null,
            takenAt: $at,
            healthScored: (bool) data_get($data, 'health.scored', true),
            fixesAsked: array_values(array_filter((array) data_get($data, 'fixes.asked', []), is_string(...))),
            workAsked: array_values(array_map(fn (array $ask): array => [
                'id' => (int) ($ask['id'] ?? 0),
                'job' => (string) ($ask['job'] ?? ''),
                'name' => (string) ($ask['name'] ?? $ask['job'] ?? ''),
                'mode' => (string) ($ask['mode'] ?? 'terminal'),
                'files' => (int) ($ask['files'] ?? 0),
                'state' => (string) ($ask['state'] ?? 'asked'),
            ], array_filter((array) data_get($data, 'work.asked', []), is_array(...)))),
            workRechecks: (int) data_get($data, 'work.rechecks', 0),
            workSends: (int) data_get($data, 'work.sends', 0),
            filesWanted: array_values(array_filter((array) data_get($data, 'work.files', []), is_string(...))),
            workTogether: is_array($together = data_get($data, 'work.together')) && (int) ($together['session'] ?? 0) > 0 ? [
                'session' => (int) $together['session'],
                'open' => is_string($together['open'] ?? null) ? $together['open'] : null,
                'watch' => array_values(array_filter((array) ($together['watch'] ?? []), is_string(...))),
            ] : null,
            searchesWanted: array_values(array_map(fn (array $search): array => [
                'id' => (string) $search['id'],
                'pattern' => (string) $search['pattern'],
                'in' => is_string($search['in'] ?? null) && $search['in'] !== '' ? $search['in'] : null,
            ], array_filter((array) data_get($data, 'work.searches', []), fn (mixed $search): bool => is_array($search) && is_string($search['id'] ?? null) && is_string($search['pattern'] ?? null)))),
            bridgeAsked: is_string($asked = data_get($data, 'bridge.asked')) && $asked !== '' ? $asked : null,
        );
    }

    private static function ago(string $moment, Carbon $at): string
    {
        return Carbon::parse($moment)->diffForHumans($at, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'short' => true, 'parts' => 1]);
    }

    public function fingerprint(): string
    {
        return hash('xxh128', json_encode([...get_object_vars($this), 'takenAt' => null], JSON_THROW_ON_ERROR));
    }

    public function healthColour(): string
    {
        return match (true) {
            ! $this->healthScored => 'dim',
            $this->healthLabel === 'Good' => 'green',
            $this->healthLabel === 'Fair' => 'amber',
            $this->healthLabel === 'Not scanned' => 'dim',
            default => 'rose',
        };
    }

    public function healthValue(): string
    {
        return $this->healthScored ? "{$this->health}%" : '—';
    }

    public function scanFraction(): float
    {
        return $this->scanFilesTotal > 0 ? $this->scanFilesDone / $this->scanFilesTotal : 0.0;
    }

    public function hasScanned(): bool
    {
        return $this->scanFilesTotal > 0 || $this->scannedAgo !== null;
    }

    public function scanHasStarted(): bool
    {
        return $this->scanStatus !== self::NOT_SCANNED;
    }

    public function scanLabel(): string
    {
        return $this->scanStatus.($this->scannedAgo === null ? '' : " · {$this->scannedAgo}");
    }

    public function mostOpen(): int
    {
        return max(1, (int) collect($this->rulesets)->max('open'));
    }

    public function totalOpen(): int
    {
        return (int) collect($this->rulesets)->sum('open');
    }

    /**
     * Whether a scan has given the project insights yet.
     */
    public function hasInsights(): bool
    {
        return $this->rulesets !== [] || $this->insightsOpen > 0;
    }
}
