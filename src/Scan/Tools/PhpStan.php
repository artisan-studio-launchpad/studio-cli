<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan\Tools;

use ArtisanStudio\StudioCli\Scan\PackageNamespaces;
use ArtisanStudio\StudioCli\Scan\PhpStanLevel;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class PhpStan extends Tool
{
    /**
     * @var array{files: int, flagged: int}|null
     */
    private ?array $totals = null;

    public function key(): string
    {
        return 'phpstan';
    }

    public function name(): string
    {
        return 'PHPStan';
    }

    public const array CONFIGS = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    /**
     * The longest whole PHPStan message sent along with a cut one, which is
     * what the studio shows in full when the message does not fit a line.
     */
    private const int LONGEST_DETAIL = 6000;

    private const string LEVEL = 'max';

    public function command(string $root): ?array
    {
        $bin = $this->bin($root, 'phpstan');
        $config = collect(self::CONFIGS)->first(fn (string $config): bool => is_file($root.'/'.$config));

        if ($bin === null || $config === null) {
            return null;
        }

        $outside = $this->cacheOutsideTheProject($root, $config, $this->readsCastsByDefault($root));

        return [$bin, 'analyse', ...($outside === null ? [] : ['--configuration='.$outside]), '--level='.self::LEVEL, '--error-format=json', '--no-progress', '--no-interaction', '--memory-limit=2G'];
    }

    /**
     * PHPStan keeps its cache where the project's own config says, usually a folder inside the
     * project, and that folder holds thousands of PHP files that Pint, Rector and the editor then
     * read as source. This is the project's config with only its cache moved to the system's
     * temp folder, so a scan leaves nothing behind in the project. The scan always runs at the
     * highest level, whatever the project's own config says, so every finding is collected, and
     * what its baseline ignores at the higher levels is not reported as unmatched. A project
     * that does not say whether Larastan reads `casts()` on its models has it read here.
     */
    private function cacheOutsideTheProject(string $root, string $config, bool $casts = false): ?string
    {
        $folder = sys_get_temp_dir().'/studio-phpstan';
        $key = hash('xxh128', $root);
        $file = $folder.'/'.$key.'.neon';
        $quote = fn (string $value): string => "'".str_replace("'", "''", $value)."'";
        $neon = "includes:\n    - ".$quote($root.'/'.$config)."\n\nparameters:\n    tmpDir: ".$quote($folder.'/'.$key)."\n    level: ".self::LEVEL."\n    reportUnmatchedIgnoredErrors: false\n".($casts ? "    parseModelCastsMethod: true\n" : '');

        if (! is_dir($folder) && ! @mkdir($folder, 0700, true) && ! is_dir($folder)) {
            return null;
        }

        return @file_put_contents($file, $neon) === false ? null : $file;
    }

    /**
     * Whether the scan has Larastan read `casts()` on the models: only when the
     * project uses Larastan, its version knows the option, and the project's own
     * config says nothing about it. One that says so, either way, is left as it is.
     */
    private function readsCastsByDefault(string $root): bool
    {
        $extension = (string) @file_get_contents($root.'/vendor/larastan/larastan/extension.neon');
        $level = new PhpStanLevel($root, self::CONFIGS);

        return str_contains($extension, 'parseModelCastsMethod') && $level->usesLarastan() && $level->readsCasts() === null;
    }

    public function missing(): string
    {
        return 'Not set up in this project: it needs PHPStan or Larastan and a phpstan.neon.';
    }

    public function install(): array
    {
        return [
            'packages' => ['larastan/larastan'],
            'files' => ['phpstan.neon' => <<<'NEON'
                includes:
                    - vendor/larastan/larastan/extension.neon

                parameters:
                    paths:
                        - app
                    level: 5

                NEON],
            'about' => 'Adds Larastan, PHPStan for Laravel, and a phpstan.neon that checks app/ at level 5. Raise the level whenever you like.',
        ];
    }

    public function findings(string $output, string $root): ?array
    {
        $json = $this->json($output);

        if ($json === null || ! isset($json['totals'])) {
            return null;
        }

        $packages = new PackageNamespaces($root);
        $findings = array_values(collect((array) ($json['files'] ?? []))
            ->flatMap(fn (mixed $file, string $path): array => collect((array) ($file['messages'] ?? []))
                ->map(function (mixed $message) use ($path, $root, $packages): array {
                    $where = $this->relative((string) preg_replace('/ \(in context of .*\)$/', '', $path), $root);
                    $builtOn = $packages->builtOn($where);
                    $whole = trim((string) preg_replace('/\s+/', ' ', (string) ($message['message'] ?? '')));
                    $finding = $this->finding($where, isset($message['line']) ? (int) $message['line'] : null, (string) ($message['identifier'] ?? 'phpstan'), $whole);
                    $finding = mb_strlen($whole) > self::LONGEST_MESSAGE ? [...$finding, 'detail' => mb_substr($whole, 0, self::LONGEST_DETAIL)] : $finding;

                    return $builtOn === [] ? $finding : [...$finding, 'builtOn' => $builtOn];
                })
                ->all())
            ->all());

        $flagged = count(array_unique(array_map(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']), $findings)));
        $this->totals = ['files' => max($flagged, $this->analysed($root)), 'flagged' => $flagged];

        return $findings;
    }

    /**
     * How many PHP files PHPStan read, and how many it found something in, so
     * the score can count the files that came back clean.
     *
     * @return array{files: int, flagged: int}|null
     */
    public function summary(): ?array
    {
        return $this->totals;
    }

    /**
     * The PHP files under the paths the project's own config tells PHPStan to read.
     */
    private function analysed(string $root): int
    {
        $config = collect(self::CONFIGS)->map(fn (string $name): string => $root.'/'.$name)->first(fn (string $path): bool => is_file($path));
        $neon = $config === null ? '' : (string) file_get_contents($config);
        $paths = preg_match('/^\s*paths:\s*\n((?:\s+-\s*.+\n?)+)/m', $neon, $match) === 1
            ? array_map(fn (string $line): string => trim((string) preg_replace('/^\s*-\s*/', '', $line), " \t'\""), array_filter(explode("\n", $match[1]), fn (string $line): bool => trim($line) !== ''))
            : ['app'];

        return array_sum(array_map(fn (string $path): int => $this->phpFilesIn(str_starts_with($path, '/') ? $path : $root.'/'.$path), $paths));
    }

    private function phpFilesIn(string $path): int
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? 1 : 0;
        }

        if (! is_dir($path)) {
            return 0;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        return iterator_count(new RegexIterator($files, '/\.php$/'));
    }
}
