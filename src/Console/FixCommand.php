<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\BackgroundTasks;
use ArtisanStudio\StudioCli\Fix\AutomatedFixes;
use ArtisanStudio\StudioCli\Fix\FixProgress;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Fix\RectorRules;
use ArtisanStudio\StudioCli\Fix\Security\TargetedSecurityUpdates;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Scan\LastFindings;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;

/**
 * Fixes the rules asked for on a new local branch, one commit per rule, and
 * checks each rule again the moment its commit lands, so the health score
 * moves card by card instead of at the end.
 *
 * The npm patches run beside the studio's own fixes, since they touch only the
 * front end's manifest and lockfile. Composer runs last, in a process of its
 * own: it replaces the vendor folder, and nothing may load code from it
 * after that but a fresh process.
 */
class FixCommand extends Command
{
    public const string SIGNATURE = 'studio:fix';

    private const string NODE = 'node-audit';

    private const string COMPOSER = 'composer-audit';

    private const int BESIDE_TIMEOUT = 1800;

    private const float COUNT_EVERY_SECONDS = 0.5;

    private const float HEARTBEAT_EVERY_SECONDS = 1.5;

    protected $signature = self::SIGNATURE.'
        {--tool=* : Only these rules, by key}
        {--yes : Fix without asking again, because you already said yes on the Dashboard}
        {--joining : Fix these rules on the branch a run already started, beside it}
        {--no-rescan : Leave the scan alone afterwards}
        {--plain : Print one plain line with the outcome}';

    protected $description = 'Fix what your checking tools found, on a new local branch with one commit per rule, checking each rule again as its commit lands';

    private ?FixProgress $progress = null;

    private bool $rescored = false;

    /**
     * @var array<string, array<string, int>>
     */
    private array $foundIn = [];

    public function handle(Studio $studio, TaskJournal $journal): int
    {
        if (getenv(BackgroundTasks::DETACHED) === '1' && function_exists('posix_setsid')) {
            @posix_setsid();
        }

        if (! $studio->isLinked()) {
            return $this->outcome('This project is not linked yet. Run php artisan studio:settings, then try again.', self::SUCCESS);
        }

        $fromTheStudio = $studio->scanFixes() ?? [];
        $asked = array_values(array_filter((array) $this->option('tool'), is_string(...))) ?: $fromTheStudio;
        $toolbox = new Toolbox;
        $fixes = new AutomatedFixes($toolbox, base_path(), $studio);
        $keys = $fixes->fixable($asked);
        $branch = new LocalBranch(base_path());
        $this->progress = new FixProgress(base_path());
        $this->progress->listen($this->heartbeat($studio));

        if ($this->option('joining')) {
            return $this->join($keys, $studio, $toolbox, $fixes, $branch, $journal);
        }

        if ($keys === []) {
            return $this->outcome($asked === [] ? 'Nothing was asked to be fixed.' : Says::named('{name} cannot fix ').implode(', ', array_map($toolbox->name(...), $asked)).' automatically yet.', self::SUCCESS);
        }

        $refused = match (true) {
            ! $branch->isRepository() => 'This project is not a git repository, so there is no safe branch to fix on.',
            ! $branch->isClean() => Says::named('You have uncommitted changes. Commit or stash them first, so {name}\'s fixes stay apart from yours.'),
            default => null,
        };

        if ($refused !== null) {
            $this->progress->refused($refused, $keys);
            $studio->submitScanFixReport(['refused' => $refused]);

            return $this->outcome($refused, self::FAILURE);
        }

        $names = implode(', ', array_map($toolbox->name(...), $keys));

        if (! $this->option('yes') && (! $this->input->isInteractive() || ! confirm('Fix '.$names.' on a new branch? Each rule gets its own commit, and nothing is pushed.', default: true))) {
            return $this->outcome('Nothing was changed.', self::SUCCESS);
        }

        $from = $branch->current();
        $base = $branch->head();
        $started = $branch->start(Carbon::now()->format('Y-m-d-Hi'));

        if ($started === null) {
            return $this->outcome('Could not start a new branch, so nothing was changed.', self::FAILURE);
        }

        $this->step($journal, 'Fixing '.$names.' on '.$started.', a new branch from '.($from ?? 'where you are').'.');

        $kept = new LastFindings(base_path());
        $order = self::inOrder($keys);
        $this->foundIn = array_combine($order, array_map(fn (string $key): array => collect($kept->for($key) ?? [])->countBy(fn (array $finding): string => (string) preg_replace('/:\d+$/', '', $finding['where']))->all(), $order));
        $this->progress->begin($started, $order, array_combine($order, array_map(fn (string $key): int => AutomatedFixes::counted($key, $kept->for($key) ?? []), $order)), $base);
        $this->progress->scanned(array_combine($order, array_map(fn (string $key): array => $kept->for($key) ?? [], $order)));
        $report = fn (): array => ['branch' => $started, 'from' => $from, 'rulesets' => $this->progress?->reports() ?? [], 'diffs' => $base === null ? [] : $branch->changesSince($base)];

        $node = in_array(self::NODE, $keys, true) ? $this->beside(self::NODE) : null;
        $own = array_values(array_intersect(AutomatedFixes::own($toolbox), $keys));

        $this->settle($this->fixOwn($own, $toolbox, $fixes, $branch, $journal), $studio, $branch, $journal, $report);

        array_map(function (string $key) use ($toolbox, $fixes, $branch, $journal, $studio, $report): void {
            $this->settle([$key => $this->fixWithTool($key, $toolbox, $fixes, $branch, $journal)], $studio, $branch, $journal, $report);
        }, array_values(array_intersect(AutomatedFixes::tools($toolbox), $keys)));

        if ($node !== null) {
            $this->step($journal, 'Waiting for the npm patches to finish beside the rest…');
            $node->wait();
            $this->stoppedUnlessDone(self::NODE, $node, $journal);
        }

        if (in_array(self::COMPOSER, $keys, true)) {
            $composer = $this->beside(self::COMPOSER);
            $composer->wait();
            $this->stoppedUnlessDone(self::COMPOSER, $composer, $journal);
        }

        $this->rescored = $this->rescored || (! $this->option('no-rescan') && ($node !== null || in_array(self::COMPOSER, $keys, true)));
        $this->sayRescored($journal);
        $this->progress->finished([]);
        $committed = collect($this->progress->reports())->whereNotNull('commit')->count();

        return $this->outcome(sprintf('%s on %s: %d of %d rules changed something. Nothing was pushed.', $committed === 0 ? 'Nothing needed changing' : 'Fixed', $started, $committed, count($keys)), self::SUCCESS);
    }

