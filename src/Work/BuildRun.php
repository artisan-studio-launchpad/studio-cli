<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use ArtisanStudio\StudioCli\Work\Concerns\AsksTheDeveloper;
use ArtisanStudio\StudioCli\Work\Concerns\ChecksTheMachine;
use ArtisanStudio\StudioCli\Work\Concerns\ReportsToTheStudio;
use ArtisanStudio\StudioCli\Work\Concerns\StreamsFromTheStudio;
use Illuminate\Support\Str;

/**
 * Builds an idea planned in the studio, on a new local branch: step by step
 * the studio writes its files, this machine writes them, checks
 * them and commits the step, and at the end it stops once for the developer
 * to try their app. Nothing here talks to GitHub, and nothing is pushed.
 *
 * @phpstan-type PlannedFile array{path: string, action: string, what: string, pattern: string}
 * @phpstan-type PlannedStep array{number: int, role: string, name: string, tone: string, title: string, line: string, prototype: bool, files: list<PlannedFile>}
 */
final class BuildRun
{
    use AsksTheDeveloper, ChecksTheMachine, ReportsToTheStudio, StreamsFromTheStudio;

    public const string JOB = 'build';

    private const string TEST_ROLE = 'prover';

    private const int LARGEST_FILE = 300_000;

    private const int LARGEST_RELATED = 100_000;

    private const int MOST_RELATED = 40;

    private const int MOST_RELATED_BYTES = 1_000_000;

    private const string SIGN_OFF = 'approve';

    private const string GUIDE = 'retry';

    private const int RENDER_TRIES = 2;

    private const string CALLED_OFF = 'You stopped the build in the app, so I stopped there: nothing more was written or committed.';

    private ?string $halt = null;

    private ?string $branch = null;

    private ?string $base = null;

    private ?string $from = null;

    /**
     * @var list<string>
     */
    private array $read = [];

    /**
     * @var list<string>
     */
    private array $built = [];

    /**
     * @var list<string>
     */
    private array $created = [];

    /**
     * @var array<string, array{content: string, note: string}>
     */
    private array $arrived = [];

    /**
     * @var array<string, string>
     */
    private array $broken = [];

    /**
     * @var array<string, string>
     */
    private array $failing = [];

    public function __construct(
        private readonly string $root,
        private readonly Studio $studio,
        private readonly LocalBranch $git,
        private readonly WorkProgress $progress,
        private readonly TaskJournal $journal,
        private readonly StepChecks $checks,
        private readonly int $pollSeconds = 1,
        private readonly int $pickupSeconds = 300,
        private readonly ?CoveringTests $tests = null,
    ) {}

