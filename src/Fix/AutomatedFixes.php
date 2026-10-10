<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\Fix\Security\TargetedSecurityUpdates;
use ArtisanStudio\StudioCli\Scan\LastFindings;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use Illuminate\Support\Collection;

/**
 * Fixes the rules without AI, in one pass.
 *
 * Everything starts from what the scan found, so nothing is checked again
 * while fixing. The fixers live in the studio: each flagged file goes
 * up once with its findings, and every fix any rule wants in it comes back as
 * one write, at offsets in the text the scan read, so no fix moves another's
 * line. What only this machine can do stays here: the tools' own fixers,
 * which need no lines.
 */
final class AutomatedFixes
{
    /**
     * Rules the studio fixes itself, at the lines their tool reported.
     *
     * @var list<string>
     */
    public const array OWN = ['pest-type-coverage', 'phpstan', self::SECURITY_SCAN, self::ARCH];

    public const string SECURITY_SCAN = 'studio-security';

    public const string ARCH = 'pest-arch';

    /**
     * Rules whose tool has its own fixer, run whole after the studio's, since they need no lines.
     *
     * @var list<string>
     */
    public const array TOOLS = ['rector', 'pint'];

    /**
     * Audits whose flagged packages are patched within the versions the
     * project allows, first, so everything after runs on the patched code.
     *
     * @var list<string>
     */
    public const array SECURITY = ['composer-audit', 'node-audit'];

    public function __construct(
        private readonly Toolbox $toolbox,
        private readonly string $root,
        private readonly ?Studio $studio = null,
    ) {}

    /**
     * {@see self::OWN}, with the described tools whose findings the studio fixes too.
     *
     * @return list<string>
     */
    public static function own(?Toolbox $toolbox = null): array
    {
        return [...self::OWN, ...($toolbox ?? new Toolbox)->fixedByStudio()];
    }

    /**
     * {@see self::TOOLS}, after the described tools' own fixers.
     *
     * @return list<string>
     */
    public static function tools(?Toolbox $toolbox = null): array
    {
        return [...($toolbox ?? new Toolbox)->ownFixers(), ...self::TOOLS];
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function fixable(array $keys): array
    {
        $own = self::own($this->toolbox);

        return array_values(array_filter([...self::SECURITY, ...$own, ...self::tools($this->toolbox)], fn (string $key): bool => in_array($key, $keys, true)
            && (in_array($key, $own, true) || $this->toolbox->canFix($key) || $this->security()->canFix($key))));
    }

    public function security(): TargetedSecurityUpdates
    {
        return new TargetedSecurityUpdates($this->root, fn (): array => $this->toolbox->environment(), $this->studio);
    }

    /**
     * What the last scan found for each rule, when it read this commit. Rules
     * it has nothing for are checked now, all at the same time.
     *
     * @param  list<string>  $keys
     * @return array{findings: array<string, list<array{where: string, rule: string, message: string}>>, checked: list<string>, failed: array<string, string>}
     */
    public function findings(array $keys): array
    {
        $own = array_values(array_intersect($keys, self::own($this->toolbox)));
        $kept = new LastFindings($this->root);
        $scanned = array_filter(array_combine($own, array_map($kept->for(...), $own)), fn (?array $findings): bool => $findings !== null);
        $missing = array_values(array_diff($own, array_keys($scanned)));
        $checked = $missing === [] ? [] : $this->toolbox->checkTogether($missing);

        $found = [...$scanned, ...array_map(fn (array $result): array => $result['findings'] ?? [], array_filter($checked, fn (array $result): bool => $result['ran']))];

        return [
            'findings' => collect($found)->map(fn (array $findings, string $key): array => [...$findings, ...(self::added()[$key] ?? [])])->all(),
            'checked' => $missing,
            'failed' => array_map(fn (array $result): string => $result['reason'] ?? 'It did not run.', array_filter($checked, fn (array $result): bool => ! $result['ran'])),
        ];
    }

    /**
     * Findings the studio adds to a rule's own, such as a project config it
     * fixes alongside the code, by rule.
     *
     * @return array<string, list<array{where: string, rule: string, message: string}>>
     */
    public static function added(): array
    {
        return collect((array) config('studio-cli.added', []))
            ->filter(fn (mixed $findings, mixed $key): bool => is_string($key) && is_array($findings))
            ->map(fn (array $findings): array => array_values(array_filter($findings, fn (mixed $finding): bool => is_array($finding)
                && is_string($finding['where'] ?? null) && preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]{0,200}$#', $finding['where']) === 1 && ! str_contains($finding['where'], '..')
                && is_string($finding['rule'] ?? null) && is_string($finding['message'] ?? null))))
            ->all();
    }

