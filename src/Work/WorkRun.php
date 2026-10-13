<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Scan\LastFindings;
use ArtisanStudio\StudioCli\Scan\Tools\Tests;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use ArtisanStudio\StudioCli\Work\Concerns\AsksTheDeveloper;
use ArtisanStudio\StudioCli\Work\Concerns\ChecksTheMachine;
use ArtisanStudio\StudioCli\Work\Concerns\ReportsToTheStudio;
use ArtisanStudio\StudioCli\Work\Concerns\StreamsFromTheStudio;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @phpstan-type Attempt array{ok: bool, reason: string, commit: ?string, fixed: int, left: int, patch: string, verdict: ?array<string, mixed>, paths: list<string>, aside: list<string>}
 * @phpstan-type Step array{files: list<array{path: string, findings: list<array{tool: string, rule: string, line: ?int, title: string, label?: string}>}>, rule: string, label: string}
 * @phpstan-type Finding array{where: string, rule: string, message: string, detail?: string}
 * @phpstan-type Verdict array{passed: bool, reason: string, fixed: int, left: int, unran: ?string, added: list<Finding>, results: array<string, array{ran: bool, findings?: list<Finding>}>, broke: list<string>}
 */
final class WorkRun
{
    use AsksTheDeveloper, ChecksTheMachine, ReportsToTheStudio, StreamsFromTheStudio;

    private const int LARGEST_FILE = 300_000;

    private const int PART_BYTES = 2_000_000;

    private const int PART_COMMITS = 100;

    /**
     * How many times a file goes to the studio in one attempt: once, then
     * again each time it names a file it needs to see.
     */
    private const int MOST_ROUNDS = 3;

    /**
     * How much the studio reads beside one file, in bytes.
     */
    private const int RELATED_BYTES = 120_000;

    /**
     * The most of what failed that goes with a second try, in characters:
     * what the studio takes.
     */
    private const int LONGEST_FAILED = 8_000;

    private const int MOST_SET_ASIDE_KINDS = 50;

    private const int LONGEST_PROPOSAL_NOTE = 400;

    private const int LONGEST_WHY = 600;

    private const int MOST_PROPOSALS_REPORTED = 50;

    private const string SECRET = '~(^|/)(\.env(\..*)?|auth\.json|id_[a-z0-9]+|[^/]+\.(pem|key|p12|pfx))$~i';

    public const string FIXES = 'fixes';

    public const string TESTS = 'tests';

    public const string FAILING_TESTS = 'failing-tests';

    public const string WORKS = 'works';

    public const string BROKE = 'broke';

    public const string STOP = 'stop';

    public const string APPROVE = 'approve';

    public const string LEAVE = 'leave';

    /**
     * @var array<string, string>
     */
    private const array JOBS = ['secrets' => 'Secrets', 'livewire' => 'Livewire safety', 'types' => 'Types', 'clean-code' => 'Clean code', 'failing-tests' => 'Failing tests', 'leftovers' => 'Leftovers', self::TESTS => 'Tests for my fixes'];

    private ?string $branch = null;

    private ?string $base = null;

    private ?string $from = null;

    private string $job = '';

    private string $ruleKey = '';

    private int $tested = 0;

    /**
     * @var array<string, list<Finding>>
     */
    private array $baseline = [];

    /**
     * @var array<string, int>
     */
    private array $found = [];

    /**
     * @var array<string, int>
     */
    private array $fixed = [];

    /**
     * @var array<string, int>
     */
    private array $fixedInFile = [];

    /**
     * @var array<string, string>
     */
    private array $lastCommit = [];

    /**
     * @var array<string, string>
     */
    private array $ruleNames = [];

    /**
     * @var array<string, string>
     */
    private array $leftAsItWas = [];

    /**
     * @var array<string, string>
     */
    private array $notes = [];

    /**
     * @var array<string, string>
     */
    private array $putBack = [];

    /**
     * @var list<string>
     */
    private array $untested = [];

    /**
     * @var array<string, string>
     */
    private array $wrote = [];

    /**
     * @var array<string, string>
     */
    private array $decided = [];

    /**
     * The files the studio asked to see for each file, sent with it from then on.
     *
     * @var array<string, list<string>>
     */
    private array $wanted = [];

    /**
     * The files asked for in the round that just came back.
     *
     * @var array<string, list<string>>
     */
    private array $askedFor = [];

    /**
     * The files each file was sent with.
     *
     * @var array<string, list<string>>
     */
    private array $given = [];

    /**
     * While a check after a retry or a put-back runs, only the test files
     * that can have changed: the rest passed on files that have not changed
     * since.
     *
     * @var list<string>|null
     */
    private ?array $retesting = null;

    public function __construct(
        private readonly string $root,
        private readonly Studio $studio,
        private readonly Toolbox $toolbox,
        private readonly LocalBranch $git,
        private readonly WorkProgress $progress,
        private readonly TaskJournal $journal,
        private readonly int $pollSeconds = 1,
        private readonly ?Closure $check = null,
        private readonly int $pickupSeconds = 120,
        private readonly ?CoveringTests $tests = null,
    ) {}

    /**
     * @param  array<string, mixed>  $ask
     */
    public function run(array $ask): string
    {
        is_string($ask['author'] ?? null) && config(['studio-cli.author' => $ask['author']]);

        return match (true) {
            ($ask['mode'] ?? 'terminal') === 'app' => $this->apply($ask),
            ($ask['job'] ?? null) === self::TESTS => $this->tested($ask),
            default => $this->work($ask),
        };
    }