    /**
     * Tells the studio where the run is, so Insights can follow it live: at
     * once when a rule changes state, otherwise at most every so often.
     *
     * @return Closure(array<string, mixed>): void
     */
    private function heartbeat(Studio $studio): Closure
    {
        $sent = ['at' => 0.0, 'states' => ''];

        return function (array $progress) use ($studio, &$sent): void {
            $live = FixProgress::live($progress);
            $states = $live['phase'].'|'.implode(',', array_column($live['rulesets'], 'state'));
            $now = microtime(true);

            if ((string) ($progress['branch'] ?? '') === '' || ! in_array($live['phase'], [FixProgress::FIXING, FixProgress::CHECKING, FixProgress::FINISHED], true) || ($states === $sent['states'] && $now - $sent['at'] < self::HEARTBEAT_EVERY_SECONDS)) {
                return;
            }

            $sent = ['at' => $now, 'states' => $states];
            $studio->submitScanFixReport(['branch' => (string) $progress['branch'], 'live' => $live]);
        };
    }

    /**
     * The order the rules run in, which is the order their cards show: the
     * studio's own fixes and the npm patches beside them, then the tools' own fixers,
     * then Composer.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function inOrder(array $keys): array
    {
        return array_values(array_filter([...AutomatedFixes::own(), self::NODE, ...AutomatedFixes::tools(), self::COMPOSER], fn (string $key): bool => in_array($key, $keys, true)));
    }

    /**
     * A rule fixed by a process of its own, on the same branch.
     */
    private function beside(string $key): Process
    {
        $process = new Process([PHP_BINARY, 'artisan', self::SIGNATURE, '--tool='.$key, '--joining', '--yes', '--plain', '--no-ansi', '--no-interaction', ...($this->option('no-rescan') ? ['--no-rescan'] : [])], base_path(), timeout: self::BESIDE_TIMEOUT);
        $process->start();

        return $process;
    }

