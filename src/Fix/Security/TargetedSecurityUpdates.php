<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix\Security;

use ArtisanStudio\StudioCli\Studio;
use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Patches the packages an audit flags, and nothing else, within the versions
 * the project already allows. A blanket update moves every package that has
 * a newer release; this moves only the vulnerable ones, to the least version
 * that clears them, and proves the front end still builds before keeping it.
 *
 * Artisan Studio plans which npm packages move and how far. Its answer is
 * written into package.json here, so only a plain `^1.2.3` range for a package
 * already listed, or an override bound to one version line, is ever written.
 */
final class TargetedSecurityUpdates
{
    private const string RANGE = '/^\^\d+\.\d+\.\d+$/';

    private const string OVERRIDE = '/^(@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*@>=\d+\.\d+\.\d+ <\d+\.\d+\.\d+$/i';

    /**
     * The front end's files an npm patch changes, and the only ones it commits.
     *
     * @var list<string>
     */
    public const array NODE_FILES = ['package.json', 'pnpm-lock.yaml', 'package-lock.json'];

    private const int ROUNDS = 3;

    private const int TIMEOUT = 900;

    /**
     * @param  Closure(): array<string, string|false>  $environment
     */
    public function __construct(
        private readonly string $root,
        private readonly Closure $environment,
        private readonly ?Studio $studio = null,
    ) {}

    public function canFix(string $key): bool
    {
        return match ($key) {
            'node-audit' => is_file($this->root.'/pnpm-lock.yaml') || is_file($this->root.'/package-lock.json'),
            'composer-audit' => is_file($this->root.'/composer.lock'),
            default => false,
        };
    }

    /**
     * @return array{ran: bool, reason?: string, fixed: int, kinds: array<string, int>, changed: list<string>, left: list<string>}
     */
    public function fix(string $key): array
    {
        return $key === 'composer-audit' ? $this->composer() : $this->node();
    }

    /**
     * @return array{ran: bool, reason?: string, fixed: int, kinds: array<string, int>, changed: list<string>, left: list<string>}
     */
    /**
     * One audit plans every patch at once, and one install applies them. The
     * audit after it is the count of what is left, and only when a patch
     * pulled in another vulnerable version does a second round run.
     *
     * @return array{ran: bool, reason?: string, fixed: int, kinds: array<string, int>, changed: list<string>, left: list<string>}
     */
    private function node(): array
    {
        $pnpm = is_file($this->root.'/pnpm-lock.yaml');
        $audit = $this->json([$pnpm ? 'pnpm' : 'npm', 'audit', '--json']);
        $before = $this->advisories($audit, $pnpm);
        $changed = [];
        $majors = [];

        if ($pnpm) {
            foreach (range(1, self::ROUNDS) as $round) {
                $plan = $this->plan($audit);

                if ($plan === null && $changed === []) {
                    return ['ran' => false, 'reason' => 'Artisan Studio could not be reached to plan the patches, so nothing was changed.', 'fixed' => 0, 'kinds' => [], 'changed' => [], 'left' => []];
                }

                $majors = $plan === null ? $majors : array_map(fn (array $major): string => $major['package'].' '.$major['installed'], $plan['majors']);
                $patches = array_values(array_filter($plan['patches'] ?? [], fn (array $patch): bool => ! isset($changed[$patch['package'].' '.$patch['installed']])));

                if ($plan === null || $patches === []) {
                    break;
                }

                $this->writeManifest(self::withChanges($this->manifest(), $plan['changes']));
                array_map(function (array $patch) use (&$changed): void {
                    $changed[$patch['package'].' '.$patch['installed']] = $patch['package'].' '.$patch['installed'].' → '.$patch['required'];
                }, $patches);

                if (! $this->run(['pnpm', 'install', '--no-frozen-lockfile', '--prefer-offline'])) {
                    return $this->revert(['package.json', 'pnpm-lock.yaml'], ['pnpm', 'install', '--prefer-offline'], 'pnpm could not install the patched versions, so nothing was kept.');
                }

                $audit = $this->json(['pnpm', 'audit', '--json']);
            }
        } elseif ($this->run(['npm', 'audit', 'fix'])) {
            $audit = $this->json(['npm', 'audit', '--json']);
        } else {
            return $this->revert(['package.json', 'package-lock.json'], ['npm', 'install'], 'npm audit fix did not finish, so nothing was kept.');
        }

        if (($changed !== [] || ! $pnpm) && isset($this->manifest()['scripts']['build']) && ! $this->run([$pnpm ? 'pnpm' : 'npm', 'run', 'build'])) {
            return $this->revert(['package.json', $pnpm ? 'pnpm-lock.yaml' : 'package-lock.json'], [$pnpm ? 'pnpm' : 'npm', 'install'], 'Your front end did not build with the patched versions, so nothing was kept.');
        }

        $fixed = max(0, $before - $this->advisories($audit, $pnpm));

        return ['ran' => true, 'fixed' => $fixed, 'kinds' => $fixed === 0 ? [] : ['npm packages patched within the same version' => $fixed], 'changed' => array_values($changed), 'left' => array_values(array_unique($majors))];
    }