    /**
     * @param  array<string, mixed>  $ask
     */
    public function run(array $ask): string
    {
        is_string($ask['author'] ?? null) && config(['studio-cli.author' => $ask['author']]);
        $this->session = (int) $ask['id'];
        $name = (string) ($ask['name'] ?? Says::named('Build with {name}'));
        $plan = (array) ($ask['plan'] ?? []);
        $steps = $this->stepsOf($plan);
        $refusal = $this->refusal() ?? ($steps === [] ? 'There is no plan to build from.' : null);

        if ($refusal !== null) {
            return $this->refuse($name, 'terminal', $refusal, true);
        }

        $this->from = $this->git->current();
        $this->branch = $this->git->startAt((string) ($plan['branch'] ?? '') ?: LocalBranch::buildPrefix().'idea');

        if ($this->branch === null) {
            return $this->refuse($name, 'terminal', 'I could not start a new branch, so nothing was changed.', true);
        }

        $this->base = $this->git->head();
        $this->read = array_values(array_filter((array) ($plan['read'] ?? []), is_string(...)));
        $paths = array_merge([], ...array_map(fn (array $step): array => array_column($step['files'], 'path'), $steps));
        $this->progress->begin($this->session, self::JOB, $name, 'terminal', $this->branch, $paths);
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'running', 'branch' => $this->branch, 'from' => $this->from, 'refused' => null, 'batches' => []]);
        $this->progress->planned(count($steps));
        $this->say(sprintf('I build %s on %s in %d %s. I write each step, check it and commit it on its own, and at the end I stop once so you can try your app.', $name, $this->branch, count($steps), count($steps) === 1 ? 'step' : 'steps'));
        array_map(fn (array $step): mixed => $this->queued($step), $steps);
        collect($steps)->each(fn (array $step): bool => $this->carriedOn($step, count($steps), (string) ($plan['prototype_at'] ?? '')));

        if ($this->halt === self::CALLED_OFF) {
            $this->step($this->halt);
            $this->progress->forget();

            return $this->halt;
        }

        if ($this->halt !== null) {
            return $this->refuse($name, 'terminal', $this->halt, true);
        }

        return $this->finish($name, count($steps), $this->checkedOver());
    }

    /**
     * Builds one step and, when it is the prototype, stops there until the
     * developer signs it off. False stops the build.
     *
     * @param  PlannedStep  $step
     */
    private function carriedOn(array $step, int $of, string $at): bool
    {
        $this->built($step, $of);

        if ($step['prototype'] && ! $this->halted()) {
            $at = $this->rendered($step, $at, self::RENDER_TRIES);
        }

        return ! $this->halted() && (! $step['prototype'] || $this->signedOff($step, $at));
    }

    /**
     * Opens the prototype in the app before the developer does, and when it
     * does not render, has the studio fix it from exactly what the app said.
     *
     * @param  PlannedStep  $step
     */
    private function rendered(array $step, string $at, int $tries): string
    {
        if (! str_starts_with($at, '/') || $this->halted()) {
            return $at;
        }

        $this->say(sprintf('I’m opening %s to check the prototype renders before you try it.', $at));
        $opened = $this->renders($at);
        $error = $opened['error'];

        if ($opened['missing']) {
            $this->say(sprintf('%s is not a page in your app, so I could not open it. Try the prototype where it opens.', $opened['path']));

            return $at;
        }

        if ($error === null) {
            $this->say($opened['redirect'] === null ? sprintf('%s renders.', $opened['path']) : sprintf('%s sent me on to %s, so I could not see it render.', $opened['path'], $opened['redirect']));

            return $opened['path'];
        }

        if ($tries === 0) {
            $this->say(sprintf('%s still does not render: %s. I’m stopping so you can see it.', $opened['path'], rtrim($error, '.')));

            return $opened['path'];
        }

        $this->say(sprintf('%s does not render: %s. I’m fixing it.', $opened['path'], rtrim($error, '.')));
        $this->written($step['number'], $step['files'], 'Opening '.$opened['path'].' in the app failed with: '.$error);
        $this->committed($step['number'], $step, array_column($step['files'], 'path'));

        return $this->rendered($step, $at, $tries - 1);
    }

    /**
     * Stops at the prototype until the developer signs it off. Each time they
     * say what to change, the prototype is written again and committed.
     *
     * @param  PlannedStep  $step
     */
    private function signedOff(array $step, string $at): bool
    {
        $answer = $this->ask($step['number'], array_column($step['files'], 'path'), $this->prototypeSaid($at), '', ['kind' => 'prototype', 'options' => [self::SIGN_OFF, self::GUIDE]]);

        return match ($answer['decision']) {
            self::SIGN_OFF => true,
            self::GUIDE => $this->guided($step, (string) $answer['note']) && $this->signedOff($step, $at),
            default => $this->stoppedAtPrototype(),
        };
    }

    /**
     * @param  PlannedStep  $step
     */
    private function guided(array $step, string $note): bool
    {
        $this->say('I’m changing the prototype: '.rtrim(trim($note), '.').'.');
        $this->written($step['number'], $step['files'], 'The developer tried the prototype and asks: '.trim($note));
        $this->committed($step['number'], $step, array_column($step['files'], 'path'));

        return ! $this->halted();
    }

    private function stoppedAtPrototype(): bool
    {
        $this->halt ??= sprintf('You stopped at the prototype, so I built nothing more. It is committed on %s.', $this->branch);

        return false;
    }

    private function prototypeSaid(string $at): string
    {
        return $at === ''
            ? sprintf('Your prototype is committed on %s, on sample data. Try it, then sign it off and I build the real thing, or tell me what to change.', $this->branch)
            : sprintf('Your prototype is ready on sample data: open %s in your app and try it. Sign it off and I build the real thing, or tell me what to change.', $at);
    }

    /**
     * Whether the build must stop here: the developer stopped or deleted it in
     * the app, or the repository moved off its branch, so nothing of it lands
     * on a branch they are working on.
     */
    private function halted(): bool
    {
        $this->halt ??= match (true) {
            $this->git->current() !== $this->branch => sprintf('Your repository moved off %s while I was building, so I stopped there: nothing more was written or committed.', $this->branch),
            $this->calledOff() => self::CALLED_OFF,
            default => null,
        };

        return $this->halt !== null;
    }

    /**
     * Whether the studio no longer asks for this build, because the developer
     * stopped or deleted it. A studio that cannot be reached has not.
     */
    private function calledOff(): bool
    {
        $work = $this->studio->scanWork();

        return $work !== null && ! collect($work['sessions'])->contains(fn (array $session): bool => (int) ($session['id'] ?? 0) === $this->session);
    }

    /**
     * The plan the studio sent, step by step, in the shape the run uses.
     *
     * @param  array<mixed>  $plan
     * @return list<PlannedStep>
     */
    private function stepsOf(array $plan): array
    {
        return array_values(array_map(fn (array $step): array => [
            'number' => (int) ($step['number'] ?? 0),
            'role' => (string) ($step['role'] ?? ''),
            'name' => (string) ($step['name'] ?? ''),
            'tone' => (string) ($step['tone'] ?? ''),
            'title' => (string) ($step['title'] ?? ''),
            'line' => (string) ($step['line'] ?? ''),
            'prototype' => (bool) ($step['prototype'] ?? false),
            'files' => array_values(array_map(fn (array $file): array => [
                'path' => (string) ($file['path'] ?? ''),
                'action' => (string) ($file['action'] ?? 'create'),
                'what' => (string) ($file['what'] ?? ''),
                'pattern' => (string) ($file['pattern'] ?? ''),
            ], array_filter((array) ($step['files'] ?? []), is_array(...)))),
        ], array_filter((array) ($plan['steps'] ?? []), is_array(...))));
    }

    /**
     * @param  PlannedStep  $step
     */
    private function queued(array $step): bool
    {
        $this->labels[(int) $step['number']] = (string) $step['title'];
        $this->record((int) $step['number'], array_column($step['files'], 'path'), 'queued', null, 0, 0, null);

        return true;
    }

    /**
     * @param  PlannedStep  $step
     */
    private function built(array $step, int $of): bool
    {
        if ($this->halted()) {
            return false;
        }

        $number = $step['number'];
        $files = $step['files'];
        $paths = array_column($files, 'path');
        $started = microtime(true);
        $this->rule = (string) $step['title'];
        $this->done[$number] = 0;
        $this->say(sprintf('%d of %d, %s: %s', $number, $of, $step['name'], $step['line']));
        $before = $this->typesBefore($files);

        $this->written($number, $files, null);

        if ($this->halted()) {
            return false;
        }

        $problems = $this->problems($number, $step, $files, $before);

        if ($problems !== []) {
            $this->say(sprintf('%d %s did not pass %s check. I am writing %s once more with exactly what it said.', count($problems), count($problems) === 1 ? 'file' : 'files', count($problems) === 1 ? 'its' : 'their', count($problems) === 1 ? 'it' : 'them'));
            $this->written($number, array_values(array_filter($files, fn (array $file): bool => isset($problems[$file['path']]))), collect($problems)->map(fn (string $why, string $path): string => $path.': '.$why)->implode("\n\n"));
            $this->failing = [...$this->failing, ...$this->problems($number, $step, $files, $before)];
        }

        $this->worked[$number] = microtime(true) - $started;

        return ! $this->halted() && $this->committed($number, $step, $paths);
    }

    /**
     * Sends the step's files to the studio and writes each one it sends back.
     *
     * @param  list<PlannedFile>  $files
     */
    private function written(int $number, array $files, ?string $failed): void
    {
        $paths = array_column($files, 'path');
        $sent = array_map(fn (array $file): array => ['path' => $file['path'], 'content' => $this->contentOf($file)], $files);
        $request = random_int(1, 2_000_000_000);
        $parts = $this->parts($sent);
        $related = $this->related($files);
        $this->record($number, $paths, 'running', null, 0, 0, null);
        $this->step(sprintf('Writing %d %s at once…', count($files), count($files) === 1 ? 'file' : 'files'));

        array_map(fn (array $part, int $index): ?array => $this->studio->submitScanWork(['session' => $this->session, 'propose' => [
            'id' => $request,
            'kind' => self::JOB,
            'step' => $number,
            'files' => $part,
            'failed' => $failed === null ? null : mb_substr($failed, 0, 2500),
            'more' => $index < count($parts) - 1,
            ...($index === 0 && $related !== [] ? ['related' => $related] : []),
        ]]), $parts, array_keys($parts));

        $this->streamed($number, $request, $paths, $this->placed(...));
        $this->landed();
        $this->studio->submitScanWork(['session' => $this->session, 'propose' => ['id' => $request, 'done' => true]]);
    }

    /**
     * Writes the step's files together once the studio has sent them all, new files
     * before changes, so the app never runs a change (a route, a binding)
     * whose new class is not on disk yet.
     */
    private function landed(): void
    {
        if ($this->halted()) {
            $this->arrived = [];

            return;
        }

        collect($this->arrived)
            ->sortBy(fn (array $file, string $path): int => is_file($this->root.'/'.$path) ? 1 : 0)
            ->each(function (array $file, string $path): void {
                $target = $this->root.'/'.$path;
                $creating = ! is_file($target);
                is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
                file_put_contents($target, $file['content']);
                $creating && $this->created[] = $path;
                $this->built = array_values(array_unique([...$this->built, $path]));
                $this->step($path.': '.($creating ? 'written' : 'changed').'.'.($file['note'] === '' ? '' : ' '.$file['note']));
            });
        $this->arrived = [];
    }

    /**
     * @param  PlannedFile  $file
     */
    private function contentOf(array $file): string
    {
        $path = $this->root.'/'.$file['path'];

        return $file['action'] === 'change' && is_file($path) && filesize($path) <= self::LARGEST_FILE ? (string) file_get_contents($path) : '';
    }

    /**
     * The files the studio reads while it writes these: the files each one is
     * patterned on, what this build already wrote, and what it read to plan
     * it. Only files in the repository or written by this build leave here.
     *
     * @param  list<PlannedFile>  $files
     * @return list<array{path: string, content: string, for: list<string>}>
     */
    private function related(array $files): array
    {
        $paths = array_column($files, 'path');
        $wanted = collect([...array_column($files, 'pattern'), ...$this->built, ...$this->read])
            ->filter(fn (string $path): bool => $path !== '' && ! in_array($path, $paths, true) && is_file($this->root.'/'.$path) && filesize($this->root.'/'.$path) <= self::LARGEST_RELATED)
            ->unique()
            ->values();
        $known = [...$this->git->tracked(array_values($wanted->all())), ...$this->built];

        return array_values($wanted
            ->filter(fn (string $path): bool => in_array($path, $known, true))
            ->take(self::MOST_RELATED)
            ->map(fn (string $path): array => ['path' => $path, 'content' => (string) file_get_contents($this->root.'/'.$path), 'for' => $paths])
            ->reduce(fn (array $kept, array $file): array => array_sum(array_map(fn (array $held): int => strlen($held['content']), $kept)) + strlen($file['content']) > self::MOST_RELATED_BYTES ? $kept : [...$kept, $file], []));
    }

    /**
     * Takes one file the studio sent: the whole file when it creates one, its
     * edits when it changes one, once it compiles. It is written with the rest of
     * its step.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function placed(string $path, array $proposal): string
    {
        $note = trim((string) ($proposal['note'] ?? ''));
        $content = is_string($proposal['content'] ?? null) ? (string) $proposal['content'] : '';
        $edits = array_values(array_map(fn (array $edit): array => ['find' => (string) ($edit['find'] ?? ''), 'replace' => (string) ($edit['replace'] ?? '')], array_filter((array) ($proposal['edits'] ?? []), is_array(...))));
        $file = $this->root.'/'.$path;
        $next = match (true) {
            str_contains($path, '..') || str_starts_with($path, '/') || preg_match('/(^|\/)\.env(?!\.example$)(\.|$)/', $path) === 1 => ['error' => 'I never write '.$path.'.'],
            $content !== '' && is_file($file) && ! in_array($path, $this->created, true) => ['error' => 'It is already on your machine, so I did not write over it.'],
            $content !== '' => ['content' => $content],
            $edits !== [] && is_file($file) => EditApplier::apply((string) file_get_contents($file), $edits),
            default => ['error' => $note !== '' ? $note : Says::named('{name} sent nothing for it.')],
        };
        $checked = isset($next['error']) ? ['content' => '', 'error' => (string) $next['error']] : PhpCheck::of($path, (string) $next['content']);

        if ($checked['error'] !== null) {
            $this->broken[$path] = $checked['error'];
            $this->step($path.': not written, because '.lcfirst($checked['error']));

            return $path;
        }

        $this->arrived[$path] = ['content' => $checked['content'], 'note' => $note];
        unset($this->broken[$path]);

        return $path;
    }

    /**
     * @param  list<PlannedFile>  $files
     * @return array<string, list<string>>
     */
    private function typesBefore(array $files): array
    {
        $changed = array_column(array_filter($files, fn (array $file): bool => $file['action'] === 'change'), 'path');

        return $changed === [] || ! $this->checks->canCheckTypes() ? [] : ($this->checks->types($changed) ?? []);
    }

    /**
     * What still stands in the way of this step: files that could not be
     * written, what PHPStan reports in them that it did not before, and the
     * tests the step wrote that fail.
     *
     * @param  PlannedStep  $step
     * @param  list<PlannedFile>  $files
     * @param  array<string, list<string>>  $before
     * @return array<string, string>
     */
    private function problems(int $number, array $step, array $files, array $before): array
    {
        $paths = array_column($files, 'path');
        $this->record($number, $paths, 'checking', null, 0, 0, null);
        $written = array_values(array_filter($paths, fn (string $path): bool => ! isset($this->broken[$path]) && is_file($this->root.'/'.$path)));
        $this->checks->format($written);
        $types = $this->checks->canCheckTypes() ? StepChecks::added($before, $this->checks->types($written) ?? []) : [];
        $tests = $step['role'] === self::TEST_ROLE ? $this->failingTests($written) : [];

        return [
            ...array_intersect_key($this->broken, array_flip($paths)),
            ...array_map(fn (array $found): string => 'PHPStan reports '.implode('; ', array_slice($found, 0, 8)), $types),
            ...$tests,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function failingTests(array $paths): array
    {
        $tests = array_values(array_filter($paths, fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php')));

        if ($tests === [] || $this->tests === null) {
            return [];
        }

        $this->step(sprintf('Running the %d %s I wrote.', count($tests), count($tests) === 1 ? 'test file' : 'test files'));
        $failures = $this->tests->failures($tests);

        return $failures === null
            ? array_fill_keys($tests, 'the tests could not run.')
            : collect($tests)
                ->mapWithKeys(fn (string $test): array => [$test => collect($failures)->filter(fn (string $why, string $name): bool => Str::before($name, ' › ') === $test)->map(fn (string $why, string $name): string => $name.': '.Str::limit($why, 600))->implode("\n")])
                ->filter()
                ->all();
    }

    /**
     * @param  PlannedStep  $step
     * @param  list<string>  $paths
     */
    private function committed(int $number, array $step, array $paths): bool
    {
        $changed = $this->git->changed($paths);
        $new = array_values(array_filter($this->created, fn (string $path): bool => in_array($path, $paths, true) && is_file($this->root.'/'.$path)));
        $left = array_values(array_intersect($paths, array_keys($this->broken)));
        $note = $this->noteOn($paths);
        $commit = $changed === [] && $new === [] ? null : $this->git->commit(
            'feat('.LocalBranch::commitScope().'): '.Str::lcfirst((string) $step['title']),
            collect([...$changed, ...$new])->unique()->sort()->map(fn (string $path): string => '- '.$path)->push(Says::named('- Written by {name}, checked and formatted on this machine'))->implode("\n"),
            only: $paths,
            new: $new,
        );

        $this->record($number, $paths, $commit === null ? 'skipped' : 'committed', $commit, count($paths) - count($left), count($left), $note === '' ? null : $note);
        $this->say($commit === null
            ? sprintf('Nothing in "%s" could be written, so there was nothing to commit. Why is in the app.', $step['title'])
            : sprintf('Committed %s: %s, %d %s.', substr($commit, 0, 7), $step['title'], count($paths) - count($left), count($paths) - count($left) === 1 ? 'file' : 'files'));

        return $commit !== null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function noteOn(array $paths): string
    {
        $failing = array_intersect_key($this->failing, array_flip($paths));
        $broken = array_intersect_key($this->broken, array_flip($paths));

        return trim(implode("\n\n", array_filter([
            collect($broken)->map(fn (string $why, string $path): string => 'I could not write '.$path.': '.$why)->implode("\n"),
            collect(array_diff_key($failing, $broken))->map(fn (string $why, string $path): string => $path.' still does not pass: '.$why)->implode("\n"),
        ])));
    }

    /**
     * The one stop: everything is committed, so the developer tries the app.
     *
     * @return array{decision: string, note: ?string}|null
     */
    private function checkedOver(): ?array
    {
        $committed = collect($this->batches)->where('state', 'committed');

        if ($committed->isEmpty()) {
            return null;
        }

        $this->say('Every step is committed. Checking that the files compile, your tests pass and your app boots…');
        $paths = array_values(array_unique(array_column($this->git->changesSince((string) $this->base), 'path')));
        $broken = collect($paths)
            ->filter(fn (string $path): bool => is_file($this->root.'/'.$path))
            ->mapWithKeys(fn (string $path): array => [$path => (string) PhpCheck::of($path, (string) file_get_contents($this->root.'/'.$path))['error']])
            ->filter()
            ->all();
        $covering = $this->tests?->for($paths) ?? [];
        $tests = array_values(array_unique([...$covering, ...array_filter($paths, fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php'))]));
        $failing = $tests === [] || $this->tests === null ? null : $this->tests->failing($tests);

        return $this->ask(0, $paths, $this->overall(array_values($committed->all()), $broken, $tests, $failing, $this->boots()), '', ['kind' => 'final', 'options' => [WorkRun::WORKS, WorkRun::BROKE]]);
    }

    /**
     * @param  list<array{rule: string, commit: ?string}>  $committed
     * @param  array<string, string>  $broken
     * @param  list<string>  $tests
     * @param  list<string>|null  $failing
     */
    private function overall(array $committed, array $broken, array $tests, ?array $failing, ?string $boot): string
    {
        $steps = count($this->batches);
        $left = collect($this->batches)->whereIn('state', ['skipped', 'failed'])->pluck('rule');
        $named = collect($committed)->pluck('rule')->join(', ', ' and ');

        return implode("\n\n", array_filter([
            (count($committed) === $steps
                ? sprintf('I committed %s on %s: %s.', $steps === 1 ? 'the step' : 'all '.$steps.' steps', $this->branch, $named)
                : sprintf('I committed %d of %d steps on %s: %s.', count($committed), $steps, $this->branch, $named))
                .($left->isEmpty() ? '' : ' I could not commit '.$left->join(', ', ' and ').'.'),
            trim(match (true) {
                $broken === [] && $boot === null => 'Every file compiles'.(is_file($this->root.'/artisan') ? ', and your app still boots.' : '.'),
                default => ($broken === [] ? '' : $this->brokenSaid($broken)).' '.($boot === null ? '' : 'Your app does not boot: '.rtrim($boot, '.').'.'),
            }),
            $this->testsSaid($tests, $failing),
            collect($this->created)->contains(fn (string $path): bool => str_starts_with($path, 'database/migrations/')) ? 'I added a migration, so run php artisan migrate first.' : '',
            sprintf('Try your app on %s, then tell me: does everything work?', $this->branch),
        ]));
    }

    /**
     * @param  array<string, string>  $broken
     */
    private function brokenSaid(array $broken): string
    {
        return count($broken) === 1
            ? sprintf('%s does not compile: %s.', basename((string) array_key_first($broken)), rtrim((string) reset($broken), '.'))
            : sprintf('%d files do not compile: %s.', count($broken), collect($broken)->map(fn (string $error, string $path): string => basename($path).' ('.rtrim($error, '.').')')->values()->join(', ', ' and '));
    }

    /**
     * The tests in a sentence each: those the build wrote, then the developer's own.
     *
     * @param  list<string>  $tests
     * @param  list<string>|null  $failing
     */
    private function testsSaid(array $tests, ?array $failing): string
    {
        [$mine, $yours] = collect($failing ?? [])->partition(fn (string $test): bool => in_array(Str::before($test, ' › '), $this->created, true));

        return match (true) {
            $tests === [] => '',
            $failing === null => 'Your tests could not run, so I could not check them.',
            $failing === [] => sprintf('All %d %s for these changes %s.', count($tests), count($tests) === 1 ? 'test file' : 'test files', count($tests) === 1 ? 'passes' : 'pass'),
            default => collect([[$mine, 'of the tests I wrote'], [$yours, 'of your tests now']])
                ->filter(fn (array $group): bool => $group[0]->isNotEmpty())
                ->map(fn (array $group): string => sprintf(
                    '%d %s %s, in %s, such as “%s”.',
                    $group[0]->count(),
                    $group[1],
                    $group[0]->count() === 1 ? 'fails' : 'fail',
                    $group[0]->map(fn (string $test): string => basename(Str::before($test, ' › '), '.php'))->unique()->join(', ', ' and '),
                    Str::after((string) $group[0]->first(), ' › '),
                ))
                ->implode(' '),
        };
    }

    /**
     * @param  array{decision: string, note: ?string}|null  $answer
     */
    private function finish(string $name, int $planned, ?array $answer): string
    {
        $committed = count(array_filter($this->batches, fn (array $batch): bool => $batch['state'] === 'committed'));
        $note = rtrim(trim((string) ($answer['note'] ?? '')), '.');
        $said = match (true) {
            $committed === 0 => 'Nothing in '.$name.' could be written, so nothing was committed. Why is in the app.',
            ($answer['decision'] ?? null) === WorkRun::BROKE => 'You said something broke'.($note === '' ? '' : ': '.$note).Says::named('. Tell {name} in the studio what you saw. What I built is committed on ').$this->branch.', and nothing was pushed.',
            ($answer['decision'] ?? null) === WorkRun::STOP => 'The build was closed in the studio. What I built is committed on '.$this->branch.', and nothing was pushed.',
            default => $name.' is built and committed on '.$this->branch.Says::named('. Nothing was pushed: ask {name} for the pull request in the studio.'),
        };

        $this->say($said);
        $this->progress->finished();
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'done', 'batches' => $this->batchesForTheStudio()]);

        return sprintf('%s on %s: %d of %d %s committed. Nothing was pushed.', $name, $this->branch, $committed, $planned, $planned === 1 ? 'step' : 'steps');
    }

    private function pulse(string $phase): void
    {
        $this->pulsedAt = microtime(true);
    }
}