    /**
     * A rule fixed beside the run whose process ended without saying how it
     * went has stopped: its card says so rather than spinning on.
     */
    private function stoppedUnlessDone(string $key, Process $process, TaskJournal $journal): void
    {
        $progress = $this->progress;
        $ruleset = $progress?->read()['rulesets'][$key] ?? null;

        if ($progress === null || $ruleset === null) {
            return;
        }

        if (in_array($ruleset['state'], [FixProgress::WAITING, FixProgress::RUNNING], true)) {
            $progress->done($key, false, 0, 0);
            $progress->left($key, null);
            $this->step($journal, 'The '.$key.' fix stopped before it finished: '.(trim($process->getErrorOutput()) !== '' ? mb_substr(trim($process->getErrorOutput()), -200) : 'it ended without a word.'));
        }

        if ($ruleset['rechecking']) {
            $progress->left($key, null);
        }
    }

    /**
     * Fixing beside a run: the security rules, each committed with only its
     * own files.
     *
     * @param  list<string>  $keys
     */
    private function join(array $keys, Studio $studio, Toolbox $toolbox, AutomatedFixes $fixes, LocalBranch $branch, TaskJournal $journal): int
    {
        $started = $this->progress?->read()['branch'] ?? $branch->current();
        $base = $this->progress?->read()['base'] ?? null;
        $report = fn (): array => ['branch' => $started, 'from' => null, 'rulesets' => $this->progress?->reports() ?? [], 'diffs' => $base === null ? [] : $branch->changesSince($base)];

        array_map(function (string $key) use ($toolbox, $fixes, $branch, $journal, $studio, $report): void {
            $this->settle([$key => $this->fixSecurity($key, $toolbox, $fixes, $branch, $journal)], $studio, $branch, $journal, $report);
        }, array_values(array_intersect(AutomatedFixes::SECURITY, $keys)));

        return $this->outcome('Patched beside the run.', self::SUCCESS);
    }

    /**
     * @param  array<string, list<array{where: string, rule: string, message: string}>|null>  $left
     * @param  callable(): array<string, mixed>  $report
     */
    private function settle(array $left, Studio $studio, LocalBranch $branch, TaskJournal $journal, callable $report): void
    {
        $left = array_filter($left, is_array(...));

        if ($left === []) {
            return;
        }

        array_map(fn (string $key, array $findings): mixed => $this->progress?->left($key, AutomatedFixes::counted($key, $findings)), array_keys($left), $left);

        if ($this->option('no-rescan')) {
            return;
        }

        $studio->submitScanFixReport($report());
        $studio->submitScanToolResults(['commit' => $branch->head(), 'partial' => true, 'tools' => array_map(fn (array $findings): array => ['ran' => true, 'findings' => $findings], $left)]);
        $this->rescored = true;
    }

    private function sayRescored(TaskJournal $journal): void
    {
        if ($this->rescored) {
            $this->step($journal, Says::named('Your score has been updated with what {name} fixed. Your next scan checks it all again.'));
        }
    }

    /**
     * @param  list<array{where: string, rule: string, message: string}>  $found
     * @param  list<array{where: string, rule: string, message: string}>  $fixed
     * @return list<array{where: string, rule: string, message: string}>
     */
    private static function without(array $found, array $fixed): array
    {
        $gone = array_count_values(array_map(self::identity(...), $fixed));

        return array_values(array_filter($found, function (array $finding) use (&$gone): bool {
            $identity = self::identity($finding);

            if (($gone[$identity] ?? 0) === 0) {
                return true;
            }

            $gone[$identity]--;

            return false;
        }));
    }

    /**
     * @param  array{where: string, rule: string, message: string}  $finding
     */
    private static function identity(array $finding): string
    {
        return $finding['where']."\0".$finding['rule']."\0".$finding['message'];
    }

    /**
     * @param  array{where: string, rule: string, message: string}  $finding
     */
    private static function fileOf(array $finding): string
    {
        return (string) preg_replace('/:\d+$/', '', $finding['where']);
    }