    /**
     * How many things a rule's findings are, the way Insights counts them:
     * an audit's advisories are one per package, everything else one each.
     *
     * @param  list<array{where: string, rule: string, message: string}>  $findings
     */
    public static function counted(string $key, array $findings): int
    {
        return in_array($key, self::SECURITY, true)
            ? count(array_unique(array_map(fn (array $finding): string => (string) strtok($finding['message'], ' '), $findings)))
            : count($findings);
    }

    /**
     * Every fix the studio can make, file by file across all the rules, made in the
     * studio and written here once.
     *
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     * @param  (callable(string, string): void)|null  $onFixed  told the rule and the file of each fix as its batch comes back
     * @return array{kinds: array<string, array<string, int>>, files: array<string, int>, saved: list<string>, fixed: array<string, list<array{where: string, rule: string, message: string}>>, swept: list<array{where: string, rule: string, message: string}>, failed: int, requests: int, sent: int}
     */
    public function fileByFile(array $findings, ?callable $onFixed = null): array
    {
        $made = (new StudioFixes($this->root, $this->studio ?? app(Studio::class)))->fix($findings, isset($findings[self::ARCH]), $onFixed);
        $saved = $made['saved'];
        $fixed = collect($made['fixed'])->filter(fn (array $fix): bool => in_array($fix['path'], $saved, true))->values();
        $keys = $saved === [] ? [] : array_values(array_unique($fixed->pluck('key')->all()));
        $kinds = array_combine($keys, array_map(fn (string $key): array => $fixed->where('key', $key)->countBy('label')->all(), $keys));

        return [
            'kinds' => $kinds,
            'files' => array_combine($keys, array_map(fn (string $key): int => $fixed->where('key', $key)->pluck('path')->unique()->count(), $keys)),
            'saved' => $saved,
            'fixed' => $fixed->groupBy('key')->map(fn (Collection $fixes): array => $fixes->pluck('finding')->values()->all())->all(),
            'swept' => $made['swept'],
            'failed' => $made['failed'],
            'requests' => $made['requests'],
            'sent' => $made['sent'],
        ];
    }

    /**
     * Rector's type rules for every rule asked, in one run over every flagged file.
     *
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     * @return array<string, int>
     */
    public function rector(array $findings): array
    {
        $rules = new RectorRules($this->root, fn (): array => $this->toolbox->environment());
        $paths = array_values(array_unique(array_map(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']), array_merge(...array_values($findings)))));
        $changed = $rules->apply(array_keys($findings), $paths);

        return $changed === null || $changed === [] ? [] : [$rules->label() => count($changed)];
    }

    /**
     * Leaves what the fixes changed in the project's own style, when the project
     * has Pint: imports in order, spacing as the rest of the code.
     *
     * @param  list<string>  $changed
     */
    public function tidy(array $changed): void
    {
        $php = array_values(array_filter($changed, fn (string $path): bool => str_ends_with($path, '.php')));

        if ($php !== [] && $this->toolbox->canFix('pint')) {
            $this->toolbox->fix('pint', $php);
        }
    }
}
