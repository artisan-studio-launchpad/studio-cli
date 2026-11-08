<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Fix\FixTimeline;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Fix\RectorRules;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Studio;
use Closure;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

/**
 * The Insights fix with no screen to draw: the same steps, in the same order,
 * on a new sami/fixes branch with a commit for each rule, as Fix with SAMI
 * makes them, and every step prints how long it took, so what makes a fix slow
 * can be told from what only looks slow.
 *
 * The file-by-file step shows a progress bar with the time that has gone, and
 * every retry and failure is listed at the end.
 *
 * Run it on a project you can throw the changes away from.
 */
class InsightsFixCommand extends Command
{
    public const string SIGNATURE = 'studio:insights-fix';

    protected $signature = self::SIGNATURE.'
        {--tool=* : Fix only these rules, without asking}
        {--no-rector : Leave out Rector\'s type rules}';

    protected $description = 'Run the Insights auto fixes step by step, timing each one, on a new sami/fixes branch with a commit for each rule, with no screen';

    /**
     * @var list<string>
     */
    private array $issues = [];

    /**
     * @var array<string, array{name: string, found: ?int, fixed: int, kinds: array<string, int>, note: string}>
     */
    private array $report = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $scanned = [];

    private string $run = '';

    private ?string $from = null;

    private ?string $base = null;

    private ?Studio $studio = null;

    private ?LocalBranch $git = null;

    private ?FixProgress $progress = null;

    /**
     * @var list<string>
     */
    private array $finished = [];

    /**
     * @var array<string, ?string>
     */
    private array $commits = [];

    /**
     * @var array<string, int>
     */
    private array $filesIn = [];

    /**
     * @var list<string>
     */
    private array $chosen = [];

    /**
     * @var array{ms?: int, parsing_ms?: int, phases?: array<string, int>, rules?: list<array<string, mixed>>, fixers?: list<array<string, mixed>>}
     */
    private array $engine = [];

    public function handle(Studio $studio): int
    {
        if (! $studio->isLinked()) {
            $this->components->error('This project is not linked yet. Run php artisan studio:settings, then try again.');

            return self::FAILURE;
        }

        if (! app(BridgeGate::class)->isConnected()) {
            $this->components->error((string) __('studio-cli::bridge.blocked'));

            return self::FAILURE;
        }

        $studio->scanFixes();

        if (! is_array(config('studio-cli.offered'))) {
            $this->components->error('Could not read the prepare-scan options from Artisan Studio ('.($studio->lastFailure() ?? 'it sent none').'), so nothing is offered and nothing was fixed. Is Artisan Studio up to date?');

            return self::FAILURE;
        }

        $toolbox = new Toolbox;
        $fixes = new AutomatedFixes($toolbox, base_path(), $studio);
        $keys = $this->rules($fixes, $toolbox);

        if ($keys === []) {
            $this->components->warn('Nothing to fix: the prepare-scan screen offers no rule here that Artisan Studio can fix, or none was chosen. Install the tools you want, pick them on the Scan screen, then try again.');

            return self::SUCCESS;
        }

        $branch = new LocalBranch(base_path());
        $refused = match (true) {
            ! $branch->isRepository() => 'This project is not a git repository, so there is no safe branch to fix on.',
            ! $branch->isClean() => Says::named('You have uncommitted changes. Commit or stash them first, so {name}\'s fixes stay apart from yours.'),
            default => null,
        };

        if ($refused !== null) {
            $studio->submitScanFixReport(['refused' => $refused]);
            $this->components->error($refused);

            return self::FAILURE;
        }

        $this->from = $branch->current();
        $this->base = $branch->head();
        $started = $branch->start(date('Y-m-d-Hi'));

        if ($started === null) {
            $this->components->error('Could not start a new branch, so nothing was changed.');

            return self::FAILURE;
        }

        $this->studio = $studio;
        $this->git = $branch;
        $this->chosen = $keys;
        $this->run = $started;
        $this->progress = app(FixProgress::class);
        $order = FixCommand::inOrder($keys);
        $this->progress->begin($started, $order, array_fill_keys($order, 0), $this->base);
        $status = app(ToolStatus::class);
        $status->asked($keys);
        $status->pick($keys);
        $status->running($keys);
        $timeline = new FixTimeline(base_path());
        $timeline->begin();
        $began = hrtime(true);
        $this->newLine();
        $this->components->info('Fixing on '.$started.', a new branch from '.($this->from ?? 'where you are').'. Each rule gets its own commit.');

        $own = array_values(array_intersect($keys, AutomatedFixes::own($toolbox)));
        $found = $own === [] ? null : $this->reading($fixes, $toolbox, $studio, $branch, $own);

        $found === null || $this->fixingOwn($fixes, $studio, $branch, $found);

        array_map(fn (string $key): mixed => $this->patching($fixes, $toolbox, $branch, $key), array_values(array_intersect($keys, AutomatedFixes::SECURITY)));

        array_map(fn (string $key): mixed => $this->running($fixes, $toolbox, $branch, $key), array_values(array_intersect($keys, AutomatedFixes::tools($toolbox))));

        $this->progress?->finished([]);
        $this->pulse('finished', 'finished');
        $this->sendReport($this->chosen);

        $this->summary($timeline, (int) ((hrtime(true) - $began) / 1_000_000), count($this->base === null ? [] : $branch->changesSince($this->base)));

        return self::SUCCESS;
    }

    /**
     * What the last scan found for these rules, and the tools it has nothing
     * for run now. This is the scan, not the fixing, and it comes first.
     *
     * @param  list<string>  $keys
     * @return array{findings: array<string, list<array{where: string, rule: string, message: string}>>, checked: list<string>, failed: array<string, string>, results: array<string, array<string, mixed>>, kept: array<string, list<array{where: string, rule: string, message: string}>>}
     */
    private function reading(AutomatedFixes $fixes, Toolbox $toolbox, Studio $studio, LocalBranch $branch, array $keys): array
    {
        $studio->authorizeScan();
        array_map(fn (string $key): mixed => $studio->reportToolProgress($key, 'running'), $keys);
        $found = $this->timed('Read what the scan found', fn (): array => $fixes->findings($keys), fn (array $found): string => number_format(array_sum(array_map(count(...), $found['findings']))).' findings'.($found['checked'] === [] ? ', from the last scan' : ', '.count($found['checked']).' tools scanned now'));
        $this->issues = [...$this->issues, ...collect($found['failed'])->map(fn (string $reason, string $key): string => $toolbox->name($key).' did not run: '.$reason)->values()->all()];
        $this->report = [...$this->report, ...collect($found['findings'])->map(fn (array $findings, string $key): array => ['name' => $toolbox->name($key), 'found' => count($findings), 'fixed' => 0, 'kinds' => [], 'note' => ''])->all()];
        $this->announce($studio, $toolbox, $branch, $keys, $found);
        $this->progress?->begin($this->run, FixCommand::inOrder($this->chosen), collect($found['findings'])->map(fn (array $findings, string $key): int => AutomatedFixes::counted($key, $findings))->all(), $this->base);
        $this->progress?->running(array_keys($found['findings']));
        $this->progress?->scanned($found['findings']);
        $studio->pickScanRules($this->chosen);
        $this->pulse('fixing', 'fixing file by file');

        return $found;
    }

    /**
     * Tells Artisan Studio what the scan found, the way the scan itself does,
     * so the app follows each rule and moves on from the scan step.
     *
     * @param  list<string>  $keys
     * @param  array{results: array<string, array<string, mixed>>, kept: array<string, list<array{where: string, rule: string, message: string}>>}  $found
     */
    private function announce(Studio $studio, Toolbox $toolbox, LocalBranch $branch, array $keys, array $found): void
    {
        $this->scanned = collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $found['results'][$key] ?? ['ran' => true, 'findings' => $found['kept'][$key] ?? []]])
            ->all();