    /**
     * Only composer.lock moves. Updating vendor in place swaps the code under
     * the running site, queue workers and studio, which breaks all three until
     * it is done, so the developer runs composer install when they take it.
     *
     * @return array{ran: bool, reason?: string, fixed: int, kinds: array<string, int>, changed: list<string>, left: list<string>}
     */
    private function composer(): array
    {
        $audit = $this->json(['composer', 'audit', '--format=json', '--no-interaction', '--locked']);
        $packages = array_values(array_filter(array_keys((array) ($audit['advisories'] ?? [])), fn (mixed $package): bool => is_string($package) && (array) $audit['advisories'][$package] !== []));
        $before = $this->locked();

        if ($packages === []) {
            return ['ran' => true, 'fixed' => 0, 'kinds' => [], 'changed' => [], 'left' => []];
        }

        if (! $this->run(['composer', 'update', ...$packages, '--with-dependencies', '--no-install', '--no-scripts', '--no-interaction'])) {
            return $this->revert(['composer.json', 'composer.lock'], [], 'Composer could not update the flagged packages within your constraints, so nothing was kept.');
        }

        $after = $this->locked();
        $left = array_keys(array_filter((array) ($this->json(['composer', 'audit', '--format=json', '--no-interaction', '--locked'])['advisories'] ?? []), fn (mixed $list): bool => (array) $list !== []));
        $fixed = count(array_diff($packages, $left));

        return [
            'ran' => true,
            'fixed' => $fixed,
            'kinds' => $fixed === 0 ? [] : ['composer packages patched within your constraints' => $fixed],
            'changed' => array_values(array_map(fn (string $package): string => $package.' '.$before[$package].' → '.$after[$package], array_filter(array_keys($after), fn (string $package): bool => isset($before[$package]) && $before[$package] !== $after[$package]))),
            'left' => array_values(array_filter($left, is_string(...))),
        ];
    }

    /**
     * The studio's plan for this audit: what to patch, what needs a major,
     * and the changes to package.json. Null when it could not be asked.
     *
     * @param  array<string, mixed>  $audit
     * @return array{patches: list<array{package: string, installed: string, required: string}>, majors: list<array{package: string, installed: string}>, changes: array<mixed>}|null
     */
    private function plan(array $audit): ?array
    {
        $manifest = $this->manifest();
        $advisories = array_values(array_filter(array_map(fn (array $advisory): array => [
            'module_name' => (string) ($advisory['module_name'] ?? ''),
            'patched_versions' => (string) ($advisory['patched_versions'] ?? ''),
            'findings' => array_values(array_map(
                fn (array $finding): array => ['version' => (string) $finding['version']],
                array_filter((array) ($advisory['findings'] ?? []), fn (mixed $finding): bool => is_array($finding) && is_string($finding['version'] ?? null) && $finding['version'] !== ''),
            )),
        ], array_filter((array) ($audit['advisories'] ?? []), is_array(...))), fn (array $advisory): bool => $advisory['module_name'] !== '' && $advisory['findings'] !== []));
        $answer = ($this->studio ?? app(Studio::class))->planNodePatches($advisories, array_filter([
            'dependencies' => array_filter((array) ($manifest['dependencies'] ?? []), is_string(...)),
            'devDependencies' => array_filter((array) ($manifest['devDependencies'] ?? []), is_string(...)),
        ]));
        $named = fn (mixed $entry, string ...$fields): bool => is_array($entry) && collect($fields)->every(fn (string $field): bool => is_string($entry[$field] ?? null));

        return ! is_array($answer) || ! is_array($answer['patches'] ?? null) ? null : [
            'patches' => array_values(array_filter($answer['patches'], fn (mixed $patch): bool => $named($patch, 'package', 'installed', 'required'))),
            'majors' => array_values(array_filter((array) ($answer['majors'] ?? []), fn (mixed $major): bool => $named($major, 'package', 'installed'))),
            'changes' => (array) ($answer['changes'] ?? []),
        ];
    }