    /**
     * The studio's own fixes for every rule at once, file by file, from what the
     * scan found, then Rector's type rules over the same files.
     *
     * @param  list<string>  $keys
     * @return array<string, list<array{where: string, rule: string, message: string}>>
     */
    private function fixOwn(array $keys, Toolbox $toolbox, AutomatedFixes $fixes, LocalBranch $branch, TaskJournal $journal): array
    {
        if ($keys === [] || $this->hasLeft($branch, $keys, $journal)) {
            return [];
        }

        $started = hrtime(true);
        $this->progress?->running($keys);
        $names = implode(' and ', array_map($toolbox->name(...), $keys));
        $found = $fixes->findings($keys);

        $this->step($journal, $found['checked'] === []
            ? 'Starting from what your scan found, so nothing is checked twice.'
            : 'Checked '.implode(' and ', array_map($toolbox->name(...), $found['checked'])).' once, all at the same time.');

        $this->step($journal, 'Fixing '.$names.' file by file: every fix a file needs, written once.');
        $counts = [];
        $paths = [];
        $shown = 0.0;
        $own = $fixes->fileByFile($found['findings'], function (string $key, string $path) use (&$counts, &$paths, &$shown): void {
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $paths[$key][$path] = ($paths[$key][$path] ?? 0) + 1;

            if (microtime(true) - $shown >= self::COUNT_EVERY_SECONDS) {
                $shown = microtime(true);
                $this->progress?->fixing($counts, $paths);
            }
        });
        $this->progress?->fixing($counts, $paths);
        $found['findings'] = isset($found['findings'][AutomatedFixes::ARCH])
            ? [...$found['findings'], AutomatedFixes::ARCH => [...$found['findings'][AutomatedFixes::ARCH], ...$own['swept']]]
            : $found['findings'];
        $this->step($journal, $this->sentLine($own));
        $fixes->tidy($branch->changed(except: TargetedSecurityUpdates::NODE_FILES));
        $fixed = array_sum(array_map(array_sum(...), $own['kinds']));
        $files = count($branch->changed(except: TargetedSecurityUpdates::NODE_FILES));
        $commit = $files === 0 ? null : $branch->commit(
            sprintf(Says::named('fix: {name} fixed %s %s %s automatically'), number_format($fixed), $names, $fixed === 1 ? 'finding' : 'findings'),
            $this->body($own['kinds'], $toolbox),
            except: TargetedSecurityUpdates::NODE_FILES,
        );

        $this->step($journal, $commit === null
            ? $names.Says::named(': nothing {name} could fix automatically.')
            : sprintf('%s: %s fixed in %d %s, committed.', $names, number_format($fixed), $files, $files === 1 ? 'file' : 'files'));

        $this->step($journal, 'Running Rector\'s type rules over the same files, in one go…');
        $byRector = $fixes->rector(collect($found['findings'])
            ->only(RectorRules::keys())
            ->map(fn (array $findings, string $key): array => self::without($findings, $own['fixed'][$key] ?? []))
            ->filter()
            ->all());
        $fixes->tidy($branch->changed(except: TargetedSecurityUpdates::NODE_FILES));
        $rectorFiles = count($branch->changed(except: TargetedSecurityUpdates::NODE_FILES));
        $rectorCommit = $rectorFiles === 0 ? null : $branch->commit(
            sprintf(Says::named('refactor(rector): {name} ran Rector\'s type rules on %d %s'), $rectorFiles, $rectorFiles === 1 ? 'file' : 'files'),
            "- Types Rector could prove, for {$names}\n- Rector's own rules, run on the files the scan flagged",
            except: TargetedSecurityUpdates::NODE_FILES,
        );

        if ($rectorCommit !== null) {
            $this->step($journal, sprintf('Rector typed %d more %s, committed.', $rectorFiles, $rectorFiles === 1 ? 'file' : 'files'));
        }

        $took = (int) ((hrtime(true) - $started) / 1_000_000);

        array_map(function (string $key, int $index) use ($found, $own, $byRector, $commit, $rectorCommit, $took, $toolbox): void {
            $this->progress?->done($key, ! isset($found['failed'][$key]), array_sum($own['kinds'][$key] ?? []), $own['files'][$key] ?? 0, count($found['findings'][$key] ?? []));
            $this->progress?->report($key, [
                'name' => $toolbox->name($key),
                'ran' => ! isset($found['failed'][$key]),
                'reason' => $found['failed'][$key] ?? null,
                'fixed' => array_sum($own['kinds'][$key] ?? []),
                'kinds' => [...($own['kinds'][$key] ?? []), ...($index === 0 ? $byRector : [])],
                'setup' => [],
                'files' => $own['files'][$key] ?? 0,
                'commit' => $commit ?? $rectorCommit,
                'took' => $took,
            ]);
        }, $keys, array_keys($keys));

        return collect($keys)
            ->reject(fn (string $key): bool => isset($found['failed'][$key]))
            ->mapWithKeys(fn (string $key): array => [$key => self::without($found['findings'][$key] ?? [], $own['fixed'][$key] ?? [])])
            ->all();
    }

