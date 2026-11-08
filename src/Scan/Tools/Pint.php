<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

class Pint extends Tool
{
    public const string UNNAMED = 'style';

    public function key(): string
    {
        return 'pint';
    }

    public function name(): string
    {
        return 'Pint';
    }

    public function command(string $root): ?array
    {
        $bin = $this->bin($root, 'pint');

        if ($bin === null) {
            return null;
        }

        return [$bin, '--test', '--format=json', '-v', ...$this->configuration($root), ...($this->offers($root, [$bin, '--help'], '--parallel') ? ['--parallel'] : [])];
    }

    public function fixCommand(string $root): ?array
    {
        $bin = $this->bin($root, 'pint');

        return $bin === null ? null : [$bin, ...$this->configuration($root), ...($this->offers($root, [$bin, '--help'], '--parallel') ? ['--parallel'] : [])];
    }

    public function serialFixCommand(string $root): ?array
    {
        $bin = $this->bin($root, 'pint');

        return $bin === null ? null : [$bin, ...$this->configuration($root)];
    }

    public function serialCommand(string $root): ?array
    {
        $bin = $this->bin($root, 'pint');

        return $bin === null ? null : [$bin, '--test', '--format=json', '-v', ...$this->configuration($root)];
    }

    /**
     * Pint formats every PHP file under the project, and a project keeps PHP files in its tmp
     * folder that are not its code (PHPStan's cache is thousands of them), so Pint is slow and
     * reports files nobody wrote. This is the project's own pint.json with tmp added to what
     * it skips, written to the system's temp folder so no developer has to edit their config.
     *
     * @return list<string>
     */
    private function configuration(string $root): array
    {
        $own = $root.'/pint.json';
        $config = is_file($own) ? json_decode((string) file_get_contents($own), true) : [];

        if (! is_array($config)) {
            return [];
        }

        $config['exclude'] = array_values(array_unique([...array_map('strval', (array) ($config['exclude'] ?? [])), 'tmp']));
        $folder = sys_get_temp_dir().'/studio-pint';
        $file = $folder.'/'.hash('xxh128', $root).'.json';

        if (! is_dir($folder) && ! @mkdir($folder, 0700, true) && ! is_dir($folder)) {
            return [];
        }

        return @file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false ? [] : ['--config='.$file];
    }

    public function canFix(string $root): bool
    {
        return $this->bin($root, 'pint') !== null;
    }

    public function isSetUp(string $root): bool
    {
        return $this->bin($root, 'pint') !== null;
    }

    public function install(): array
    {
        return ['packages' => ['laravel/pint'], 'files' => [], 'about' => 'Adds Laravel Pint, which checks code style. It runs with --test, so it never changes a file.'];
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if ($json === null) {
            return null;
        }

        $files = (array) ($json['files'] ?? []);

        return array_values(collect(array_is_list($files) ? $files : array_map(fn (mixed $file, string $path): array => [...(array) $file, 'path' => $path], $files, array_keys($files)))
            ->flatMap(fn (mixed $file): array => collect((array) (($file['fixers'] ?? null) ?? ($file['appliedFixers'] ?? null) ?? [self::UNNAMED]))
                ->map(fn (mixed $fixer): array => $this->finding(
                    $this->relative((string) ($file['path'] ?? $file['name'] ?? ''), $root),
                    null,
                    (string) $fixer,
                    $fixer === self::UNNAMED ? 'Fails Pint\'s style rules.' : 'Fails Pint\'s '.$fixer.' rule.',
                ))
                ->all())
            ->filter(fn (array $finding): bool => $finding['where'] !== '')
            ->all());
    }
}