    /**
     * package.json with the studio's changes: a listed dependency raised to a
     * plain `^` range, and an override for each transitive one, never
     * replacing one the project already has.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<mixed>  $changes
     * @return array<string, mixed>
     */
    public static function withChanges(array $manifest, array $changes): array
    {
        $manifest = collect(['dependencies', 'devDependencies'])->reduce(function (array $manifest, string $section) use ($changes): array {
            $raised = array_filter(
                (array) ($changes[$section] ?? []),
                fn (mixed $range, mixed $package): bool => is_string($package) && isset($manifest[$section][$package]) && is_string($range) && preg_match(self::RANGE, $range) === 1,
                ARRAY_FILTER_USE_BOTH,
            );

            return $raised === [] ? $manifest : array_replace_recursive($manifest, [$section => $raised]);
        }, $manifest);
        $existing = (array) ($manifest['pnpm']['overrides'] ?? []);
        $overrides = array_filter(
            (array) ($changes['overrides'] ?? []),
            fn (mixed $range, mixed $key): bool => is_string($key) && preg_match(self::OVERRIDE, $key) === 1 && is_string($range) && preg_match(self::RANGE, $range) === 1,
            ARRAY_FILTER_USE_BOTH,
        );

        return $overrides === [] ? $manifest : [...$manifest, 'pnpm' => [...(array) ($manifest['pnpm'] ?? []), 'overrides' => [...$existing, ...array_diff_key($overrides, $existing)]]];
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function advisories(array $audit, bool $pnpm): int
    {
        return $pnpm
            ? count(array_unique(array_column((array) ($audit['advisories'] ?? []), 'module_name')))
            : count(array_filter((array) ($audit['vulnerabilities'] ?? []), fn (mixed $vulnerability): bool => collect((array) ($vulnerability['via'] ?? []))->contains(fn (mixed $via): bool => is_array($via))));
    }

    /**
     * @return array<string, string> package => locked version
     */
    private function locked(): array
    {
        $lock = json_decode((string) @file_get_contents($this->root.'/composer.lock'), true);

        return collect([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])])
            ->filter(fn (mixed $package): bool => is_array($package) && isset($package['name'], $package['version']))
            ->mapWithKeys(fn (array $package): array => [(string) $package['name'] => ltrim((string) $package['version'], 'v')])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $manifest = json_decode((string) @file_get_contents($this->root.'/package.json'), true);

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(array $manifest): void
    {
        $original = (string) @file_get_contents($this->root.'/package.json');
        $indent = preg_match('/^\{\n( +)"/', $original, $found) === 1 ? strlen($found[1]) : 4;
        $json = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $spaced = (string) preg_replace_callback('/^( +)/m', fn (array $lead): string => str_repeat(' ', intdiv(strlen($lead[1]), 4) * $indent), $json);

        file_put_contents($this->root.'/package.json', $spaced."\n");
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $reinstall
     * @return array{ran: false, reason: string, fixed: 0, kinds: array<string, int>, changed: list<string>, left: list<string>}
     */
    private function revert(array $files, array $reinstall, string $reason): array
    {
        $this->run(['git', 'checkout', '--', ...array_values(array_filter($files, fn (string $file): bool => is_file($this->root.'/'.$file)))]);

        if ($reinstall !== []) {
            $this->run($reinstall);
        }

        return ['ran' => false, 'reason' => $reason, 'fixed' => 0, 'kinds' => [], 'changed' => [], 'left' => []];
    }

    /**
     * @param  list<string>  $command
     * @return array<string, mixed>
     */
    private function json(array $command): array
    {
        $process = new Process($command, $this->root, ($this->environment)(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable) {
            return [];
        }

        $start = strpos($process->getOutput(), '{');
        $json = $start === false ? null : json_decode(substr($process->getOutput(), $start), true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): bool
    {
        $process = new Process($command, $this->root, ($this->environment)(), timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }
}
