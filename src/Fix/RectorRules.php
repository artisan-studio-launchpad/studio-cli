<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The Rector rules Artisan Studio chooses for each ruleset, run on only the
 * files a check flagged, with a config of its own: the project's rector.php is
 * never read or changed. A rule this project's Rector does not have is
 * skipped, not guessed at.
 *
 * The rules arrive with what the studio asks to fix. Each one is written into
 * a config this machine runs, so only a plain class name whose file is in the
 * project's own vendor folder is ever used.
 */
final class RectorRules
{
    private const int TIMEOUT = 900;

    private const string CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/';

    private const string VENDOR_FILE = '#^vendor/[A-Za-z0-9_./-]+\.php$#';

    public function __construct(
        private readonly string $root,
        private readonly Closure $environment,
    ) {}

    /**
     * The rulesets Artisan Studio has Rector rules for.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::rules());
    }

    /**
     * @return array<string, array<string, string>> ruleset => rule => the file that proves the project has it
     */
    public static function rules(): array
    {
        return collect((array) config('studio-cli.rector', []))
            ->filter(fn (mixed $rules, mixed $key): bool => is_string($key) && is_array($rules))
            ->map(fn (array $rules): array => array_filter(
                $rules,
                fn (mixed $file, mixed $rule): bool => is_string($rule) && is_string($file) && preg_match(self::CLASS_NAME, $rule) === 1 && preg_match(self::VENDOR_FILE, $file) === 1 && ! str_contains($file, '..'),
                ARRAY_FILTER_USE_BOTH,
            ))
            ->filter()
            ->all();
    }

    public function label(): string
    {
        return 'files where Rector added types it could prove';
    }

    /**
     * Every rule for these rulesets in one Rector run over every flagged file.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $paths
     * @return list<string>|null The files Rector changed, or null when it could not run.
     */
    public function apply(array $keys, array $paths): ?array
    {
        $rules = array_values(array_unique(array_merge(...array_map($this->available(...), $keys))));
        $paths = array_values(array_filter(array_unique($paths), fn (string $path): bool => is_file($this->root.'/'.$path)));

        if ($rules === [] || $paths === [] || ! is_file($this->root.'/vendor/bin/rector')) {
            return null;
        }

        $config = sys_get_temp_dir().'/studio-rector-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($config, $this->config($rules));
        $process = new Process(['vendor/bin/rector', 'process', ...$paths, '--config='.$config, '--output-format=json', '--no-progress-bar'], $this->root, ($this->environment)(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($config);
        }

        $start = strpos($process->getOutput(), '{');
        $json = $start === false ? null : json_decode(substr($process->getOutput(), $start), true);

        return is_array($json) ? array_values(array_map(fn (array $diff): string => (string) ($diff['file'] ?? ''), (array) ($json['file_diffs'] ?? []))) : null;
    }

    /**
     * @return list<string>
     */
    private function available(string $key): array
    {
        return array_keys(array_filter(self::rules()[$key] ?? [], fn (string $file): bool => is_file($this->root.'/'.$file)));
    }

    /**
     * @param  list<string>  $rules
     */
    private function config(array $rules): string
    {
        $listed = implode(",\n        ", array_map(fn (string $rule): string => '\\'.$rule.'::class', $rules));

        return <<<PHP
            <?php

            return \\Rector\\Config\\RectorConfig::configure()
                ->withRules([
                    {$listed},
                ]);
            PHP;
    }
}