    /**
     * @param  array<string, mixed>  $ask
     */
    private function tested(array $ask): string
    {
        $this->session = (int) $ask['id'];
        $this->job = self::TESTS;
        $name = self::JOBS[self::TESTS];
        $paths = array_values(array_filter(array_map(fn (mixed $file): string => is_array($file) ? (string) ($file['path'] ?? '') : '', (array) ($ask['files'] ?? [])), fn (string $path): bool => $path !== '' && is_file($this->root.'/'.$path)));
        $branch = (string) ($ask['branch'] ?? '');
        $refusal = $this->refusal() ?? match (true) {
            $paths === [] => 'There is nothing left to write tests for.',
            $this->tests === null => 'Your tests are switched off, so I could not run the tests I write. Switch them on for your next scan.',
            default => null,
        };

        if ($refusal !== null) {
            return $this->refuse($name, 'terminal', $refusal, true);
        }

        if (! $this->onBranch($branch)) {
            return $this->refuse($name, 'terminal', 'I could not start a new branch, so nothing was changed.', true);
        }

        $this->base = $this->git->head();
        $this->progress->begin($this->session, $this->job, $name, 'terminal', (string) $this->branch, $paths);
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'running', 'branch' => $this->branch, 'from' => $this->from, 'refused' => null, 'batches' => []]);
        $this->progress->planned(1);
        $this->say(sprintf('I write a test for each of the %d %s no test covers and commit them. Failing tests, the last job, runs them and fixes what fails.', count($paths), count($paths) === 1 ? 'file' : 'files'));
        $this->queued(1, ['files' => array_map(fn (string $path): array => ['path' => $path, 'findings' => []], $paths), 'rule' => self::TESTS, 'label' => $name]);

        $kept = $this->testsWritten(1, $paths);
        $commit = $kept === [] ? null : $this->git->commit('test('.LocalBranch::commitScope().'): tests for my fixes', collect($kept)->map(fn (string $test): string => '- '.$test)->push('- Failing tests runs them last')->implode("\n"), only: $kept, new: $kept);
        $why = $this->why($paths);
        $this->record(1, $paths, $commit === null ? 'skipped' : 'committed', $commit, count($kept), count($paths) - count($kept), $why === '' ? null : $why);

        return $this->finish($name, false, 1);
    }

    private function onBranch(string $branch): bool
    {
        if ($branch === '' || ! $this->git->isOnFixes($branch)) {
            return $this->branched();
        }

        $this->from = $branch;
        $this->branch = $branch;

        return true;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function testsWritten(int $number, array $paths): array
    {
        $started = microtime(true);
        $example = $this->tests?->example($paths[0]);
        $this->done[$number] = 0;
        $this->record($number, $paths, 'running', null, 0, 0, null);
        $this->proposed($number, array_map(fn (string $path): array => ['path' => $path], $paths), null, self::TESTS, $example);
        $this->worked[$number] = microtime(true) - $started;

        return array_values($this->wrote);
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function placedTest(string $path, array $proposal): string
    {
        $note = trim((string) ($proposal['note'] ?? ''));
        $test = (array) ($proposal['test'] ?? []);
        $target = (string) ($test['path'] ?? '');
        $usable = $target !== '' && str_starts_with($target, 'tests/') && str_ends_with($target, 'Test.php') && ! str_contains($target, '..') && ! is_file($this->root.'/'.$target) && ! in_array($target, $this->wrote, true);

        if (! $usable) {
            return $this->leftAsItWas($path, $note !== '' ? $note : 'I could not write a new test file for it.');
        }

        is_dir(dirname($this->root.'/'.$target)) || mkdir(dirname($this->root.'/'.$target), 0755, true);
        file_put_contents($this->root.'/'.$target, (string) ($test['content'] ?? ''));
        unset($this->leftAsItWas[$path]);
        $this->wrote[$path] = $target;
        $note === '' || $this->notes[$path] = $note;
        $this->step($path.': test written in '.$target.'.');

        return $path;
    }

    /**
     * @param  list<array{id: int, tool: string, rule: string, path: string, line: ?int}>  $rechecks
     */
    public function recheck(array $rechecks): string
    {
        array_map(function (array $recheck): void {
            $result = $this->checkTool($recheck['tool']);
            $matches = fn (array $pair): bool => self::fileOf(['where' => (string) $pair['where']]) === $recheck['path'] && ($pair['rule'] === $recheck['rule'] || str_starts_with((string) $pair['rule'], $recheck['rule'].':'));
            $there = collect($result['findings'] ?? [])->contains($matches);
            $unread = collect($result['unread'] ?? [])->contains($matches);
            $passed = $result['ran'] && ! $there && ! $unread;

            $this->studio->submitScanWork(['recheck' => [
                'id' => $recheck['id'],
                'passed' => $passed,
                'reason' => match (true) {
                    ! $result['ran'] => $this->oneLine('The check could not run: '.($result['reason'] ?? 'it did not finish.')),
                    $there => $this->oneLine('It is still in '.$recheck['path'].'.'),
                    $unread => $this->oneLine('The check could not read '.$recheck['path'].' this time, because its page binds a property by a name worked out as it renders.'),
                    default => null,
                },
            ]]);

            $this->step($passed ? $recheck['path'].' is clear of that rule now.' : $recheck['path'].' still has it, or the check could not run.');
        }, $rechecks);

        return 'Checked '.count($rechecks).' '.(count($rechecks) === 1 ? 'place' : 'places').'. Nothing was changed.';
    }

    /**
     * Sends each fix branch the developer asked a pull request for to the
     * studio, commit by commit, and the studio puts it on GitHub and opens the PR.
     * Nothing here talks to GitHub.
     *
     * @param  list<array{id: int, branch: string, base: string}>  $sends
     */
    public function sendBranches(array $sends): string
    {
        $sent = array_map(function (array $send): bool {
            $outgoing = $this->git->outgoing((string) $send['branch'], (string) $send['base']);
            $refused = is_string($outgoing) ? $outgoing : $this->sendCommits((int) $send['id'], $outgoing);

            $refused === null || $this->studio->submitScanWork(['send' => ['id' => (int) $send['id'], 'failed' => mb_substr($refused, 0, 600)]]);
            $this->step($refused === null ? 'Sent '.$send['branch'].Says::named(' to the studio, so {name} can put it on GitHub and open your PR.') : 'I could not send '.$send['branch'].': '.$refused);
            $refused === null && $this->backFrom((string) $send['branch'], (string) $send['base']);

            return $refused === null;
        }, $sends);

        return count(array_filter($sent)) === count($sent) ? 'Sent '.implode(', ', array_column($sends, 'branch')).Says::named(' to the studio. {name} puts it on GitHub and opens the PR.') : Says::named('A fix branch could not be sent. {name} says why in the studio.');
    }

    /**
     * Once a fix branch is on its way to GitHub, puts the developer back on
     * the branch the fixes started from, when they are still on the fix
     * branch with nothing changed and not committed.
     */
    private function backFrom(string $branch, string $base): void
    {
        if (! $this->git->isOnStudioBranch($branch)) {
            return;
        }

        $this->step(match (true) {
            ! $this->git->isClean() => 'You are still on '.$branch.': it has changes not committed, so I left you there.',
            $this->git->switchTo($base) => 'You are back on '.$base.'. '.$branch.' stays on this machine.',
            default => 'I could not switch you back to '.$base.', so you are still on '.$branch.'.',
        });
    }

    /**
     * @param  array{base: string, commits: list<array<string, mixed>>}  $outgoing
     */
    private function sendCommits(int $id, array $outgoing): ?string
    {
        $parts = $this->partsOf($outgoing['commits']);
        $last = count($parts) - 1;
        $dropped = collect($parts)->search(fn (array $commits, int $part): bool => $this->studio->submitScanWork(['send' => ['id' => $id, 'base' => $outgoing['base'], 'part' => $part, 'more' => $part < $last, 'commits' => $commits]]) === null);

        return $dropped === false ? null : sprintf('the studio did not take part %d of %d of it.', $dropped + 1, count($parts));
    }

    /**
     * @param  list<array<string, mixed>>  $commits
     * @return list<list<array<string, mixed>>>
     */
    private function partsOf(array $commits): array
    {
        $parts = array_reduce($commits, function (array $parts, array $commit): array {
            $size = array_sum(array_map(fn (array $file): int => strlen((string) $file['content']), (array) $commit['files']));
            $last = array_key_last($parts);

            if ($last !== null && $parts[$last]['size'] + $size <= self::PART_BYTES && count($parts[$last]['commits']) < self::PART_COMMITS) {
                $parts[$last] = ['size' => $parts[$last]['size'] + $size, 'commits' => [...$parts[$last]['commits'], $commit]];

                return $parts;
            }

            return [...$parts, ['size' => $size, 'commits' => [$commit]]];
        }, []);

        return array_column($parts, 'commits');
    }

    /**
     * @param  array<string, mixed>  $ask
     */
    private function work(array $ask): string
    {
        $this->session = (int) $ask['id'];
        $name = self::JOBS[(string) $ask['job']] ?? (string) $ask['job'];
        $files = array_values(array_filter((array) ($ask['files'] ?? []), is_array(...)));
        $refusal = $this->refusal() ?? ($files === [] ? 'There is nothing left open in this job.' : null);

        if ($refusal !== null) {
            return $this->refuse($name, 'terminal', $refusal, true);
        }

        $this->job = (string) $ask['job'];
        $this->settle($this->job === self::FAILING_TESTS ? array_values(array_unique([...$this->toolsOf($files), Tests::KEY])) : $this->toolsOf($files), array_column($files, 'path'));
        $files = $this->job === self::FAILING_TESTS ? $this->failingInWritten($files) : $files;
        $this->ruleNames = collect($files)->flatMap(fn (array $file): array => (array) ($file['findings'] ?? []))->mapWithKeys(fn (array $finding): array => [(string) $finding['rule'] => (string) ($finding['label'] ?? Str::headline((string) $finding['rule']))])->all();
        $plan = $this->stillOpen($this->planned($files));

        if ($plan === []) {
            $this->progress->begin($this->session, $this->job, $name, 'terminal', '', array_column($files, 'path'));

            return $this->finish($name, false, 0);
        }

        if (! $this->branched()) {
            return $this->refuse($name, 'terminal', 'I could not start a new branch, so nothing was changed.', true);
        }

        $paths = array_merge([], ...array_map(fn (array $step): array => array_column($step['files'], 'path'), $plan));
        $this->progress->begin($this->session, $this->job, $name, 'terminal', (string) $this->branch, array_column($files, 'path'));
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'running', 'branch' => $this->branch, 'from' => $this->from, 'refused' => null, 'batches' => []]);
        $this->progress->planned(count($plan));
        $this->pulse('fixing');

        $this->say(sprintf('%s is %d %s on %s, %d %s of problem. I fix one kind at a time, every file of it at once, and commit each kind that passes its check. At the end I stop so you can try your app.', $name, count($paths), count($paths) === 1 ? 'file' : 'files', $this->branch, count($plan), count($plan) === 1 ? 'kind' : 'kinds'));
        array_map(fn (array $step, int $index) => $this->queued($index + 1, $step), $plan, array_keys($plan));

        $stopped = collect($plan)->contains(fn (array $step, int $index): bool => ! $this->fixed($index + 1, count($plan), $step));

        return $this->finish($name, $stopped, count($plan), $stopped ? null : $this->checkedOver(count($plan)));
    }

    /**
     * @param  array{decision: string, note: ?string}|null  $answer
     */
    private function finish(string $name, bool $stopped, int $planned, ?array $answer = null): string
    {
        $committed = count(array_filter($this->batches, fn (array $batch): bool => $batch['state'] === 'committed'));
        $note = rtrim(trim((string) ($answer['note'] ?? '')), '.');
        $said = match (true) {
            $planned === 0 => 'Everything in '.$name.' is already clear on this machine, so there was nothing to change.',
            $stopped => 'I stopped. What I committed is on '.$this->branch.', and anything else I changed is still in your files, uncommitted.',
            ($answer['decision'] ?? null) === self::BROKE => 'You said something broke'.($note === '' ? '' : ': '.$note).'. We look at it together in the app. What I committed is on '.$this->branch.', and nothing was pushed.',
            $committed === 0 && $this->leftAsItWas !== [] && $this->job !== self::TESTS => 'I could not settle anything in '.$name.' here, so nothing was committed. We go through what is left together in the app.',
            $this->job === self::TESTS => sprintf('I wrote %d %s for my fixes and committed %s on %s. Failing tests, the last job, runs %s. Nothing was pushed.', count($this->wrote), count($this->wrote) === 1 ? 'test' : 'tests', count($this->wrote) === 1 ? 'it' : 'them', $this->branch, count($this->wrote) === 1 ? 'it' : 'them'),
            default => 'All done with '.$name.'. What I fixed is committed on '.$this->branch.'. Nothing was pushed.',
        };

        $this->say(trim($said."\n\n".($this->job === self::TESTS ? $this->why(array_keys(array_diff_key($this->leftAsItWas, $this->wrote))) : $this->summary())));
        $this->progress->finished($stopped ? 'I stopped. What I committed is on the safety branch, and anything else I changed is still in your files, uncommitted.' : null);
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'done', 'batches' => $this->batchesForTheStudio(), ...($this->untested === [] ? [] : ['untested' => $this->untested])]);
        $this->pulse('finished');

        return $planned === 0 ? 'Nothing to change: '.$name.' is already clear. Nothing was changed.' : sprintf('%s on %s: %d of %d %s committed. Nothing was pushed.', $stopped ? 'Stopped' : 'Finished', $this->branch, $committed, $planned, $planned === 1 ? 'problem' : 'problems');
    }

    private function summary(): string
    {
        $done = collect($this->batches)->where('state', 'committed')->map(fn (array $batch): string => sprintf('%s: %d fixed (%s)', $batch['rule'], $batch['fixed'], substr((string) $batch['commit'], 0, 7)));
        $skipped = collect($this->batches)->whereIn('state', ['skipped', 'failed'])->pluck('rule');

        return trim(($done->isEmpty() ? '' : 'Fixed: '.$done->implode('; ').'.')."\n".($skipped->isEmpty() ? '' : 'Skipped: '.$skipped->implode('; ').'.'));
    }

    /**
     * The tests written for the fixes come to Failing tests with no
     * finding: the ones that fail on this machine get theirs from the run, so
     * they are fixed with the rest, and the ones that pass are left out.
     *
     * @param  list<array{path: string, findings: list<array<string, mixed>>}>  $files
     * @return list<array{path: string, findings: list<array<string, mixed>>}>
     */
    private function failingInWritten(array $files): array
    {
        $failing = fn (string $path): array => array_map(fn (array $finding): array => [
            'tool' => Tests::KEY,
            'rule' => 'failed',
            'label' => 'Failing tests',
            'line' => (int) Str::afterLast((string) $finding['where'], ':') ?: null,
            'title' => (string) ($finding['message'] ?? 'A test failed.'),
        ], self::inFiles($this->baseline[Tests::KEY] ?? [], [$path]));

        return array_values(array_filter(
            array_map(fn (array $file): array => (array) ($file['findings'] ?? []) !== [] ? $file : [...$file, 'findings' => $failing((string) $file['path'])], $files),
            fn (array $file): bool => $file['findings'] !== [],
        ));
    }

    /**
     * @param  list<array{path: string, findings: list<array{tool: string, rule: string, line: ?int, title: string, label?: string}>}>  $files
     * @return list<Step>
     */
    private function planned(array $files): array
    {
        $commonest = collect($files)->flatMap(fn (array $file): array => array_column($file['findings'], 'rule'))->countBy()->sortDesc()->keys()->all();

        return collect($files)
            ->groupBy(fn (array $file): string => (string) (collect($commonest)->first(fn (string $rule): bool => in_array($rule, array_column($file['findings'], 'rule'), true)) ?? 'other'))
            ->sortByDesc(fn (Collection $group): int => $group->count())
            ->map(function (Collection $group, string $rule): array {
                $all = $group->values()->all();
                $finding = collect($all[0]['findings'])->first(fn (array $finding): bool => $finding['rule'] === $rule);

                return ['files' => $all, 'rule' => $rule, 'label' => (string) ($finding['label'] ?? Str::headline($rule))];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<Step>  $plan
     * @return list<Step>
     */
    private function stillOpen(array $plan): array
    {
        $isOpen = fn (array $file): bool => collect(array_column($file['findings'], 'tool'))->unique()->contains(fn (string $tool): bool => self::within($this->baseline[$tool] ?? [], [$file['path']]) > 0);
        $clear = collect($plan)->flatMap(fn (array $step): array => array_values(array_filter($step['files'], fn (array $file): bool => ! $isOpen($file))))->values();

        if ($clear->isNotEmpty()) {
            $this->step(sprintf('%s %s already clear on this machine, so I left %s out.', $clear->map(fn (array $file): string => basename($file['path']))->implode(', '), $clear->count() === 1 ? 'is' : 'are', $clear->count() === 1 ? 'it' : 'them'));
            $this->studio->submitScanToolResults([
                'commit' => $this->git->head(),
                'partial' => true,
                'tools' => $clear->flatMap(fn (array $file): array => array_column($file['findings'], 'tool'))->unique()->mapWithKeys(fn (string $tool): array => [$tool => ['ran' => true, 'findings' => $this->baseline[$tool] ?? []]])->all(),
            ]);
        }

        return array_values(array_filter(array_map(fn (array $step): array => [...$step, 'files' => array_values(array_filter($step['files'], $isOpen))], $plan), fn (array $step): bool => $step['files'] !== []));
    }

    /**
     * @param  Step  $step
     */
    private function queued(int $number, array $step): void
    {
        $this->labels[$number] = $step['label'];
        $this->rule = $step['label'];
        $this->tools = $this->toolsOf($step['files']);
        $this->record($number, array_column($step['files'], 'path'), 'queued', null, 0, 0, null);
    }

    /**
     * @param  Step  $step
     */
    private function fixed(int $number, int $of, array $step): bool
    {
        $files = $step['files'];
        $paths = array_column($files, 'path');
        $tools = $this->toolsOf($files);
        $this->tools = $tools;
        $this->rule = $step['label'];
        $this->ruleKey = $step['rule'];
        $this->say(sprintf('%d of %d: "%s", %d %s.', $number, $of, $step['label'], count($paths), count($paths) === 1 ? 'file' : 'files'));

        $attempt = $this->attempt($number, $paths, $tools, $files);
        $this->setAside($files);

        if ($attempt['ok']) {
            return $this->committed($number, $this->onceMore($number, $paths, $tools, $files, $attempt), $tools);
        }

        $changed = $this->git->changed($paths);
        $this->git->restore($changed);
        $this->progress->says(sprintf('I left "%s" as it was and moved on. Why is in the app.', $step['label']));
        $this->step($attempt['reason']."\n\n".($changed === [] ? '' : 'I put these files back as they were. ').'On to the next one.');
        $this->record($number, $paths, 'skipped', null, 0, 0, $attempt['reason']);

        return true;
    }

    /**
     * Before anything is committed, the changed tests that still fail go back
     * to the studio once with what fails now, so the job lands as one commit.
     * When that try does not pass its check, the first change stands as it was.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $tools
     * @param  list<array{path: string, findings: list<array{tool: string, rule: string, line: ?int, title: string}>}>  $files
     * @param  Attempt  $attempt
     * @return Attempt
     */
    private function onceMore(int $number, array $paths, array $tools, array $files, array $attempt): array
    {
        $now = (array) ($attempt['verdict']['results'][Tests::KEY]['findings'] ?? []);
        $still = array_values(array_filter($files, fn (array $file): bool => in_array($file['path'], $attempt['paths'], true) && self::within($now, [$file['path']]) > 0));

        if (! in_array(Tests::KEY, $tools, true) || $still === []) {
            return $attempt;
        }

        $first = collect($attempt['paths'])->mapWithKeys(fn (string $path): array => [$path => (string) file_get_contents($this->root.'/'.$path)])->all();
        $this->say(sprintf('%d of the tests I changed still %s. I am trying %s once more with what fails now, before I commit anything.', count($still), count($still) === 1 ? 'fails' : 'fail', count($still) === 1 ? 'it' : 'them'));
        $again = $this->attempt($number, $paths, $tools, $still, 'Your change to this file is in place below, and the test still fails. What fails now: '.mb_substr(collect(self::inFiles($now, array_column($still, 'path')))
            ->map(fn (array $finding): string => $finding['where'].': '.$finding['message'].(isset($finding['detail']) ? ' '.$finding['detail'] : ''))
            ->implode("\n"), 0, 2000));

        if ($again['ok']) {
            return $again;
        }

        $this->git->restore($this->git->changed($paths));
        array_map(fn (string $path, string $content): int|false => file_put_contents($this->root.'/'.$path, $content), array_keys($first), $first);

        return $attempt;
    }

    /**
     * @param  list<array{path: string, findings: list<array{tool: string, rule: string, line: ?int, title: string}>}>  $files
     */
    private function setAside(array $files): void
    {
        $asides = array_values(array_map(fn (array $file): array => [
            'path' => $file['path'],
            'findings' => collect($file['findings'])->map(fn (array $finding): array => ['tool' => $finding['tool'], 'rule' => $finding['rule']])->unique(fn (array $finding): string => $finding['tool'].'|'.$finding['rule'])->take(self::MOST_SET_ASIDE_KINDS)->values()->all(),
            'reason' => mb_substr($this->decided[$file['path']], 0, 2000),
        ], array_filter($files, fn (array $file): bool => isset($this->decided[$file['path']]) && ! in_array($file['path'], $this->git->changed([$file['path']]), true))));

        $asides === [] || $this->studio->submitScanWork(['session' => $this->session, 'set_aside' => $asides]);
    }

    /**
     * @return array{decision: string, note: ?string}|null
     */
    private function checkedOver(int $planned): ?array
    {
        $committed = collect($this->batches)->where('state', 'committed');

        if ($committed->isEmpty()) {
            return null;
        }

        $this->say('I committed every kind I could fix. Checking that the files I changed compile and that your app still boots…');
        $paths = array_values(array_unique(array_column($this->git->changesSince((string) $this->base), 'path')));
        $broken = collect($paths)
            ->filter(fn (string $path): bool => is_file($this->root.'/'.$path))
            ->mapWithKeys(fn (string $path): array => [$path => (string) PhpCheck::of($path, (string) file_get_contents($this->root.'/'.$path))['error']])
            ->filter()
            ->all();

        $boot = $this->boots();
        $this->untested = $this->untested($paths);

        if ($broken === [] && $boot === null) {
            $this->say($this->overall($committed->all(), $planned, $broken, $boot, $this->untested, asking: false));

            return ['decision' => self::WORKS, 'note' => null];
        }

        return $this->ask(0, $paths, $this->overall($committed->all(), $planned, $broken, $boot, $this->untested), '', ['kind' => 'final', 'options' => [self::WORKS, self::BROKE]]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $committed
     * @param  array<string, string>  $broken
     * @param  list<string>  $untested
     */
    private function overall(array $committed, int $planned, array $broken, ?string $boot, array $untested, bool $asking = true): string
    {
        $left = collect($this->batches)->whereIn('state', ['skipped', 'failed'])->pluck('rule');

        return implode("\n\n", array_filter([
            sprintf('I committed %d of %d %s on %s: %s.', count($committed), $planned, $planned === 1 ? 'kind of problem' : 'kinds of problem', $this->branch, collect($committed)->map(fn (array $batch): string => sprintf('%s (%d fixed)', $batch['rule'], $batch['fixed']))->implode('; ')),
            match (true) {
                $broken === [] && $boot === null => 'Every file I changed compiles'.(is_file($this->root.'/artisan') ? ', and your app still boots.' : '.'),
                default => trim(($broken === [] ? '' : 'These files do not compile now: '.collect($broken)->map(fn (string $error, string $path): string => basename($path).' ('.$error.')')->implode('; ').'. ').($boot === null ? '' : 'Your app does not boot now: '.$boot)),
            },
            $asking ? 'Try your app on this branch, then tell me: does everything still work?' : 'You look over everything once, at the end, before the pull request.',
            $left->isEmpty() ? '' : 'Left for us to work through together: '.$left->implode('; ').'.',
            $this->leftAsItWas === [] ? '' : sprintf('I could not fix %s here. %s to Leftovers, where I try again with everything I learned.', implode(', ', array_map(basename(...), array_keys($this->leftAsItWas))), count($this->leftAsItWas) === 1 ? 'It moves' : 'They move'),
            $untested === [] ? '' : sprintf('No test covers %s. I write a test for %s once Leftovers is done, and Failing tests runs them last, so these fixes stay in place.', implode(', ', array_map(basename(...), $untested)), count($untested) === 1 ? 'it' : 'each'),
        ]));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function untested(array $paths): array
    {
        return $this->tests === null ? [] : array_values(array_filter($paths, fn (string $path): bool => str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php') && ! str_starts_with($path, 'tests/') && $this->tests->for([$path]) === []));
    }

    /**
     * The test files the tests job wrote, for Failing tests to run last.
     *
     * @return list<string>
     */
    private function testsWrittenSoFar(): array
    {
        return $this->job === self::TESTS ? array_values($this->wrote) : [];
    }

    /**
     * The reason for each file of a kind, for the app to show file by file.
     *
     * @param  list<string>  $paths
     * @return list<array{path: string, left: bool, why: string}>
     */
    private function reasonsFor(array $paths): array
    {
        return collect($paths)
            ->map(fn (string $path): ?array => match (true) {
                isset($this->leftAsItWas[$path]) => ['path' => $path, 'left' => true, 'why' => 'I left it as it was: '.$this->leftAsItWas[$path]],
                isset($this->putBack[$path]) => ['path' => $path, 'left' => true, 'why' => 'I left it as it was, because PHP could not compile the change there ('.$this->putBack[$path].').'],
                isset($this->failures[$path]) => ['path' => $path, 'left' => true, 'why' => ucfirst($this->failures[$path])],
                isset($this->decided[$path]) => ['path' => $path, 'left' => true, 'why' => $this->decided[$path]],
                ($this->notes[$path] ?? '') !== '' => ['path' => $path, 'left' => false, 'why' => $this->notes[$path]],
                default => null,
            })
            ->filter()
            ->map(fn (array $reason): array => [...$reason, 'why' => mb_substr(trim($reason['why']), 0, 2000)])
            ->sortByDesc('left')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $paths
     */
    private function why(array $paths): string
    {
        $left = array_intersect_key($this->leftAsItWas, array_flip($paths));
        $notes = array_values(array_unique(array_filter(array_intersect_key($this->notes, array_flip($paths)))));
        $broken = array_intersect_key($this->putBack, array_flip($paths));
        $missing = array_intersect_key($this->failures, array_flip($paths));

        return trim(implode("\n\n", array_filter([
            $this->leftAlone($left),
            implode("\n\n", $notes),
            $broken === [] ? '' : sprintf(
                'I left %s as %s, because PHP could not compile the change there (%s).',
                implode(', ', array_map(basename(...), array_keys($broken))),
                count($broken) === 1 ? 'it was' : 'they were',
                implode('; ', array_map(fn (string $error, string $path): string => basename($path).': '.$error, $broken, array_keys($broken))),
            ),
            implode("\n\n", array_map(fn (string $why, string $path): string => basename($path).' '.$why, $missing, array_keys($missing))),
        ])));
    }

    /**
     * @param  Attempt  $attempt
     * @param  list<string>  $tools
     */
    private function committed(int $number, array $attempt, array $tools): bool
    {
        $landed = $this->landed($number, $attempt['paths'], $tools, (array) $attempt['verdict'], trim(($this->tested === 0 ? '' : sprintf('No new failures in the %d %s.', $this->tested, $this->tested === 1 ? 'test file that covers it' : 'test files that cover them'))."\n\n".$attempt['reason']));

        if (! $landed['ok']) {
            $this->record($number, $attempt['paths'], 'failed', null, 0, 0, $landed['reason']);
            $this->say($landed['reason'].' Your files are as you left them.');

            return false;
        }

        $this->say(sprintf('Committed %s: %s, %d %s. On to the next one.', substr((string) $landed['commit'], 0, 7), $this->rule, count($attempt['paths']), count($attempt['paths']) === 1 ? 'file' : 'files'));

        return true;
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $tools
     * @param  list<array{path: string, findings: list<array{tool: string, rule: string, line: ?int, title: string}>}>  $files
     * @return Attempt
     */
    private function attempt(int $number, array $paths, array $tools, array $files, ?string $failed = null): array
    {
        $started = microtime(true);
        $tried = $this->tried($number, $files, $paths, $tools, $failed);

        if ($tried['verdict'] !== null && ! $tried['verdict']['passed'] && $tried['verdict']['unran'] === null) {
            $again = $this->implicated($paths, $tried['verdict']);
            $this->say(sprintf('The check found something in my change to %d %s. I am trying %s once more with exactly what it found, and keeping the rest.', count($again), count($again) === 1 ? 'file' : 'files', count($again) === 1 ? 'it' : 'them'));
            $this->git->restore($this->git->changed($again));
            $this->retesting = $this->retestFor($again, $tried['verdict']['broke']);
            $tried = $this->tried($number, array_values(array_filter($files, fn (array $file): bool => in_array($file['path'], $again, true))), $paths, $tools, $tried['reason']);
            $this->retesting = null;
        }

        $verdict = $tried['verdict'];
        $done = array_values(array_diff($paths, $tried['aside']));
        $this->worked[$number] = ($this->worked[$number] ?? 0.0) + microtime(true) - $started;

        return match (true) {
            $verdict === null => $this->notPassed($tried['reason'], $paths),
            $verdict['passed'] => $this->passing($done, $verdict, $tried['aside'], $tried['why']),
            default => $this->narrowed($done, $tools, $verdict, $tried['reason'], $tried['aside'], $tried['why']),
        };
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $tools
     * @param  Verdict  $verdict
     * @param  list<string>  $aside
     * @return Attempt
     */
    private function narrowed(array $paths, array $tools, array $verdict, string $reason, array $aside, string $putBack): array
    {
        $found = array_values(array_unique(array_intersect($paths, array_map(self::fileOf(...), $verdict['added']))));
        $broke = $this->coveredBy($paths, $verdict['broke']) ?: $this->coveredBy($paths, $verdict['broke'], loosely: true);
        $faulted = array_values(array_unique([...$found, ...$broke]));
        $rest = array_values(array_diff($paths, $faulted));

        if ($faulted === [] || $rest === []) {
            return $this->notPassed($reason, $paths);
        }

        $why = trim(implode(' ', [
            $found === [] ? '' : sprintf('I put %s back as %s, because %s reported %s there after the change.', implode(', ', array_map(basename(...), $found)), count($found) === 1 ? 'it was' : 'they were', $this->toolNames($tools), self::describe($verdict['added'])),
            $this->putBackFor($broke, $verdict['broke']),
        ]));
        $again = $this->checkedWithout($faulted, $rest, $tools, $verdict['broke'], $why);
        $more = $again['passed'] || $again['unran'] !== null ? [] : $this->coveredBy($rest, $again['broke'], loosely: true);
        $left = array_values(array_diff($rest, $more));

        if ($more === [] || $left === []) {
            return $again['passed'] ? $this->passing($rest, $again, [...$aside, ...$faulted], trim($putBack."\n\n".$why)) : $this->notPassed($again['reason'], $paths);
        }

        $whyMore = $this->putBackFor($more, $again['broke']);
        $again = $this->checkedWithout($more, $left, $tools, $again['broke'], $whyMore);

        return $again['passed'] ? $this->passing($left, $again, [...$aside, ...$faulted, ...$more], trim($putBack."\n\n".$why."\n\n".$whyMore)) : $this->notPassed($again['reason'], $paths);
    }

    /**
     * Put these files back, say so, and check the rest again with only the
     * tests that broke.
     *
     * @param  list<string>  $faulted
     * @param  list<string>  $rest
     * @param  list<string>  $tools
     * @param  list<string>  $broke
     * @return Verdict
     */
    private function checkedWithout(array $faulted, array $rest, array $tools, array $broke, string $why): array
    {
        $this->git->restore($faulted);
        $this->say(trim($why.' Checking the other '.count($rest).' again on their own.'));
        $this->retesting = $this->retestFor([], $broke);
        $again = $this->verify($rest, $tools);
        $this->retesting = null;

        return $again;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $broke
     */
    private function putBackFor(array $files, array $broke): string
    {
        return $files === [] ? '' : sprintf('I put %s back as %s, because the tests that cover %s failed after the change: %s.', implode(', ', array_map(basename(...), $files)), count($files) === 1 ? 'it was' : 'they were', count($files) === 1 ? 'it' : 'them', implode('; ', array_slice($broke, 0, 3)));
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $verdict
     * @param  list<string>  $aside
     * @return Attempt
     */
    private function passing(array $paths, array $verdict, array $aside, string $why): array
    {
        return ['ok' => true, 'reason' => $why, 'commit' => null, 'fixed' => (int) $verdict['fixed'], 'left' => (int) $verdict['left'], 'patch' => $this->git->patchOf($this->git->changed($paths)), 'verdict' => $verdict, 'paths' => $paths, 'aside' => $aside];
    }

    /**
     * @param  list<array{path: string, findings: list<array{tool: string, rule: string, line: ?int, title: string}>}>  $files
     * @param  list<string>  $paths
     * @param  list<string>  $tools
     * @return array{reason: string, verdict: ?Verdict, aside: list<string>, why: string}
     */
    private function tried(int $number, array $files, array $paths, array $tools, ?string $failed): array
    {
        $sent = array_column($files, 'path');
        array_map(function (string $path): void {
            unset($this->failures[$path], $this->putBack[$path], $this->notes[$path], $this->leftAsItWas[$path]);
        }, $sent);

        $this->done[$number] = max($this->done[$number] ?? 0, count($paths) - count($sent));
        $this->record($number, $paths, 'running', null, 0, 0, null);
        $this->pulse('fixing');
        $this->proposed($number, $files, $failed);

        $aside = array_values(array_intersect(array_keys($this->putBack), $paths));
        $why = $this->why($paths);
        $changed = $this->git->changed($paths);

        if ($changed === []) {
            return ['reason' => $why !== '' ? $why : 'No change was made to these files, so there is nothing to check.', 'verdict' => null, 'aside' => [], 'why' => ''];
        }

        $this->record($number, $paths, 'checking', null, 0, 0, null);
        $this->say(sprintf('I changed %d %s. Checking %s now with %s.', count($changed), count($changed) === 1 ? 'file' : 'files', count($changed) === 1 ? 'it' : 'them', $this->toolNames($tools)));
        $verdict = $this->verify($changed, $tools);

        return ['reason' => $verdict['reason'], 'verdict' => $verdict, 'aside' => [...$aside, ...array_values(array_diff($paths, $changed, $aside))], 'why' => $why];
    }

    /**
     * @param  list<string>  $paths
     * @param  Verdict  $verdict
     * @return list<string>
     */
    private function implicated(array $paths, array $verdict): array
    {
        $changed = $this->git->changed($paths);
        $found = array_values(array_intersect($changed, array_map(self::fileOf(...), $verdict['added'])));
        $implicated = array_values(array_unique([...$found, ...$this->coveredBy($changed, $verdict['broke'])]));

        return match (true) {
            $implicated !== [] => $implicated,
            $verdict['fixed'] === 0 => $paths,
            default => $changed,
        };
    }

    /**
     * The test files a check after a retry or a put-back needs: the ones that
     * cover the files changed again, and the ones that broke.
     *
     * @param  list<string>  $changedAgain
     * @param  list<string>  $broke
     * @return list<string>
     */
    private function retestFor(array $changedAgain, array $broke): array
    {
        return array_values(array_unique([
            ...($changedAgain === [] ? [] : $this->tests?->for($changedAgain) ?? []),
            ...array_map(fn (string $test): string => Str::before($test, ' › '), $broke),
        ]));
    }

    /**
     * The changed files that a test which broke after the change covers.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $broke
     * @return list<string>
     */
    private function coveredBy(array $paths, array $broke, bool $loosely = false): array
    {
        $tests = array_values(array_unique(array_map(fn (string $test): string => Str::before($test, ' › '), $broke)));

        if ($tests === []) {
            return [];
        }

        if ($loosely) {
            return array_values(array_filter($paths, fn (string $path): bool => array_intersect($this->tests?->for([$path, ...$this->usersOf($path)]) ?? [], $tests) !== []));
        }

        $sources = implode("\n", array_map(fn (string $test): string => is_file($this->root.'/'.$test) ? (string) file_get_contents($this->root.'/'.$test) : '', $tests));
        $reached = fn (string $class): bool => str_starts_with($class, 'app/') && str_ends_with($class, '.php')
            && (str_contains($sources, 'use App\\'.str_replace('/', '\\', substr($class, 4, -4)).';') || preg_match('/\b'.preg_quote(basename($class, '.php'), '/').'::class\b/', $sources) === 1);

        return array_values(array_filter($paths, fn (string $path): bool => collect([$path, ...$this->usersOf($path)])->contains($reached)));
    }

    /**
     * For a trait, the app classes that use it, and the ones that use those
     * in turn, a few levels up: a test reaches a trait through them.
     *
     * @return list<string>
     */
    private function usersOf(string $path): array
    {
        $isTrait = fn (string $file): bool => is_file($this->root.'/'.$file) && preg_match('/^\s*trait\s+\w+/m', (string) file_get_contents($this->root.'/'.$file)) === 1;

        return array_values(array_unique(collect(range(1, 3))->reduce(function (array $found) use ($isTrait): array {
            $next = collect($found['frontier'])->filter($isTrait)->flatMap(fn (string $file): array => $this->git->usersOfTrait(basename($file, '.php')))->diff($found['users'])->values()->all();

            return ['frontier' => $next, 'users' => [...$found['users'], ...$next]];
        }, ['frontier' => [$path], 'users' => []])['users']));
    }

    /**
     * @param  array<string, string>  $left
     */
    private function leftAlone(array $left): string
    {
        return collect($left)
            ->groupBy(fn (string $why): string => $why, true)
            ->map(fn (Collection $files, string $why): string => sprintf(
                'I left %s as %s%s',
                implode(', ', array_map(basename(...), $files->keys()->all())),
                $files->count() === 1 ? 'it was' : 'they were',
                $why === '' ? '.' : ': '.$why,
            ))
            ->implode("\n\n");
    }

    /**
     * @param  list<array{path: string}>  $files
     * @param  array{path: string, content: string}|null  $example
     */
    private function proposed(int $number, array $files, ?string $failed, string $kind = self::FIXES, ?array $example = null, int $round = 1): void
    {
        $this->askedFor = [];
        $paths = array_column($files, 'path');
        $tracked = array_values(array_filter($this->git->tracked($paths), fn (string $path): bool => is_file($this->root.'/'.$path)));
        $contents = array_combine($tracked, array_map(fn (string $path): string => (string) file_get_contents($this->root.'/'.$path), $tracked));
        $fits = array_filter($contents, fn (string $content): bool => strlen($content) <= self::LARGEST_FILE);
        $sent = array_map(fn (string $path): array => ['path' => $path, 'content' => $fits[$path], ...($kind === self::TESTS ? ['diff' => mb_substr($this->git->lastChangeOf($path), 0, self::LARGEST_FILE)] : [])], array_keys($fits));
        $this->leftOut(array_values(array_diff($paths, $tracked)), 'is not in your repository, so it stays on your machine and I left it as it was.');
        $this->leftOut(array_keys(array_diff_key($contents, $fits)), 'is too big for me to work on, so I left it as it was.');

        if ($sent === []) {
            return;
        }

        $request = random_int(1, 2_000_000_000);
        $parts = $this->parts($sent);
        $related = $kind === self::FIXES ? $this->dependedOn($fits) : [];
        $this->step(sprintf($kind === self::TESTS ? 'Writing tests for %d %s at once…' : 'Fixing %d %s at once…', count($sent), count($sent) === 1 ? 'file' : 'files'));

        $taken = array_map(fn (array $part, int $index): ?array => $this->studio->submitScanWork(['session' => $this->session, 'propose' => [
            'id' => $request,
            'kind' => $kind,
            'files' => $part,
            'note' => null,
            'failed' => $failed === null ? null : mb_substr($failed, 0, self::LONGEST_FAILED),
            'more' => $index < count($parts) - 1,
            ...($index === 0 && $example !== null ? ['example' => $example] : []),
            ...($index === 0 && $related !== [] ? ['related' => $related] : []),
        ]]), $parts, array_keys($parts));

        if (in_array(null, $taken, true)) {
            $this->leftOut(array_column($sent, 'path'), 'is as it was: the studio would not take the work.');

            return;
        }

        $this->streamed($number, $request, array_column($sent, 'path'), $kind === self::TESTS ? $this->placedTest(...) : $this->applyProposed(...), counts: $round === 1);
        $this->studio->submitScanWork(['session' => $this->session, 'propose' => ['id' => $request, 'done' => true]]);
        $again = array_values(array_filter($files, fn (array $file): bool => isset($this->askedFor[$file['path']])));

        if ($kind !== self::FIXES || $this->job !== self::FAILING_TESTS || $again === [] || $round >= self::MOST_ROUNDS) {
            return;
        }

        $this->say(sprintf('I need to see %s. Round %d of %d: %d %s again with %s…', collect($this->askedFor)->flatten()->unique()->implode(', '), $round + 1, self::MOST_ROUNDS, count($again), count($again) === 1 ? 'file' : 'files', count($this->askedFor) === 1 && count(reset($this->askedFor)) === 1 ? 'it' : 'them'));
        $this->proposed($number, $again, $failed, $kind, null, $round + 1);
    }

    /**
     * The app files that hold the text a failing test expected, so the studio reads
     * where it comes from. A count in it may have changed, so the text is
     * looked for again without its numbers.
     *
     * @return list<string>
     */
    private function showing(string $path): array
    {
        return collect(self::inFiles($this->baseline[Tests::KEY] ?? [], [$path]))
            ->map(fn (array $finding): string => preg_match('/^Expected the output (?:not )?to contain "(.+?)"(?: in specified order)?\. /s', (string) ($finding['detail'] ?? ''), $match) === 1 ? $match[1] : '')
            ->filter()
            ->flatMap(fn (string $expected): array => collect([$expected, trim((string) preg_replace('/(^|\s)\d+(?=\s|$)/', ' ', $expected))])
                ->filter(fn (string $text): bool => mb_strlen($text) >= 6)
                ->unique()
                ->map(fn (string $text): array => $this->git->filesWith($text))
                ->first(fn (array $files): bool => $files !== []) ?? [])
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * The code of only the tests in this file that fail: from each failing
     * test's opening line to the next test.
     */
    private function failingCode(string $path, string $content): ?string
    {
        $lines = explode("\n", $content);
        $starts = array_keys(array_filter($lines, fn (string $line): bool => preg_match('/^\s*(it|test|describe)\(/', $line) === 1));
        $failing = array_map(fn (array $finding): int => (int) Str::afterLast($finding['where'], ':'), self::inFiles($this->baseline[Tests::KEY] ?? [], [$path]));

        if ($starts === [] || $failing === [] || in_array(0, $failing, true)) {
            return null;
        }

        $top = collect(array_slice($lines, 0, $starts[0]))->reject(fn (string $line): bool => preg_match('/^\s*(use|namespace|declare)\b|^<\?php/', $line) === 1)->implode("\n");

        return $top."\n".collect($failing)
            ->map(function (int $line) use ($lines, $starts): string {
                $start = collect($starts)->filter(fn (int $at): bool => $at <= $line - 1)->max() ?? $starts[0];
                $next = collect($starts)->first(fn (int $at): bool => $at > $start) ?? count($lines);

                return implode("\n", array_slice($lines, $start, $next - $start));
            })
            ->unique()
            ->implode("\n");
    }

    /**
     * The files that fit what the studio reads beside one file, most wanted first,
     * so a big page never buries the few files that decide it.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function withinBudget(array $files): array
    {
        $used = 0;

        return array_values(array_filter($files, function (string $file) use (&$used): bool {
            $size = is_file($this->root.'/'.$file) ? (int) filesize($this->root.'/'.$file) : 0;
            $fits = $used === 0 || $used + $size <= self::RELATED_BYTES;
            $used += $fits ? $size : 0;

            return $fits;
        }));
    }

    /**
     * The files the studio named as needed, that are in the project and
     * that it was not given yet. A file that may hold secrets is never
     * one of them.
     *
     * @return list<string>
     */
    private function neededFiles(string $path, string $needs): array
    {
        return collect(preg_split('/[\s,;]+/', $needs) ?: [])
            ->map(fn (string $file): string => rtrim(trim($file, " \t`'\"()[]<>,;:"), '.'))
            ->map(fn (string $file): string => trim(str_starts_with($file, './') ? substr($file, 2) : $file, " \t`'\"()[]<>"))
            ->filter(fn (string $file): bool => preg_match('~^[\w-][\w.\-/]*\.\w+$~', $file) === 1 && ! str_contains($file, '..') && preg_match(self::SECRET, $file) !== 1)
            ->reject(fn (string $file): bool => $file === $path || in_array($file, $this->given[$path] ?? [], true) || ! is_file($this->root.'/'.$file) || $this->git->tracked([$file]) === [])
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * The views and policies the sent files depend on, read only, so the
     * studio decides from the code that decides it. Only files in the repository
     * leave this machine.
     *
     * @param  array<string, string>  $sent
     * @return list<array{path: string, content: string, for: list<string>}>
     */
    private function dependedOn(array $sent): array
    {
        $needs = collect($sent)->map(fn (string $content, string $path): array => $this->job === self::FAILING_TESTS
            ? $this->withinBudget(array_values(array_unique([...$this->wanted[$path] ?? [], ...$this->showing($path), ...(new DependsOn($this->root))->of($path, $content, $this->failingCode($path, $content))])))
            : (new DependsOn($this->root))->of($path, $content));
        $this->given = [...$this->given, ...$needs->all()];
        $wanted = $needs->flatten()->unique()->reject(fn (string $path): bool => isset($sent[$path]))->values()->all();
        $tracked = array_values(array_filter($this->git->tracked($wanted), fn (string $path): bool => filesize($this->root.'/'.$path) <= self::LARGEST_FILE));

        return array_slice(array_map(fn (string $path): array => [
            'path' => $path,
            'content' => (string) file_get_contents($this->root.'/'.$path),
            'for' => $needs->filter(fn (array $files): bool => in_array($path, $files, true))->keys()->values()->all(),
        ], $tracked), 0, 60);
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function applyProposed(string $path, array $proposal): string
    {
        $note = trim((string) ($proposal['note'] ?? ''));
        $edits = array_values(array_filter((array) ($proposal['edits'] ?? []), is_array(...)));
        $needs = $this->neededFiles($path, (string) ($proposal['needs'] ?? ''));

        if ($needs !== []) {
            $this->wanted[$path] = [...$this->wanted[$path] ?? [], ...$needs];
            $this->askedFor[$path] = $needs;
        }

        if ($edits === []) {
            (($proposal['unneeded'] ?? false) === true || ($proposal['decided'] ?? false) === true) && $note !== '' && $this->decided[$path] = $note;

            return $this->leftAsItWas($path, $note);
        }

        $edited = EditApplier::apply((string) file_get_contents($this->root.'/'.$path), $edits);

        if (isset($edited['error'])) {
            return $this->leftAsItWas($path, 'my edits did not fit the file ('.$edited['error'].').');
        }

        $checked = PhpCheck::of($path, $edited['content']);

        if ($checked['error'] !== null) {
            $this->putBack[$path] = $checked['error'];
            $this->step($path.': the change would not compile ('.$checked['error'].'), so I left it as it was.');

            return $path;
        }

        file_put_contents($this->root.'/'.$path, $checked['content']);
        unset($this->leftAsItWas[$path], $this->putBack[$path]);
        $note === '' || $this->notes[$path] = $note;
        $this->step($path.': changed.');

        return $path;
    }

    private function leftAsItWas(string $path, string $why): string
    {
        $this->leftAsItWas[$path] = $why;
        $this->step(trim($path.': left as it was. '.$why));

        return $path;
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $tools
     * @return Verdict
     */
    private function verify(array $paths, array $tools): array
    {
        $results = array_combine($tools, array_map(fn (string $tool): array => $this->standing($tool, $this->checkedAgain($tool, $paths)), $tools));

        return $this->verdictOf($paths, $tools, $results, $this->broken($paths));
    }

    /**
     * A tool's result with what it found before still standing in every file
     * and rule it could not check this time: a check that could not look
     * never counts as fixed.
     *
     * @param  array{ran: bool, reason?: string, findings?: list<Finding>, unread?: list<array{where: string, rule: string}>}  $result
     * @return array{ran: bool, reason?: string, findings?: list<Finding>, unread?: list<array{where: string, rule: string}>}
     */
    private function standing(string $tool, array $result): array
    {
        $unread = array_map(fn (array $pair): string => $pair['where'].'|'.$pair['rule'], $result['unread'] ?? []);

        if (! $result['ran'] || $unread === []) {
            return $result;
        }

        $now = $result['findings'] ?? [];
        $kept = array_filter($this->baseline[$tool] ?? [], fn (array $finding): bool => in_array(self::fileOf($finding).'|'.$finding['rule'], $unread, true) && ! in_array($finding, $now, true));

        return [...$result, 'findings' => [...$now, ...array_values($kept)]];
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $tools
     * @param  array<string, array{ran: bool, findings?: list<Finding>}>  $results
     * @param  list<string>  $broke
     * @return Verdict
     */
    private function verdictOf(array $paths, array $tools, array $results, array $broke): array
    {
        $unran = array_key_first(array_filter($results, fn (array $result): bool => ! $result['ran']));

        if ($unran !== null) {
            return ['passed' => false, 'reason' => $this->toolbox->name((string) $unran).' could not run for the check, so I cannot tell how it went. I have not committed the change.', 'fixed' => 0, 'left' => 0, 'unran' => (string) $unran, 'added' => [], 'results' => $results, 'broke' => []];
        }

        $counts = array_map(fn (string $tool): array => $this->measure($tool, $results[$tool]['findings'] ?? [], $this->scopeOf($tool, $paths)), $tools);
        $fixed = array_sum(array_column($counts, 'fixed'));
        $added = array_merge([], ...array_column($counts, 'added'));
        $more = count($broke) - 3;
        $reason = match (true) {
            in_array(true, array_column($counts, 'regressed'), true) => 'After the change '.collect($tools)->map($this->toolbox->name(...))->join(', ', ' and ').' reports '.self::describe($added).' in these files. I have not committed it.',
            $fixed === 0 => 'The tools still report all of what was in these files, so the change did not clear anything. I have not committed it.'.(isset($results[Tests::KEY]) ? ' What fails now: '.mb_substr(collect(self::inFiles($results[Tests::KEY]['findings'] ?? [], $this->scopeOf(Tests::KEY, $paths)))->map(fn (array $finding): string => $finding['where'].': '.$finding['message'].(isset($finding['detail']) ? ' '.$finding['detail'] : ''))->implode("\n"), 0, 1800) : ''),
            $broke !== [] => 'After the change '.(count($broke) === 1 ? 'this test fails' : 'these tests fail').': '.implode('; ', array_slice($broke, 0, 3)).($more > 0 ? ' and '.$more.' more' : '').'. I have not committed it.',
            default => '',
        };

        return ['passed' => $reason === '', 'reason' => $reason, 'fixed' => $fixed, 'left' => array_sum(array_column($counts, 'left')), 'unran' => null, 'added' => $added, 'results' => $results, 'broke' => $broke];
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $tools
     * @param  array<string, mixed>  $verdict
     * @return array{ok: bool, reason: string, commit: ?string}
     */
    private function landed(int $number, array $paths, array $tools, array $verdict, string $note): array
    {
        $changed = $this->git->changed($paths);
        $also = $this->alsoCleared($paths, (array) ($verdict['results'] ?? []));
        $commit = $changed === [] ? null : $this->git->commit(
            sprintf(Says::named('fix(%s): {name} worked through %d %s of %s'), $this->job, count($changed), count($changed) === 1 ? 'file' : 'files', $this->rule),
            collect($changed)
                ->map(fn (string $path): string => '- '.$path)
                ->when($also !== [], fn (Collection $lines): Collection => $lines->push('- Also cleared in these files: '.collect($also)->map(fn (array $kind): string => $kind['label'].' ('.$kind['fixed'].')')->implode(', ')))
                ->push('- Checked again by the tool that found them'.($this->tested === 0 ? '' : ', and by the tests that cover them'))
                ->implode("\n"),
            only: $changed,
        );

        if ($commit === null) {
            return ['ok' => false, 'reason' => 'I could not commit this.', 'commit' => null];
        }

        $this->also[$number] = $also;
        $this->adopt((array) ($verdict['results'] ?? []), $paths, $commit);
        $batch = (array) (collect($this->batches)->firstWhere('number', $number)['files'] ?? $paths);
        $left = array_sum(array_map(fn (string $tool): int => self::within($this->baseline[$tool] ?? [], $this->scopeOf($tool, $batch)), $tools));
        $this->record($number, $paths, 'committed', $commit, (int) ($verdict['fixed'] ?? 0), $left, $note === '' ? null : $note);
        $this->send($tools, $commit);

        return ['ok' => true, 'reason' => '', 'commit' => $commit];
    }

    /**
     * The other kinds of problem a commit cleared in its files, beyond the one
     * it is named for: what the tools found there before and no longer do,
     * kind by kind.
     *
     * @param  list<string>  $paths
     * @param  array<string, array{ran: bool, findings?: list<Finding>}>  $results
     * @return list<array{rule: string, label: string, fixed: int}>
     */
    private function alsoCleared(array $paths, array $results): array
    {
        $before = collect(array_keys($results))->flatMap(fn (string $tool): array => self::inFiles($this->baseline[$tool] ?? [], $paths))->countBy('rule');
        $after = collect($results)->flatMap(fn (array $result): array => self::inFiles($result['findings'] ?? [], $paths))->countBy('rule');

        return array_values($before
            ->map(fn (int $count, string $rule): int => $count - (int) $after->get($rule, 0))
            ->filter(fn (int $fixed, string $rule): bool => $fixed > 0 && $rule !== $this->ruleKey && ! str_starts_with($rule, $this->ruleKey.':'))
            ->map(fn (int $fixed, string $rule): array => ['rule' => $rule, 'label' => $this->ruleNames[$rule] ?? Str::headline($rule), 'fixed' => $fixed])
            ->sortByDesc('fixed')
            ->all());
    }

    /**
     * @param  list<string>  $paths
     * @return Attempt
     */
    private function notPassed(string $reason, array $paths): array
    {
        return ['ok' => false, 'reason' => $reason, 'commit' => null, 'fixed' => 0, 'left' => 0, 'patch' => $this->git->patchOf($this->git->changed($paths)), 'verdict' => null, 'paths' => [], 'aside' => []];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function broken(array $paths): array
    {
        $covering = $this->retesting ?? $this->tests?->for($paths) ?? [];
        $this->tested = $this->retesting === null ? 0 : $this->tested;

        if ($covering === []) {
            return [];
        }

        $this->step(sprintf('Running the %d %s these files.', count($covering), count($covering) === 1 ? 'test file that covers' : 'test files that cover'));
        $failing = $this->tests?->failing($covering);

        if ($failing === null) {
            $this->step('Your tests could not run, so my check of these files is the tools alone.');

            return [];
        }

        $this->tested = $this->retesting === null ? count($covering) : $this->tested;

        return $failing === [] ? [] : array_values(array_diff($failing, $this->failingBefore($paths, $failing)));
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $failing
     * @return list<string>
     */
    private function failingBefore(array $paths, array $failing): array
    {
        $tests = array_values(array_unique(array_map(fn (string $test): string => Str::before($test, ' › '), $failing)));
        $changed = $this->git->changed($paths);
        $edited = array_combine($changed, array_map(fn (string $path): string => (string) file_get_contents($this->root.'/'.$path), $changed));
        $this->step(sprintf('%d %s. Running %s on your files as they were, to see whether the change did it.', count($failing), count($failing) === 1 ? 'test fails' : 'tests fail', count($tests) === 1 ? 'that test file' : 'those test files'));
        $this->git->restore($changed);

        try {
            return $this->tests?->failing($tests) ?? [];
        } finally {
            array_map(fn (string $path, string $content): int|false => file_put_contents($this->root.'/'.$path, $content), array_keys($edited), $edited);
        }
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $tools
     */
    private function outcome(array $paths, array $tools, string $commit): ?string
    {
        $verdict = $this->verify($paths, $tools);

        if ($verdict['unran'] === null) {
            $this->adopt($verdict['results'], $paths, $commit);
        }

        return $verdict['unran'];
    }

    /**
     * @param  array<string, array{ran: bool, findings?: list<Finding>}>  $results
     * @param  list<string>  $paths
     */
    private function adopt(array $results, array $paths, string $commit): void
    {
        array_map(function (string $tool) use ($results, $paths, $commit): void {
            $now = $results[$tool]['findings'] ?? [];
            $earlier = $this->baseline[$tool] ?? $now;
            $scope = $this->scopeOf($tool, $paths);

            $this->fixed[$tool] = ($this->fixed[$tool] ?? 0) + max(0, self::within($earlier, $scope) - self::within($now, $scope));
            $this->lastCommit[$tool] = $commit;
            $this->countFiles($earlier, $now, $scope);
            $this->baseline[$tool] = [...array_values(array_filter($earlier, fn (array $finding): bool => ! in_array(self::fileOf($finding), $scope, true))), ...self::inFiles($now, $scope)];
            $this->kept()->keep($tool, $this->baseline[$tool]);
        }, array_keys($results));
    }

    /**
     * @param  list<Finding>  $now
     * @param  list<string>  $paths
     * @return array{fixed: int, left: int, regressed: bool, added: list<Finding>}
     */
    private function measure(string $tool, array $now, array $paths): array
    {
        $earlier = $this->baseline[$tool] ?? $now;
        $before = self::within($earlier, $paths);
        $after = self::within($now, $paths);
        $added = self::addedIn($earlier, $now, $paths);

        return ['fixed' => max(0, $before - $after), 'left' => $after, 'regressed' => $after > $before || $added !== [], 'added' => $added];
    }

    /**
     * @param  list<Finding>  $before
     * @param  list<Finding>  $after
     * @param  list<string>  $paths
     */
    private function countFiles(array $before, array $after, array $paths): void
    {
        array_map(function (string $path) use ($before, $after): void {
            $gone = self::within($before, [$path]) - self::within($after, [$path]);
            $this->fixedInFile[$path] = ($this->fixedInFile[$path] ?? 0) + max(0, $gone);
        }, $paths);
    }

    /**
     * @param  array<string, mixed>  $ask
     */
    private function apply(array $ask): string
    {
        $this->session = (int) $ask['id'];
        $name = self::JOBS[(string) $ask['job']] ?? (string) $ask['job'];
        $proposals = array_values(array_filter((array) ($ask['proposals'] ?? []), is_array(...)));

        if ($proposals === []) {
            return 'Nothing agreed is waiting, so nothing was changed.';
        }

        $refusal = $this->refusal(array_column(array_filter($proposals, fn (array $proposal): bool => ($proposal['yours'] ?? false) === true), 'path'));

        if ($refusal !== null) {
            return $this->refuse($name, 'app', $refusal, false);
        }

        if (! $this->onBranch((string) ($ask['branch'] ?? ''))) {
            return $this->refuse($name, 'app', 'I could not start a new branch, so nothing was changed.', false);
        }

        $paths = array_column($proposals, 'path');
        $tools = array_values(array_unique(array_merge([], ...array_map(fn (array $proposal): array => array_values(array_filter((array) ($proposal['tools'] ?? []), is_string(...))), $proposals))));
        $this->progress->begin($this->session, (string) $ask['job'], $name, 'app', (string) $this->branch, $paths);
        $this->studio->submitScanWork(['session' => $this->session, 'branch' => $this->branch, 'from' => $this->from, 'refused' => null]);
        $this->step('Applying '.count($proposals).' '.(count($proposals) === 1 ? 'change' : 'changes').' you agreed in the app, on '.$this->branch.'.');
        $this->settle($tools, [...array_column(array_values(array_filter((array) ($ask['files'] ?? []), is_array(...))), 'path'), ...$paths]);

        $landed = array_map(fn (array $proposal): array => ($proposal['yours'] ?? false) === true ? $this->fixedByYou($proposal) : $this->applyOne($proposal), $proposals);
        $checked = $this->outcomeOf(array_values(array_filter($landed, fn (array $result): bool => $result['commit'] !== null)), $tools);

        collect($landed)
            ->map(fn (array $result): array => [...array_intersect_key($checked[$result['id']] ?? $result, array_flip(['id', 'status', 'commit', 'note', 'left'])), ...(isset($result['why']) ? ['why' => $result['why']] : [])])
            ->map(fn (array $result): array => [...$result, 'note' => $result['note'] === null ? null : mb_substr((string) $result['note'], 0, self::LONGEST_PROPOSAL_NOTE)])
            ->chunk(self::MOST_PROPOSALS_REPORTED)
            ->each(fn (Collection $part): ?array => $this->studio->submitScanWork(['session' => $this->session, 'branch' => $this->branch, 'proposals' => $part->values()->all()]));
        $this->send($tools, (string) $this->git->head());
        $this->progress->finished();
        $this->pulse('finished');

        return sprintf('Applied %d of %d agreed changes on %s. Nothing was pushed.', count(array_filter($landed, fn (array $result): bool => $result['commit'] !== null)), count($proposals), $this->branch);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{id: int, status: string, commit: ?string, note: ?string, left: ?int, path: string, tools: list<string>}
     */
    private function applyOne(array $proposal): array
    {
        $path = (string) $proposal['path'];
        $id = (int) $proposal['id'];
        $tools = array_values(array_filter((array) ($proposal['tools'] ?? []), is_string(...)));
        $failed = fn (string $note): array => ['id' => $id, 'status' => 'failed', 'commit' => null, 'note' => $note, 'left' => null, 'path' => $path, 'tools' => $tools];
        $file = $this->root.'/'.$path;
        $edits = array_values(array_filter((array) ($proposal['edits'] ?? []), is_array(...)));
        $creates = ! file_exists($file) && count($edits) === 1 && (string) ($edits[0]['find'] ?? '') === '' && trim((string) ($edits[0]['replace'] ?? '')) !== '';

        if (str_starts_with(basename($path), '.env') || str_contains($path, '..') || str_starts_with($path, '/')) {
            return $failed('I never create or change '.$path.'.');
        }

        if (! is_file($file) && ! $creates) {
            return $failed($path.' is not on your machine.');
        }

        $edited = $creates ? ['content' => rtrim((string) $edits[0]['replace'], "\n")."\n"] : EditApplier::apply((string) file_get_contents($file), $edits);

        if (isset($edited['error'])) {
            return $failed($edited['error']);
        }

        $checked = PhpCheck::of($path, $edited['content']);

        if ($checked['error'] !== null) {
            return $failed('The change would not compile ('.$checked['error'].'), so I left '.$path.' as it was.');
        }

        if (str_ends_with($path, '.json') && ! json_validate($checked['content'])) {
            return $failed('The change would not be valid JSON, so I left '.$path.' as it was.');
        }

        if (! $creates && $checked['content'] === (string) file_get_contents($file)) {
            return $failed('The change left '.$path.' exactly as it was, so there was nothing to commit.');
        }

        is_dir(dirname($file)) || mkdir(dirname($file), 0755, true);
        file_put_contents($file, $checked['content']);
        $commit = $this->git->commit('fix('.LocalBranch::commitScope().'): the change you agreed to in '.$path, trim((string) ($proposal['why'] ?? '')) === '' ? '' : '- '.trim((string) $proposal['why']), only: [$path], new: $creates ? [$path] : []);

        return $commit === null ? $failed('I changed '.$path.' but could not commit it.') : ['id' => $id, 'status' => 'applied', 'commit' => $commit, 'note' => null, 'left' => null, 'path' => $path, 'tools' => $tools];
    }

    /**
     * A file the studio could not fix and the developer fixes themselves: it
     * opens in their editor, the run waits for them to say it is done, then
     * their change is committed and checked like the studio's own.
     *
     * @param  array<string, mixed>  $proposal
     * @return array{id: int, status: string, commit: ?string, note: ?string, left: ?int, path: string, tools: list<string>}
     */
    private function fixedByYou(array $proposal): array
    {
        $path = (string) $proposal['path'];
        $id = (int) $proposal['id'];
        $tools = array_values(array_filter((array) ($proposal['tools'] ?? []), is_string(...)));
        $failed = fn (string $note): array => ['id' => $id, 'status' => 'failed', 'commit' => null, 'note' => $note, 'left' => null, 'path' => $path, 'tools' => $tools];
        $file = $this->root.'/'.$path;

        if (! is_file($file) || str_contains($path, '..') || preg_match(self::SECRET, $path) === 1) {
            return $failed($path.' is not on your machine.');
        }

        $already = $this->git->changed([$path]) !== [];
        $opened = $already || app(Editor::class)->open([$path]);
        $why = trim((string) ($proposal['why'] ?? ''));
        $asked = match (true) {
            $already => 'You changed '.$path.' in your editor. Why? Tell me in a line: your words become the commit message, and I check it again.',
            $opened => 'I opened '.$path.' in your editor. Fix it and save it, then tell me why you changed it: your words become the commit message, and I check it again.',
            default => 'Open '.$path.' in your editor. Fix it and save it, then tell me why you changed it: your words become the commit message, and I check it again.',
        };
        $answer = $this->ask(0, [$path], trim($asked.($already || $why === '' ? '' : "\n\nWhy I could not: ".$why)), '', ['kind' => 'yours', 'options' => [self::APPROVE, self::LEAVE]]);

        if ($answer['decision'] !== self::APPROVE) {
            return $failed('You left '.$path.' as it was.');
        }

        $theirs = array_values(array_unique([...$this->git->changed(), ...$this->git->staged()]));

        if ($theirs === []) {
            return $failed('You did not change anything, so there was nothing to check.');
        }

        $reason = trim((string) ($answer['note'] ?? ''));
        $commit = $this->git->commit(
            $reason === '' ? 'fix: your change to '.$path : 'fix: '.Str::limit((string) strtok($reason, "\n"), 72, ''),
            implode("\n", array_filter([...array_map(fn (string $changed): string => '- '.$changed, $theirs), $reason === '' ? null : '- Why: '.$reason, Says::named('- You fixed it yourself, and {name} checked it again')])),
            only: $theirs,
            byStudio: false,
        );

        return $commit === null ? $failed('I could not commit your change to '.$path.'.') : ['id' => $id, 'status' => 'applied', 'commit' => $commit, 'note' => null, 'left' => null, 'path' => $path, 'tools' => $tools, ...($reason === '' ? [] : ['why' => mb_substr($reason, 0, self::LONGEST_WHY)])];
    }

    /**
     * @param  list<array{id: int, status: string, commit: ?string, note: ?string, left: ?int, path: string, tools: list<string>}>  $applied
     * @param  list<string>  $tools
     * @return array<int, array{id: int, status: string, commit: ?string, note: ?string, left: ?int}>
     */
    private function outcomeOf(array $applied, array $tools): array
    {
        $paths = array_column($applied, 'path');
        $this->step('Checking '.count($applied).' applied '.(count($applied) === 1 ? 'change' : 'changes').' again with '.collect($tools)->map($this->toolbox->name(...))->join(', ', ' and ').'…');
        $unran = $tools === [] || $applied === [] ? null : $this->outcome($paths, $tools, (string) $this->git->head());

        return collect($applied)->mapWithKeys(function (array $result) use ($unran): array {
            $left = array_sum(array_map(fn (string $tool): int => self::within($this->baseline[$tool] ?? [], $this->scopeOf($tool, [$result['path']])), $result['tools']));
            $covered = $result['tools'] !== [] && $unran === null;

            return [$result['id'] => [
                'id' => $result['id'],
                'status' => $covered && $left > 0 ? 'unresolved' : 'applied',
                'commit' => $result['commit'],
                'note' => match (true) {
                    $result['tools'] === [] => 'No checking tool covers this file, so I could not check it again.',
                    ! $covered => 'The check could not run, so I could not tell how it went.',
                    $left > 0 => collect($result['tools'])->map($this->toolbox->name(...))->join(', ', ' and ').' still reports '.$left.' here.',
                    default => null,
                },
                'left' => $covered ? $left : null,
            ]];
        })->all();
    }

    /**
     * @param  list<string>  $tools
     * @param  list<string>  $paths
     */
    private function settle(array $tools, array $paths = []): void
    {
        array_map(function (string $tool) use ($paths): void {
            $this->baseline[$tool] = $tool === Tests::KEY ? $this->failingNow($paths) : ($this->kept()->for($tool) ?? $this->checkTool($tool)['findings'] ?? []);
            $this->found[$tool] = count($this->baseline[$tool]);
            $tool === Tests::KEY || $this->kept()->keep($tool, $this->baseline[$tool]);
        }, $tools);

        $this->base = $this->git->head();
        $this->shareWhyTestsFail();
        $this->pulse('fixing');
    }

    /**
     * Only the test files that fail, run again for why they fail now, with
     * what failed before standing in every other file.
     *
     * @param  list<string>  $paths
     * @return list<Finding>
     */
    private function failingNow(array $paths): array
    {
        $failing = $this->testFilesFor($paths);
        $elsewhere = array_values(array_filter($this->kept()->for(Tests::KEY) ?? [], fn (array $finding): bool => ! in_array(self::fileOf($finding), $failing, true)));

        if ($failing === []) {
            return $elsewhere;
        }

        $this->step(sprintf('Running the %d failing test %s to see why they fail…', count($failing), count($failing) === 1 ? 'file' : 'files'));

        return [...($this->checkTool(Tests::KEY, $failing)['findings'] ?? []), ...$elsewhere];
    }

    /**
     * A tool's check after a change: the tests in only the test files the
     * change is for, every other tool as it runs.
     *
     * @param  list<string>  $paths
     * @return array{ran: bool, reason?: string, findings?: list<Finding>, unread?: list<array{where: string, rule: string}>}
     */
    private function checkedAgain(string $tool, array $paths): array
    {
        if ($tool !== Tests::KEY) {
            return $this->checkTool($tool);
        }

        $tests = $this->testFilesFor($paths);

        return $tests === [] ? ['ran' => true, 'findings' => []] : $this->checkTool($tool, $tests);
    }

    /**
     * The files a tool's findings for a change sit in.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function scopeOf(string $tool, array $paths): array
    {
        return $tool === Tests::KEY ? $this->testFilesFor($paths) : $paths;
    }

    /**
     * The test files to run for a change: the test files it touches, and for
     * a change to any other file, the test files that failed before it.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function testFilesFor(array $paths): array
    {
        $tests = array_values(array_filter($paths, fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, '.php') && is_file($this->root.'/'.$path)));
        $failedBefore = count($tests) < count($paths) ? array_map(self::fileOf(...), $this->baseline[Tests::KEY] ?? []) : [];

        return array_values(array_unique([...$tests, ...array_filter($failedBefore, fn (string $path): bool => is_file($this->root.'/'.$path))]));
    }

    private function shareWhyTestsFail(): void
    {
        if (! isset($this->baseline[Tests::KEY])) {
            return;
        }

        $this->studio->submitScanToolResults([
            'commit' => $this->git->head(),
            'partial' => true,
            'tools' => [Tests::KEY => ['ran' => true, 'findings' => $this->baseline[Tests::KEY]]],
        ]);
    }

    /**
     * @param  list<string>  $tools
     */
    private function send(array $tools, string $commit): void
    {
        if ($tools === []) {
            return;
        }

        $this->studio->submitScanFixReport([
            'branch' => (string) $this->branch,
            'from' => $this->from,
            'rulesets' => collect($tools)->mapWithKeys(fn (string $tool): array => [$tool => [
                'name' => $this->toolbox->name($tool),
                'ran' => true,
                'fixed' => $this->fixed[$tool] ?? 0,
                'kinds' => [],
                'files' => count(array_filter($this->fixedInFile)),
                'commit' => $this->lastCommit[$tool] ?? $commit,
                'took' => 0,
            ]])->all(),
            'diffs' => $this->base === null ? [] : $this->git->changesSince($this->base),
        ]);

        $this->studio->submitScanToolResults([
            'commit' => $this->git->head(),
            'partial' => true,
            'tools' => collect($tools)->mapWithKeys(fn (string $tool): array => [$tool => ['ran' => true, 'findings' => $this->baseline[$tool] ?? []]])->all(),
        ]);
        $this->pulse('fixing');
    }

    private function pulse(string $phase): void
    {
        if ($this->branch === null) {
            return;
        }

        $this->pulsedAt = microtime(true);
        $this->studio->submitScanFixReport(['branch' => $this->branch, 'live' => [
            'phase' => $phase,
            'rulesets' => collect($this->found)->map(fn (int $found, string $tool): array => [
                'state' => $phase === 'finished' ? 'done' : 'running',
                'found' => $found,
                'fixed' => $this->fixed[$tool] ?? 0,
                'left' => count($this->baseline[$tool] ?? []),
            ])->all(),
            'files' => collect($this->fixedInFile)->filter()->map(fn (int $fixed, string $path): array => ['path' => $path, 'fixed' => $fixed])->values()->all(),
        ]]);
    }

    /**
     * @param  list<array{path: string, findings: list<array{tool: string}>}>  $files
     * @return list<string>
     */
    private function toolsOf(array $files): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (array $file): array => array_column($file['findings'], 'tool'), $files))));
    }

    private function branched(): bool
    {
        $this->from = $this->git->current();
        $this->branch = $this->git->start(Carbon::now()->format('Y-m-d-Hi'));

        return $this->branch !== null;
    }

    /**
     * @param  list<string>  $paths
     * @return array{ran: bool, reason?: string, took?: int, findings?: list<Finding>, unread?: list<array{where: string, rule: string}>}
     */
    private function checkTool(string $tool, array $paths = []): array
    {
        return $this->check !== null ? ($this->check)($tool, $paths) : $this->toolbox->check($tool, $paths);
    }

    private function kept(): LastFindings
    {
        return new LastFindings($this->root);
    }

    /**
     * @param  list<string>  $tools
     */
    private function toolNames(array $tools): string
    {
        return $tools === [] ? 'the check' : collect($tools)->map($this->toolbox->name(...))->join(', ', ' and ');
    }

    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $paths
     */
    private static function within(array $findings, array $paths): int
    {
        return count(self::inFiles($findings, $paths));
    }

    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $paths
     * @return list<Finding>
     */
    private static function inFiles(array $findings, array $paths): array
    {
        return array_values(array_filter($findings, fn (array $finding): bool => in_array(self::fileOf($finding), $paths, true)));
    }

    /**
     * @param  list<Finding>  $earlier
     * @param  list<Finding>  $now
     * @param  list<string>  $paths
     * @return list<Finding>
     */
    private static function addedIn(array $earlier, array $now, array $paths): array
    {
        $was = array_count_values(array_map(self::keyOf(...), self::inFiles($earlier, $paths)));

        return collect(self::inFiles($now, $paths))
            ->groupBy(self::keyOf(...))
            ->flatMap(fn (Collection $group, string $key): array => $group->take(-max(0, $group->count() - ($was[$key] ?? 0)))->all())
            ->values()
            ->all();
    }

    /**
     * @param  array{where: string, rule: string}  $finding
     */
    private static function keyOf(array $finding): string
    {
        return self::fileOf($finding)."\0".$finding['rule'];
    }

    /**
     * @param  list<Finding>  $added
     */
    private static function describe(array $added): string
    {
        $shown = collect($added)->take(3)->map(fn (array $finding): string => $finding['where'].' ['.$finding['rule'].'] '.$finding['message'])->implode('; ');
        $more = count($added) - 3;

        return match (true) {
            $added === [] => 'more',
            default => count($added).' new '.(count($added) === 1 ? 'finding' : 'findings').' ('.$shown.($more > 0 ? '; and '.$more.' more' : '').')',
        };
    }

    /**
     * @param  array{where: string}  $finding
     */
    private static function fileOf(array $finding): string
    {
        return (string) preg_replace('/:\d+$/', '', $finding['where']);
    }
}
