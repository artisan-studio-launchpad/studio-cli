<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\TypeCoverageCache;

class TypeCoverage extends Tool
{
    public const string PLUGIN = 'vendor/pestphp/pest-plugin-type-coverage';

    private const array KINDS = ['pr' => 'property', 'pa' => 'parameter', 'rt' => 'return', 'co' => 'constant'];

    private ?string $report = null;

    /** @var array{coverage: int}|null */
    private ?array $totals = null;

    public function key(): string
    {
        return 'pest-type-coverage';
    }

    public function name(): string
    {
        return 'Pest type coverage';
    }

    public function missing(): string
    {
        return 'Not installed in this project: it needs pestphp/pest-plugin-type-coverage.';
    }

    public function install(): array
    {
        return ['packages' => ['pestphp/pest-plugin-type-coverage'], 'files' => [], 'about' => 'Adds Pest\'s type coverage plugin, which finds parameters, returns and properties with no type.'];
    }

    public function command(string $root): ?array
    {
        if ($this->bin($root, 'pest') === null || ! is_dir($root.'/'.self::PLUGIN)) {
            return null;
        }

        (new TypeCoverageCache($root))->prune();
        $this->report = sys_get_temp_dir().'/studio-type-coverage-'.bin2hex(random_bytes(6)).'.json';

        return ['vendor/bin/pest', '--type-coverage', '--min=0', '--type-coverage-json='.$this->report];
    }

    public function findings(string $output, string $root): ?array
    {
        $report = $this->report === null ? null : $this->read($this->report);
        $this->totals = null;

        if ($report === null || ! isset($report['result'])) {
            return null;
        }

        $this->totals = ['coverage' => (int) round((float) ($report['total'] ?? 0))];

        return array_values(collect((array) $report['result'])
            ->flatMap(fn (mixed $file): array => collect((array) ($file['uncoveredLines'] ?? []))
                ->map(fn (mixed $entry): ?array => preg_match('/^([a-z]{2})(\d+)$/', (string) $entry, $match) === 1
                    ? $this->finding($this->relative((string) ($file['file'] ?? ''), $root), (int) $match[2], self::KINDS[$match[1]] ?? 'type', 'No type declared on this '.(self::KINDS[$match[1]] ?? 'declaration').'.')
                    : null)
                ->filter()
                ->all())
            ->filter(fn (array $finding): bool => $finding['where'] !== '')
            ->all());
    }

    /**
     * @return array{coverage: int}|null
     */
    public function summary(): ?array
    {
        return $this->totals;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        @unlink($path);

        return is_array($json) ? $json : null;
    }
}