    /**
     * @param  list<string>  $keys
     */
    private function hasLeft(LocalBranch $branch, array $keys, TaskJournal $journal): bool
    {
        if ($branch->isOnFixes()) {
            return false;
        }

        array_map(fn (string $key): mixed => $this->progress?->done($key, false, 0, 0), $keys);
        $this->step($journal, Says::named('You switched away from {name}\'s branch, so the fixes stopped there and nothing more was changed.'));

        return true;
    }

    /**
     * @return list<array{where: string, rule: string, message: string}>|null
     */
    private function fixWithTool(string $key, Toolbox $toolbox, AutomatedFixes $fixes, LocalBranch $branch, TaskJournal $journal): ?array
    {
        if ($this->hasLeft($branch, [$key], $journal)) {
            return null;
        }

        $name = $toolbox->name($key);
        $this->step($journal, 'Running '.$name.'\'s own fixer…');
        $this->progress?->running([$key]);
        $result = $toolbox->fix($key);

        if ($key !== 'pint') {
            $fixes->tidy($branch->changed(except: TargetedSecurityUpdates::NODE_FILES));
        }

        $changed = $branch->changed(except: TargetedSecurityUpdates::NODE_FILES);
        $files = count($changed);
        $this->progress?->fixedIn($key, array_intersect_key($this->foundIn[$key] ?? [], array_flip($changed)));
        $commit = $files === 0 ? null : $branch->commit(
            sprintf(Says::named('%s(%s): {name} ran %s\'s own fixer on %d %s'), $key === 'pint' ? 'style' : 'refactor', $key, $name, $files, $files === 1 ? 'file' : 'files'),
            '- '.$name.'\'s own fixer, with your project\'s own config',
            except: TargetedSecurityUpdates::NODE_FILES,
        );

        $this->step($journal, match (true) {
            ! $result['ran'] && $commit !== null => $name.': '.$files.' '.($files === 1 ? 'file' : 'files').' committed, but it stopped with an error: '.($result['reason'] ?? 'it did not finish.'),
            ! $result['ran'] => $name.' was not fixed: '.($result['reason'] ?? 'it did not run.'),
            $commit === null && $files > 0 => $name.' changed '.$files.' files, but they could not be committed.',
            $commit === null => $name.' had nothing to fix.',
            default => $name.': '.$files.' '.($files === 1 ? 'file' : 'files').', committed.',
        });

        $this->progress?->done($key, $result['ran'], 0, $files);
        $this->progress?->report($key, [
            'name' => $name,
            'ran' => $result['ran'],
            'reason' => $result['reason'] ?? null,
            'fixed' => 0,
            'kinds' => [],
            'setup' => [],
            'files' => $files,
            'commit' => $commit,
            'took' => (int) ($result['took'] ?? 0),
        ]);

        return ! $result['ran'] && $commit === null
            ? null
            : array_values(array_filter($this->progress?->scannedFor($key) ?? [], fn (array $finding): bool => ! in_array(self::fileOf($finding), $changed, true)));
    }