        collect($this->scanned)->each(fn (array $result, string $key): mixed => app(ToolStatus::class)->ran($key, $result));
        collect($this->scanned)->each(fn (array $result, string $key): mixed => $studio->reportToolProgress($key, $result['ran'] ? 'done' : 'skipped', $result['ran'] ? null : (string) ($result['reason'] ?? null)));
        $studio->submitScanToolResults(['commit' => $branch->head(), 'packages' => $toolbox->packages(), 'namespaces' => (object) $toolbox->namespaces(), 'phpstan_level' => $toolbox->phpstanLevel(), 'tools' => $this->scanned]);
    }

    /**
     * Whether the developer chose this rule for the run: Pint's tidy and
     * Rector's type rules only run when they did.
     */
    private function picks(string $key): bool
    {
        return in_array($key, $this->chosen, true);
    }

    /**
     * Tells Artisan Studio where the run is, the way a Fix with SAMI run does,
     * on the sami/fixes branch it made, so Insights opens and follows it. A
     * rule counts as done once its commit has landed, which is when the city
     * scans and crushes what was fixed.
     */
    private function pulse(string $phase, string $step): void
    {
        $status = app(ToolStatus::class);
        $status->pick($this->chosen);
        $status->alsoRunning($this->chosen);
        $this->progress?->stepping($step);
        $this->studio?->submitScanFixReport(['branch' => $this->run, 'live' => [
            'phase' => $phase,
            'step' => $step,
            'rulesets' => collect($this->chosen)->mapWithKeys(fn (string $key): array => [$key => [
                'state' => in_array($key, $this->finished, true) ? 'done' : 'running',
                'found' => (int) ($this->report[$key]['found'] ?? 0),
                'fixed' => (int) ($this->report[$key]['fixed'] ?? 0),
                'left' => null,
            ]])->all(),
        ]]);
    }

    /**
     * The fix report of every rule finished so far, with the commit each one
     * landed in and the diffs of the branch, as the app's own run sends it.
     */
    private function sendReport(): void
    {
        $rulesets = collect($this->chosen)
            ->filter(fn (string $key): bool => in_array($key, $this->finished, true) && isset($this->report[$key]))
            ->mapWithKeys(fn (string $key): array => [$key => [
                'name' => mb_substr($this->report[$key]['name'], 0, 60),
                'ran' => true,
                'fixed' => $this->report[$key]['fixed'],
                'kinds' => array_map(intval(...), $this->report[$key]['kinds']),
                'files' => $this->filesIn[$key] ?? $this->report[$key]['fixed'],
                'commit' => $this->commits[$key] ?? null,
            ]])
            ->all();

        $rulesets === [] || $this->studio?->submitScanFixReport(['branch' => $this->run, 'from' => $this->from, 'rulesets' => $rulesets, 'diffs' => $this->base === null ? [] : ($this->git?->changesToSend($this->base) ?? [])]);
    }

    /**
     * Commits whatever the last step changed on the fix branch, as the app's
     * run does after each rule.
     *
     * @param  array<string, array<string, int>>  $kinds
     */
    private function commit(LocalBranch $branch, string $subject, array $kinds = [], string $body = ''): ?string
    {
        return $branch->changed() === [] ? null : $branch->commit($subject, $body !== '' ? $body : collect($kinds)
            ->flatMap(fn (array $byLabel, string $key): array => collect($byLabel)->map(fn (int $count, string $label): string => '- '.$key.': '.number_format($count).' '.$label)->values()->all())
            ->push('- Every change is a fixed rule applied at the line the tool reported, file by file')
            ->implode("\n"));
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, int>  $files
     */
    private function finish(array $keys, ?string $commit, array $files = []): void
    {
        $this->finished = array_values(array_unique([...$this->finished, ...$keys]));
        $this->commits = [...$this->commits, ...array_fill_keys($keys, $commit)];
        $this->filesIn = [...$this->filesIn, ...$files];
        array_map(fn (string $key): mixed => $this->progress?->done($key, true, (int) ($this->report[$key]['fixed'] ?? 0), $this->filesIn[$key] ?? (int) ($this->report[$key]['fixed'] ?? 0), $this->report[$key]['found'] ?? null), $keys);
    }

    /**
     * Tells Artisan Studio what is left after the fixes, as the fix run does,
     * so the counts on the app go down.
     *
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $fixed
     */
    private function announceLeft(Studio $studio, LocalBranch $branch, array $fixed): void
    {
        $identity = fn (array $finding): string => $finding['where'].'|'.$finding['rule'].'|'.$finding['message'];
        $left = collect($this->scanned)
            ->filter(fn (array $result): bool => $result['ran'] && isset($result['findings']))
            ->map(function (array $result, string $key) use ($fixed, $identity): array {
                $done = array_flip(array_map($identity, $fixed[$key] ?? []));

                return [...$result, 'findings' => array_values(array_filter($result['findings'], fn (array $finding): bool => ! isset($done[$identity($finding)])))];
            })
            ->all();

        collect($left)->each(function (array $result, string $key): void {
            app(ToolStatus::class)->ran($key, $result);
            $this->progress?->left($key, count($result['findings']));
        });
        $left === [] || $studio->submitScanToolResults(['commit' => $branch->head(), 'partial' => true, 'tools' => $left]);
    }

    /**
     * The studio's own fixes, file by file, then Pint, then Rector's type rules
     * over what is left, then Pint on what Rector changed.
     *
     * @param  array{findings: array<string, list<array{where: string, rule: string, message: string}>>, checked: list<string>, failed: array<string, string>}  $found
     */
    private function fixingOwn(AutomatedFixes $fixes, Studio $studio, LocalBranch $branch, array $found): void
    {
        $own = $this->fixing($fixes, $studio, $found['findings']);
        $this->engine = $own['timings'];
        array_map(function (string $key) use ($own): void {
            $this->report[$key] = [...$this->report[$key], 'fixed' => count($own['fixed'][$key] ?? []), 'kinds' => $own['kinds'][$key] ?? []];
        }, array_keys($found['findings']));

        if ($this->picks('pint')) {
            $this->pulse('fixing', 'tidying with Pint');
            $this->timed('Pint on the fixed files', fn (): null => $fixes->tidy($own['saved']), fn (): string => count($own['saved']).' files');
        }

        $fixed = array_sum(array_map(array_sum(...), $own['kinds']));
        $names = implode(', ', array_map(fn (string $key): string => $this->report[$key]['name'], array_keys($found['findings'])));
        $commit = $this->timed('Commit the fixes', fn (): ?string => $this->commit($branch, sprintf(Says::named('fix: {name} fixed %s %s %s automatically'), number_format($fixed), $names, $fixed === 1 ? 'finding' : 'findings'), $own['kinds']), fn (?string $sha): string => $sha === null ? 'nothing to commit' : substr($sha, 0, 7));
        $this->finish(array_keys($found['findings']), $commit, array_map(intval(...), $own['files']));
        $this->sendReport();
        $this->announceLeft($studio, $branch, $own['fixed']);
        $this->pulse('fixing', 'fixes committed');

        if (! $this->option('no-rector') && $this->picks('rector')) {
            $this->pulse('fixing', 'running Rector\'s type rules');
            $remaining = $this->unfixed($found['findings'], $own['fixed']);
            $tidied = $this->fingerprints($branch);
            $rector = $this->timed('Rector\'s type rules', fn (): array => $remaining === [] ? [] : $fixes->rector($remaining), fn (array $changed): string => number_format(array_sum($changed)).' files changed');
            $changed = $this->changedSince($branch, $tidied);
            $this->report['rector-types'] = ['name' => 'Rector\'s type rules', 'found' => null, 'fixed' => array_sum($rector), 'kinds' => $rector, 'note' => 'files changed, over what the fixers left'];
            $changed === [] || ! $this->picks('pint') || $this->timed('Pint on what Rector changed', fn (): null => $fixes->tidy($changed), fn (): string => count($changed).' files');
            $count = count($branch->changed());
            $count === 0 || $this->timed('Commit what Rector changed', fn (): ?string => $this->commit($branch, sprintf(Says::named('refactor(rector): {name} ran Rector\'s type rules on %d %s'), $count, $count === 1 ? 'file' : 'files'), [], "- Types Rector could prove\n- Rector's own rules, run on the files the scan flagged"), fn (?string $sha): string => $sha === null ? 'nothing to commit' : substr($sha, 0, 7));
        }
    }

    /**
     * Each changed file with a hash of what it holds, so what a later step
     * changes can be told even in a file that was already changed.
     *
     * @return array<string, string>
     */
    private function fingerprints(LocalBranch $branch): array
    {
        return collect($branch->changed())->mapWithKeys(fn (string $path): array => [$path => is_file(base_path($path)) ? hash_file('xxh128', base_path($path)) : ''])->all();
    }

    /**
     * @param  array<string, string>  $fingerprints
     * @return list<string>
     */
    private function changedSince(LocalBranch $branch, array $fingerprints): array
    {
        return array_values(array_keys(array_diff_assoc($this->fingerprints($branch), $fingerprints)));
    }

    /**
     * What an audit flagged, patched within the versions the project allows.
     */
    private function patching(AutomatedFixes $fixes, Toolbox $toolbox, LocalBranch $branch, string $key): void
    {
        $name = $toolbox->name($key);
        $this->progress?->running([$key]);
        app(ToolStatus::class)->started($key);
        $this->pulse('fixing', 'patching packages');
        $result = $this->timed($name.': patching packages', fn (): array => $fixes->security()->fix($key), fn (array $result): string => count($result['changed']).' patched'.($result['left'] === [] ? '' : ', '.count($result['left']).' left for a major update'));
        $result['ran'] || $this->issues[] = $name.': '.($result['reason'] ?? 'nothing was patched.');
        $this->report[$key] = ['name' => $name, 'found' => count($result['changed']) + count($result['left']), 'fixed' => count($result['changed']), 'kinds' => $result['kinds'] ?? [], 'note' => 'packages'.($result['left'] === [] ? '' : ', the rest need a major update')];
        $patched = count($result['changed']);
        $commit = $this->commit($branch, sprintf(Says::named('fix(security): {name} patched %d %s for %s'), $patched, $patched === 1 ? 'package' : 'packages', $name), [], '- Patched within the versions the project allows');
        $this->finish([$key], $commit);
        $this->sendReport();
        app(ToolStatus::class)->ran($key, ['ran' => $result['ran'], 'reason' => (string) ($result['reason'] ?? ''), 'findings' => array_fill(0, count($result['left']), [])]);
        $this->progress?->left($key, count($result['left']));
        $this->pulse('fixing', 'packages patched');
    }

    /**
     * A tool's own fixer, run on the project the way the tool does it.
     */
    private function running(AutomatedFixes $fixes, Toolbox $toolbox, LocalBranch $branch, string $key): void
    {
        $name = $toolbox->name($key);
        $was = $this->fingerprints($branch);
        $this->progress?->running([$key]);
        app(ToolStatus::class)->started($key);
        $this->pulse('fixing', 'running '.$name);
        $result = $this->timed($name.'\'s own fixer', fn (): array => $toolbox->fix($key), fn (): string => count($this->changedSince($branch, $was)).' files changed');
        $result['ran'] || $this->issues[] = $name.': '.($result['reason'] ?? 'it did not finish.');
        $changed = $this->changedSince($branch, $was);
        $this->report[$key] = ['name' => $name, 'found' => null, 'fixed' => count($changed), 'kinds' => [], 'note' => 'files changed; it does not say how many findings it had'];
        $key === 'pint' || ! $this->picks('pint') || $changed === [] || $this->timed('Pint on what '.$name.' changed', fn (): null => $fixes->tidy($changed), fn (): string => count($changed).' files');
        $files = count($branch->changed());
        $commit = $this->commit($branch, sprintf(Says::named('%s(%s): {name} ran %s\'s own fixer on %d %s'), $key === 'pint' ? 'style' : 'refactor', $key, $name, $files, $files === 1 ? 'file' : 'files'), [], '- '.$name.'\'s own fixer, with your project\'s own config');
        $this->finish([$key], $commit, [$key => $files]);
        $this->sendReport();
        app(ToolStatus::class)->ran($key, ['ran' => $result['ran'], 'reason' => (string) ($result['reason'] ?? ''), 'findings' => [], 'took' => $result['took'] ?? null]);
        $this->progress?->left($key, null);
        $this->pulse('fixing', $name.' committed');
    }

    /**
     * The rules the prepare-scan screen offers this project that Artisan Studio
     * can fix: a tool that is switched off for good, not installed or unused is
     * not there, as on the screen. Every rule is ticked to begin with, so the
     * ones not wanted are unticked.
     *
     * @return list<string>
     */
    private function rules(AutomatedFixes $fixes, Toolbox $toolbox): array
    {
        $offered = $fixes->fixable((array) config('studio-cli.offered'));
        $given = array_values(array_filter((array) $this->option('tool'), is_string(...)));

        return match (true) {
            $given !== [] => array_values(array_intersect($offered, $given)),
            ! $this->input->isInteractive() => $offered,
            default => array_values(multiselect(
                label: 'Which rules should be fixed?',
                options: array_combine($offered, array_map($toolbox->name(...), $offered)),
                default: $offered,
                scroll: 12,
                hint: 'Space unticks, enter starts. Nothing is committed or staged.',
            )),
        };
    }

    /**
     * The file-by-file step, with a bar that moves a batch at a time and shows
     * the batch, the round, the fixes so far, the time gone and what had to be
     * retried or failed.
     *
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     * @return array<string, mixed>
     */
    private function fixing(AutomatedFixes $fixes, Studio $studio, array $findings): array
    {
        $started = hrtime(true);
        $bar = progress(label: 'Fix file by file', steps: 1);
        $state = ['batches' => 1, 'batch' => 0, 'round' => 0, 'fixes' => 0, 'retries' => 0, 'failed' => 0];
        $bar->start();
        $show = function () use ($bar, &$state, $started): void {
            $bar->hint(sprintf('batch %d/%d · round %d · %s fixes · %s gone · %d retries · %d failed', $state['batch'], $state['batches'], $state['round'], number_format($state['fixes']), $this->seconds((int) ((hrtime(true) - $started) / 1_000_000)), $state['retries'], $state['failed']));
        };

        $own = $fixes->fileByFile($findings, null, function (array $event) use ($bar, &$state, $show): void {
            match ($event['type']) {
                'planned' => $bar->total = max(1, (int) $event['batches']),
                'round' => [$state['batch'], $state['round']] = [(int) $event['batch'], (int) $event['round']],
                'retry' => $this->retried($state, $event),
                'failed' => $this->failed($state, $event),
                'unfinished' => $this->unfinished($state, $event),
                'batch' => $this->finished($bar, $state, $event),
                default => null,
            };
            $show();
        });

        $bar->finish();
        $this->components->twoColumnDetail(sprintf('Fix file by file <fg=gray>%s fixes in %d files, %d requests, %s KB sent</>', number_format(array_sum(array_map(array_sum(...), $own['kinds']))), count($own['saved']), $own['requests'], number_format($own['sent'] / 1000)), '<fg=green;options=bold>'.$this->seconds((int) ((hrtime(true) - $started) / 1_000_000)).'</>');

        return $own;
    }

    /**
     * @param  array<string, int>  $state
     * @param  array<string, mixed>  $event
     */
    private function retried(array &$state, array $event): void
    {
        $state['retries']++;
        $this->issues[] = sprintf('Batch %d, round %d: the fixers asked for %d more files and %d searches, so the %d findings that waited went again with %d more files (retry).', $event['batch'], $event['round'], $event['reads'], $event['searches'], $event['findings'], $event['sent']);
    }

    /**
     * @param  array<string, int>  $state
     * @param  array<string, mixed>  $event
     */
    private function unfinished(array &$state, array $event): void
    {
        $state['failed']++;
        $this->issues[] = sprintf('Batch %d: %d findings still waited for more of the project after %d rounds, so they were left as they were.', $event['batch'], $event['findings'], $event['round']);
    }

    /**
     * @param  array<string, int>  $state
     * @param  array<string, mixed>  $event
     */
    private function failed(array &$state, array $event): void
    {
        $state['failed']++;
        $this->issues[] = sprintf('Batch %d, round %d FAILED: %s', $event['batch'], $event['round'], $event['reason']);
    }

    /**
     * @param  array<string, int>  $state
     * @param  array<string, mixed>  $event
     */
    private function finished(object $bar, array &$state, array $event): void
    {
        $state['fixes'] += (int) $event['fixes'];
        $bar->advance();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @param  Closure(T): string  $detail
     * @return T
     */
    private function timed(string $label, Closure $work, Closure $detail): mixed
    {
        $started = hrtime(true);
        $result = spin($work, $label.'…');
        $this->components->twoColumnDetail($label.' <fg=gray>'.$detail($result).'</>', '<fg=green;options=bold>'.$this->seconds((int) ((hrtime(true) - $started) / 1_000_000)).'</>');

        return $result;
    }

    /**
     * The findings the fixers left, so Rector only looks at what is still there.
     *
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $findings
     * @param  array<string, list<array{where: string, rule: string, message: string}>>  $fixed
     * @return array<string, list<array{where: string, rule: string, message: string}>>
     */
    private function unfixed(array $findings, array $fixed): array
    {
        $identity = fn (array $finding): string => $finding['where'].'|'.$finding['rule'].'|'.$finding['message'];

        return collect($findings)
            ->only(RectorRules::keys())
            ->map(fn (array $list, string $key): array => array_values(array_diff_key(
                array_combine(array_map($identity, $list), $list),
                array_flip(array_map($identity, $fixed[$key] ?? [])),
            )))
            ->filter()
            ->all();
    }

    private function summary(FixTimeline $timeline, int $milliseconds, int $files): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=white;options=bold>Findings and fixes, by rule</>', '');
        $this->table(
            ['Rule', 'Found', 'Fixed', 'Left', 'What'],
            collect($this->report)->map(fn (array $row): array => [
                $row['name'],
                $row['found'] === null ? '—' : number_format($row['found']),
                number_format($row['fixed']),
                $row['found'] === null ? '—' : number_format(max(0, $row['found'] - $row['fixed'])),
                $row['note'],
            ])->values()->all(),
        );
        collect($this->report)->filter(fn (array $row): bool => $row['kinds'] !== [])->each(function (array $row): void {
            $this->components->twoColumnDetail('<fg=white;options=bold>'.$row['name'].'</>', '<fg=gray>fixes made, by kind</>');
            collect($row['kinds'])->sortDesc()->each(fn (int $count, string $kind): null => $this->components->twoColumnDetail($kind, number_format($count)));
        });
        $this->newLine();
        $this->components->twoColumnDetail('<fg=white;options=bold>Inside "Fix file by file"</>', '<fg=gray>time, and how many times</>');
        collect($timeline->last()['steps'] ?? [])->each(fn (array $step): null => $this->components->twoColumnDetail($step['step'], $this->seconds($step['ms']).' <fg=gray>×'.$step['count'].'</>'));
        $this->engineBreakdown();
        $this->newLine();
        $this->components->twoColumnDetail('<fg=white;options=bold>Issues and retries</>', $this->issues === [] ? '<fg=green>none</>' : '<fg=yellow>'.count($this->issues).'</>');
        collect($this->issues)->each(fn (string $issue): null => $this->line('  '.$issue));
        $this->newLine();
        $this->components->info(sprintf('%s in all. %d files changed on %s. Look with git diff --stat %s..HEAD; undo with git switch %s && git branch -D %s.', $this->seconds($milliseconds), $files, $this->run, $this->base ?? 'HEAD', $this->from ?? '-', $this->run));
    }

    /**
     * Which rules the studio's fixers spent their time on, and which fixers.
     * "Asked" is how many findings had to wait for another file or a search.
     */
    private function engineBreakdown(): void
    {
        if (($this->engine['rules'] ?? []) === []) {
            return;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=white;options=bold>The studio\'s fixers, time by rule</>', '<fg=gray>'.$this->seconds((int) ($this->engine['ms'] ?? 0)).' in all, '.$this->seconds((int) ($this->engine['parsing_ms'] ?? 0)).' of it reading files</>');
        $this->table(
            ['Rule', 'Time', 'Findings', 'Fixed', 'Asked for more'],
            collect($this->engine['rules'])->take(14)->map(fn (array $row): array => [
                $row['key'].' · '.$row['rule'],
                $this->seconds((int) $row['ms']),
                number_format((int) $row['findings']),
                number_format((int) $row['fixed']),
                number_format((int) $row['asked']),
            ])->all(),
        );
        $this->components->twoColumnDetail('<fg=white;options=bold>Time by fixer</>', '<fg=gray>calls · fixes made</>');
        collect($this->engine['fixers'] ?? [])->take(8)->each(fn (array $row): null => $this->components->twoColumnDetail($row['fixer'], $this->seconds((int) $row['ms']).' <fg=gray>'.number_format((int) $row['calls']).' · '.number_format((int) $row['fixed']).'</>'));
        $this->components->twoColumnDetail('<fg=white;options=bold>Time by phase</>', '');
        collect($this->engine['phases'] ?? [])->each(fn (int $ms, string $phase): null => $this->components->twoColumnDetail($phase, $this->seconds($ms)));
    }

    private function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 1).'s';
    }
}