    /**
     * Patches what an audit flags, within the versions the project allows,
     * and keeps it only if the front end still builds. Composer's update can
     * publish new files, such as a package's assets, and they go in its commit.
     *
     * @return list<array{where: string, rule: string, message: string}>|null
     */
    private function fixSecurity(string $key, Toolbox $toolbox, AutomatedFixes $fixes, LocalBranch $branch, TaskJournal $journal): ?array
    {
        if ($this->hasLeft($branch, [$key], $journal)) {
            return null;
        }

        $name = $toolbox->name($key);
        $started = hrtime(true);
        $this->step($journal, 'Patching the packages '.$name.' flagged, within the versions you allow…');
        $this->progress?->running([$key]);
        $untracked = $branch->untracked();
        $result = $fixes->security()->fix($key);
        $node = $key === self::NODE;
        $published = $node ? [] : array_values(array_diff($branch->untracked(), $untracked));
        $files = count($node ? $branch->changed(only: TargetedSecurityUpdates::NODE_FILES) : $branch->changed(except: TargetedSecurityUpdates::NODE_FILES)) + count($published);
        $commit = $files === 0 || ! $result['ran'] ? null : $branch->commit(
            sprintf(Says::named('fix(security): {name} patched %d vulnerable %s within their versions'), count($result['changed']), count($result['changed']) === 1 ? 'package' : 'packages'),
            collect($result['changed'])->map(fn (string $change): string => '- '.$change)
                ->merge(collect($result['left'])->map(fn (string $left): string => '- Left for a major update: '.$left))
                ->push('- The audit\'s own fixed versions, each kept within the major version you are on')
                ->when(! $node, fn (Collection $lines): Collection => $lines->push('- Only composer.lock changed: run composer install to use them'))
                ->implode("\n"),
            only: $node ? TargetedSecurityUpdates::NODE_FILES : [],
            except: $node ? [] : TargetedSecurityUpdates::NODE_FILES,
            new: $published,
        );

        $this->step($journal, match (true) {
            ! $result['ran'] => $name.': '.($result['reason'] ?? 'nothing was patched.'),
            $commit === null => $name.Says::named(': nothing {name} could patch within your versions.'),
            default => sprintf('%s: %d %s patched, committed.%s%s', $name, count($result['changed']), count($result['changed']) === 1 ? 'package' : 'packages', $result['left'] === [] ? '' : ' Left for a major update: '.implode(', ', $result['left']).'.', $node ? '' : ' Only composer.lock changed: run composer install to use them.'),
        });

        $this->progress?->done($key, $result['ran'], $result['fixed'], $files);
        $this->progress?->report($key, [
            'name' => $name,
            'ran' => $result['ran'],
            'reason' => $result['reason'] ?? null,
            'fixed' => $result['fixed'],
            'kinds' => $result['kinds'],
            'setup' => [],
            'files' => $files,
            'commit' => $commit,
            'took' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        $patched = array_map(fn (string $change): string => explode(' ', $change)[0], $commit === null ? [] : $result['changed']);

        return $result['ran']
            ? array_values(array_filter($this->progress?->scannedFor($key) ?? [], fn (array $finding): bool => ! in_array(explode(' ', $finding['message'])[0], $patched, true)))
            : null;
    }

    /**
     * How the flagged files went to the studio and back, so the time a run
     * takes can be weighed against what it sent.
     *
     * @param  array{requests: int, sent: int, failed: int}  $own
     */
    private function sentLine(array $own): string
    {
        $sent = sprintf('Sent the flagged files to the studio in %d %s (%s MB), and nothing is kept there.', $own['requests'], $own['requests'] === 1 ? 'request' : 'requests', number_format($own['sent'] / 1_048_576, 1));

        return $own['failed'] === 0
            ? $sent
            : $sent.sprintf(' %d %s could not reach the studio, so those files stay as they were.', $own['failed'], $own['failed'] === 1 ? 'batch' : 'batches');
    }

    /**
     * @param  array<string, array<string, int>>  $kinds
     */
    private function body(array $kinds, Toolbox $toolbox): string
    {
        return collect($kinds)
            ->flatMap(fn (array $byLabel, string $key): array => collect($byLabel)
                ->map(fn (int $count, string $label): string => '- '.$toolbox->name($key).': '.number_format($count).' '.$label)
                ->values()
                ->all())
            ->push('- Every change is a fixed rule applied at the line the tool reported, file by file')
            ->implode("\n");
    }

    private function step(TaskJournal $journal, string $line): void
    {
        $journal->note($line);

        if (! $this->option('plain')) {
            $this->components->twoColumnDetail($line);
        }
    }

    private function outcome(string $line, int $code): int
    {
        match (true) {
            (bool) $this->option('plain') => $this->line($line),
            $code === self::SUCCESS => $this->components->info($line),
            default => $this->components->error($line),
        };

        return $code;
    }
}
