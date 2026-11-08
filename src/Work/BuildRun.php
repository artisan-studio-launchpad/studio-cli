<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Editor;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Prototype\Prototypes;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Scan\ProjectReads;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\TaskJournal;
use ArtisanStudio\StudioCli\Work\Concerns\AsksTheDeveloper;
use ArtisanStudio\StudioCli\Work\Concerns\ChecksTheMachine;
use ArtisanStudio\StudioCli\Work\Concerns\ReportsToTheStudio;
use ArtisanStudio\StudioCli\Work\Concerns\StreamsFromTheStudio;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Builds an idea planned in the studio, on a new local branch: step by step
 * the studio writes its files, this machine writes them, checks
 * them and commits the step, and at the end it stops once for the developer
 * to try their app. Nothing here talks to GitHub, and nothing is pushed.
 *
 * @phpstan-type PlannedFile array{path: string, action: string, what: string, pattern: string}
 * @phpstan-type PlannedStep array{number: int, role: string, name: string, tone: string, title: string, line: string, prototype: bool, demo: bool, files: list<PlannedFile>}
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

    private const string DEVELOPER_ASKS = 'The developer tried';

    private const string DEVELOPER_KEPT = 'The developer changed the files themselves';

    private const string GUIDE = 'retry';

    private const int RENDER_TRIES = 2;

    private const int PROTOTYPE_RENDER_TRIES = 3;

    private const int LONGEST_FAILED = 6000;

    private const int LONGEST_FAILURE = 1500;

    private const int MIGRATE_SECONDS = 120;

    private const int STILL_WRITING_EVERY = 30;

    private const int LARGEST_PLAN_FILE = 150_000;

    private const int PLAN_WAIT_SECONDS = 900;

    private const string DESTRUCTIVE = '/->(?:dropColumns?|dropIfExists|drop|renameColumn|rename|dropForeign|dropPrimary|dropUnique|dropIndex)\(|Schema::(?:drop|dropIfExists|rename)\(|DB::(?:statement|unprepared)\(|truncate\(/';

    private const int LOOK_TRIES = 1;

    private const int SHOT_WIDTH = 1440;

    private const int SHOT_HEIGHT = 810;

    private const string CALLED_OFF = 'You stopped the build in the app, so I stopped there: nothing more was written or committed.';

    private ?string $halt = null;

    private ?string $branch = null;

    private ?string $base = null;

    private ?string $from = null;

    private ?string $buildUuid = null;

    private ?ProjectReads $reads = null;

    private bool $saidNoScreenshots = false;

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
     * @var list<string>
     */
    private array $inEditor = [];

    /**
     * @var array<string, string>
     */
    private array $copies = [];

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

    /**
     * The last version of a file that did not pass its check and so was not
     * written, by path: the next try fixes it instead of starting again.
     *
     * @var array<string, string>
     */
    private array $attempted = [];

    /**
     * @var array<string, string>
     */
    private array $lastRendered = [];

    /**
     * @var list<PlannedStep>
     */
    private array $planned = [];

    private ?string $prototypeBroken = null;

    private bool $kept = false;

    private bool $deferred = false;

    /**
     * @var list<string>
     */
    private array $writing = [];

    private float $writingSince = 0.0;

    private int $stillAt = 30;

    private ?string $lastRenderError = null;

    public function __construct(
        private readonly string $root,
        private readonly Studio $studio,
        private readonly LocalBranch $git,
        private readonly WorkProgress $progress,
        private readonly TaskJournal $journal,
        private readonly StepChecks $checks,
        private readonly int $pollSeconds = 1,
        private readonly int $pickupSeconds = 300,
        private readonly ?TestRunner $tests = null,
        private readonly ?Editor $editor = null,
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
        $this->planned = $steps;
        $refusal = $this->refusal() ?? ($steps === [] ? 'There is no plan to build from.' : null);

        if ($refusal !== null) {
            return $this->handedBack($name, $refusal);
        }

        $this->from = $this->git->current();
        $this->branch = $this->git->startAt((string) ($plan['branch'] ?? '') ?: LocalBranch::buildPrefix().'idea');

        if ($this->branch === null) {
            return $this->handedBack($name, 'I could not start a new branch, so nothing was changed.');
        }

        $this->base = $this->git->head();
        $this->read = array_values(array_filter((array) ($plan['read'] ?? []), is_string(...)));
        $this->copies = $this->copiesFrom((array) ($plan['copies'] ?? []));
        $paths = array_merge([], ...array_map(fn (array $step): array => array_column($step['files'], 'path'), $steps));
        $this->progress->begin($this->session, self::JOB, $name, 'terminal', $this->branch, $paths);
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'running', 'branch' => $this->branch, 'from' => $this->from, 'refused' => null, 'batches' => []]);
        preg_match('#^https?://[^\s/?\#]+(:\d+)?/?$#', (string) config('app.url')) === 1 && $this->studio->submitScanWork(['session' => $this->session, 'app_url' => (string) config('app.url')]);
        $wireframe = $plan['wireframe_component'] ?? $plan['prototype_component'] ?? null;
        is_string($ask['build'] ?? null) && is_string($wireframe) && Prototypes::at($this->root)->remember($ask['build'], $wireframe);
        is_string($ask['build'] ?? null) && ($plan['wireframe_component'] ?? null) !== null && is_string($plan['prototype_component'] ?? null) && Prototypes::at($this->root)->remember($ask['build'], $plan['prototype_component'], Prototypes::PROTOTYPE);
        $this->buildUuid = is_string($ask['build'] ?? null) && is_string($wireframe) ? $ask['build'] : null;
        $this->deferred = ($plan['rest'] ?? null) === 'pending';
        $this->progress->planned(count($steps));
        $this->say(sprintf('I build %s on %s in %d %s. I write each step, check it and commit it on its own, and at the end I stop once so you can try your app.', $name, $this->branch, count($steps), count($steps) === 1 ? 'step' : 'steps'));
        array_map(fn (array $step): mixed => $this->queued($step), $steps);
        collect($steps)->each(fn (array $step): bool => $this->carriedOn($step, count($steps), (string) ($plan['prototype_at'] ?? '')));

        if (($plan['rest'] ?? null) === 'pending' && ! $this->halted()) {
            $steps = $this->demonstrated($steps, $plan);
            $this->halted() || $this->based();
            $fresh = $this->halted() ? null : $this->planOfTheRest($steps);
            $rest = $fresh === null ? [] : array_slice($this->stepsOf($fresh), count($steps));

            if ($fresh !== null) {
                $plan = $fresh;
                $steps = [...$steps, ...$rest];
                $this->planned = $steps;
                $this->read = array_values(array_unique([...$this->read, ...array_values(array_filter((array) ($fresh['read'] ?? []), is_string(...)))]));
                $this->progress->planned(count($steps));
                array_map(fn (array $step): mixed => $this->queued($step), $rest);
                collect($rest)->each(fn (array $step): bool => $this->carriedOn($step, count($steps), (string) ($plan['prototype_at'] ?? '')));
            }
        }

        if ($this->halt === self::CALLED_OFF) {
            $this->step($this->halt);
            $this->progress->forget();

            return $this->halt;
        }

        if ($this->halt !== null) {
            return $this->refuse($name, 'terminal', $this->halt, true);
        }

        return $this->finish($name, count($steps), $this->triedOut($steps, (string) ($plan['built_at'] ?? '')));
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
        $closing = $step['prototype'] && $this->closesItsStage($step);

        if ($closing && ! $this->halted()) {
            $at = $this->checkedPrototype($step, $at);
        }

        return ! $this->halted() && (! $closing || ($this->approved($step, $at) && ($this->deferred || $this->based())));
    }

    /**
     * Whether no later step belongs to the same stage, so the developer opens
     * the stage and signs it off once, after its last step and not before.
     *
     * @param  PlannedStep  $step
     */
    private function closesItsStage(array $step): bool
    {
        return ! collect($this->planned)->contains(fn (array $other): bool => $other['prototype'] && $other['demo'] === $step['demo'] && $other['number'] > $step['number']);
    }

    /**
     * The one commit of a prototype stage: made when the developer signs it
     * off, never before, so nothing is committed that they have not seen.
     *
     * @param  PlannedStep  $step
     */
    private function approved(array $step, string $at): bool
    {
        if (! $this->signedOff($step, $at)) {
            return false;
        }

        $this->committed($step['number'], $step, array_column($step['files'], 'path'));

        return ! $this->halted();
    }

    /**
     * Opens the prototype and looks at it, after every time it is written, so
     * the developer is never handed one that does not render.
     *
     * @param  PlannedStep  $step
     */
    private function checkedPrototype(array $step, string $at): string
    {
        $at = $this->rendered($step, $at, self::PROTOTYPE_RENDER_TRIES);

        return $this->looked($step, $at, self::LOOK_TRIES);
    }

    /**
     * Looks at the prototype the way the developer will, in a browser, and has
     * SAMI say what is wrong with how it looks, so she fixes that before the
     * developer is ever asked to try it.
     *
     * @param  PlannedStep  $step
     */
    private function looked(array $step, string $at, int $tries): string
    {
        if ($this->kept || $this->prototypeBroken !== null || $this->buildUuid === null || $this->halted() || ! Prototypes::served() || ! (bool) config('studio-cli.prototypes.review', true) || ! str_starts_with((string) config('app.url'), 'http')) {
            return $at;
        }

        $app = rtrim((string) config('app.url'), '/');
        $sends = config('studio-cli.prototypes.send_screenshots') === true;
        $shots = ! $sends ? [] : collect(['layout' => $app.'/prototype/'.$this->buildUuid, 'full' => $app.'/prototype/'.$this->buildUuid.'?full-page', 'modal' => $app.'/?prototype='.$this->buildUuid])
            ->map(fn (string $url, string $view): array => ['view' => $view, 'image' => app('studio-cli.prototype.shot')($url, self::SHOT_WIDTH, self::SHOT_HEIGHT), 'size' => self::SHOT_WIDTH.'x'.self::SHOT_HEIGHT])
            ->filter(fn (array $shot): bool => is_string($shot['image']))
            ->values()
            ->all();

        if ($sends && $shots === []) {
            $this->say('I could not open a browser on this machine to look at the prototype, so I could not check how it looks.');

            return $at;
        }

        $review = null;

        if ($sends) {
            $this->say('I’m looking at the prototype the way you will, in your layout, full page and in a modal, before you do.');
            $review = $this->studio->reviewPrototype($this->session, $shots);
        } elseif (! $this->saidNoScreenshots) {
            $this->saidNoScreenshots = true;
            $this->say(__('studio-cli::build.screenshots.not_sent'));
        }

        $measured = $this->measured(['full' => $app.'/prototype/'.$this->buildUuid.'?full-page', 'modal' => $app.'/?prototype='.$this->buildUuid]);
        $found = [...$measured, ...($review['problems'] ?? [])];

        if ($found === []) {
            $this->say(match (true) {
                ! $sends => __('studio-cli::build.screenshots.measured_only'),
                $review === null => 'I could not get a look at it, so it goes to you as it is.',
                default => 'It looks finished to me.',
            });

            return $at;
        }

        $review = ['looks_finished' => false, 'problems' => $found];
        $problems = implode('; ', array_map(fn (string $problem): string => rtrim($problem, '.'), $review['problems']));

        if ($tries === 0) {
            $this->say(sprintf('It still needs work, so you will see it as it is: %s.', $problems));

            return $at;
        }

        $this->say(sprintf('It does not look finished: %s. I’m fixing it.', $problems));
        $files = $this->filesNamed($step, $review['problems']);
        $this->written($step['number'], $files, "Screenshots of the prototype, as the developer will see it in their layout, full page and in a modal, were reviewed. Fix these, and only these:\n- ".implode("\n- ", $review['problems']));

        return $this->looked($step, $this->rendered($step, $at, self::PROTOTYPE_RENDER_TRIES), $tries - 1);
    }

    /**
     * @param  PlannedStep  $step
     * @param  list<string>  $problems
     * @return list<PlannedFile>
     */
    private function filesNamed(array $step, array $problems): array
    {
        $text = implode("\n", $problems);
        preg_match_all('/<\w+ \.([^>\s]+)>/', $text, $found);
        $tokens = array_map(fn (string $classes): array => array_values(array_filter(explode('.', $classes), fn (string $token): bool => strlen($token) > 2)), $found[1]);
        $named = array_values(array_filter($step['files'], function (array $file) use ($text, $tokens): bool {
            $code = is_file($this->root.'/'.$file['path']) ? (string) file_get_contents($this->root.'/'.$file['path']) : '';

            return str_contains($text, $file['path'])
                || str_contains($text, Str::before(basename($file['path']), '.'))
                || collect($tokens)->contains(fn (array $classes): bool => $classes !== [] && collect($classes)->every(fn (string $token): bool => str_contains($code, $token)));
        }));

        return $named === [] ? $step['files'] : $named;
    }

    /**
     * @param  array<string, string>  $urls
     * @return list<string>
     */
    private function measured(array $urls): array
    {
        return collect($urls)
            ->flatMap(fn (string $url, string $view): array => array_map(
                fn (string $finding): string => 'Measured in the '.$view.' view: '.$finding,
                (array) (app('studio-cli.prototype.audit')($url, self::SHOT_WIDTH, self::SHOT_HEIGHT) ?? []),
            ))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * After the wireframe is signed off, asks whether to make it a quick
     * prototype when the plan says the scope has something to demonstrate, and
     * builds that step when the answer is yes.
     *
     * @param  list<PlannedStep>  $steps
     * @param  array<string, mixed>  $plan
     * @return list<PlannedStep>
     */
    private function demonstrated(array $steps, array $plan): array
    {
        if ($this->halted()) {
            return $steps;
        }

        $answer = $this->ask(0, [], 'The wireframe is signed off. Before I build the real thing, would you like a prototype: the whole product on demo data, written in small parts, with no database? Your wireframe stays as it is, beside it.', '', ['kind' => 'demo', 'options' => [WorkRun::APPROVE, WorkRun::SKIP]]);

        if (($answer['decision'] ?? null) !== WorkRun::APPROVE || $this->halted()) {
            return $steps;
        }

        $fresh = $this->planOfThePrototype($steps);
        $added = $fresh === null ? [] : array_slice($this->stepsOf($fresh), count($steps));

        if ($added === []) {
            return $steps;
        }

        $all = [...$steps, ...$added];
        $this->planned = $all;
        $this->copies = $this->copiesFrom((array) ($fresh['copies'] ?? []));
        is_string($this->buildUuid) && is_string($fresh['prototype_component'] ?? null) && Prototypes::at($this->root)->remember($this->buildUuid, $fresh['prototype_component'], Prototypes::PROTOTYPE);
        $at = $this->buildUuid === null ? '' : '/prototype/'.$this->buildUuid;
        $this->progress->planned(count($all));
        array_map(fn (array $step): mixed => $this->queued($step), $added);
        collect($added)->each(fn (array $step): mixed => $this->carriedOn($step, count($all), $at));

        return $all;
    }

    /**
     * Sends the signed-off wireframe to the studio and waits for the plan of
     * the prototype, which is files of its own beside it.
     *
     * @param  list<PlannedStep>  $steps
     * @return ?array<string, mixed>
     */
    private function planOfThePrototype(array $steps): ?array
    {
        return $this->timed('plan', 'sending the signed-off wireframe and waiting for the plan of the prototype', function () use ($steps): ?array {
            $this->say('I’m planning the prototype from the wireframe you signed off: every screen on demo data, in small parts.');
            $this->studio->submitScanWork(['session' => $this->session, 'plan_request' => ['stage' => 'prototype', 'files' => $this->signedOffFiles($steps)]]);
            $started = microtime(true);

            while (! $this->halted()) {
                $mine = collect($this->studio->scanWork()['sessions'] ?? [])->first(fn (array $session): bool => (int) ($session['id'] ?? 0) === $this->session);
                $state = $mine['plan']['prototype_stage'] ?? null;

                if ($state === 'planned') {
                    return (array) $mine['plan'];
                }

                if ($state === 'failed' || microtime(true) - $started > self::PLAN_WAIT_SECONDS) {
                    $this->say(sprintf('%s I carry on to the real build from your wireframe.', (string) ($mine['plan']['prototype_reason'] ?? 'I could not plan the prototype in time.')));

                    return null;
                }

                $this->waitedFor();
            }

            return null;
        });
    }

    /**
     * The files of the last stage the developer signed off, which is the
     * prototype when there is one and the wireframe when there is not.
     *
     * @param  list<PlannedStep>  $steps
     * @return list<array{path: string, content: string}>
     */
    private function signedOffFiles(array $steps): array
    {
        $staged = array_values(array_filter($steps, fn (array $step): bool => $step['prototype']));
        $last = array_values(array_filter($staged, fn (array $step): bool => $step['demo'])) ?: $staged;

        return collect($last)
            ->flatMap(fn (array $step): array => array_column($step['files'], 'path'))
            ->unique()
            ->filter(fn (string $path): bool => is_file($this->root.'/'.$path) && filesize($this->root.'/'.$path) <= self::LARGEST_PLAN_FILE)
            ->map(fn (string $path): array => ['path' => $path, 'content' => $this->reads()->readable($path)])
            ->filter(fn (array $file): bool => is_string($file['content']))
            ->values()
            ->all();
    }

    /**
     * What may leave this machine: one filter for every file this build sends.
     */
    private function reads(): ProjectReads
    {
        return $this->reads ??= new ProjectReads($this->root);
    }

    /**
     * @param  array<mixed>  $copies
     * @return array<string, string>
     */
    private function copiesFrom(array $copies): array
    {
        return array_filter($copies, fn (mixed $copy, mixed $prototype): bool => is_string($copy) && is_string($prototype) && ! str_contains($copy.$prototype, '..'), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Sends the signed-off prototype's files to the studio and waits for the
     * plan of everything after it.
     *
     * @param  list<PlannedStep>  $steps
     * @return array<string, mixed>|null
     */
    private function planOfTheRest(array $steps): ?array
    {
        return $this->timed('plan', 'sending the signed-off prototype and waiting for the plan of the real build', fn (): ?array => $this->planOfTheRestNow($steps));
    }

    /**
     * @param  list<PlannedStep>  $steps
     * @return ?array<string, mixed>
     */
    private function planOfTheRestNow(array $steps): ?array
    {
        $files = $this->signedOffFiles($steps);
        $this->say('I’m planning the real build from the '.$this->signedOffStage().' you signed off.');
        $this->studio->submitScanWork(['session' => $this->session, 'plan_request' => ['files' => $files]]);
        $started = microtime(true);

        while (! $this->halted()) {
            $mine = collect($this->studio->scanWork()['sessions'] ?? [])->first(fn (array $session): bool => (int) ($session['id'] ?? 0) === $this->session);
            $state = $mine['plan']['rest'] ?? null;

            if ($state === 'planned') {
                return (array) $mine['plan'];
            }

            if ($state === 'failed' || microtime(true) - $started > self::PLAN_WAIT_SECONDS) {
                $this->halt ??= sprintf('%s Your prototype is committed on %s, and I built nothing more.', (string) ($mine['plan']['rest_reason'] ?? 'I could not plan the real build in time.'), $this->branch);

                return null;
            }

            $this->waitedFor();
        }

        return null;
    }

    private function based(): bool
    {
        return $this->timed('copy', 'copying the signed-off prototype as the base of the real build and committing it', fn (): bool => $this->basedNow());
    }

    private function basedNow(): bool
    {
        $copied = collect($this->copies)
            ->filter(fn (string $copy, string $prototype): bool => is_file($this->root.'/'.$prototype) && ! is_file($this->root.'/'.$copy))
            ->each(function (string $copy, string $prototype): void {
                is_dir(dirname($this->root.'/'.$copy)) || mkdir(dirname($this->root.'/'.$copy), 0755, true);
                file_put_contents($this->root.'/'.$copy, PrototypeCopy::of((string) file_get_contents($this->root.'/'.$prototype), $this->copies));
            });

        if ($copied->isEmpty()) {
            return ! $this->halted();
        }

        $paths = array_values($copied->all());
        $this->created = [...$this->created, ...$paths];
        $this->built = array_values(array_unique([...$this->built, ...$paths]));
        $commit = $this->git->commit(
            'feat('.LocalBranch::commitScope().'): the signed-off prototype as the base of the real build',
            $copied->map(fn (string $copy, string $prototype): string => '- '.$copy.', copied from '.$prototype)->implode("\n")."\n".Says::named('- Copied by {name} on this machine, so the prototype itself stays as it is'),
            only: $paths,
            new: $paths,
        );
        $this->say(sprintf('I copied the prototype you signed off to %s, the base the build gives real data%s.', implode(', ', $paths), $commit === null ? '' : ', and committed it as '.substr($commit, 0, 7)));

        return ! $this->halted();
    }

    private function routed(string $builtAt): string
    {
        $file = $this->root.'/routes/web.php';

        if ($this->copies === [] || ! is_file($file) || $this->halted()) {
            return $builtAt;
        }

        $routed = PrototypeCopy::routed((string) file_get_contents($file), $this->copies);
        $routed['unplaced'] === [] || $this->say(sprintf('Nothing in the build routes to %s, and I could not tell which part of your app it belongs with, so I added no route for it.', implode(', ', $routed['unplaced'])));
        $checked = PhpCheck::of('routes/web.php', $routed['routes']);

        if ($routed['added'] === [] || $checked['error'] !== null) {
            return $builtAt;
        }

        file_put_contents($file, $checked['content']);
        $commit = $this->git->commit('feat('.LocalBranch::commitScope().'): the route to the real page', '- routes/web.php: '.implode(', ', $routed['added']).Says::named(', which the build left out, added beside the routes it belongs with by {name}'), only: ['routes/web.php']);
        $this->say(sprintf('The build left out the route to %s, so I added it beside the routes it belongs with%s.', implode(', ', $routed['added']), $commit === null ? '' : ' and committed it as '.substr($commit, 0, 7)));

        return str_starts_with($builtAt, '/') ? $builtAt : $routed['added'][0];
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

        $missing = array_values(array_filter($step['files'], fn (array $file): bool => ! is_file($this->root.'/'.$file['path'])));

        if ($missing !== [] && $tries > 0) {
            $this->say(sprintf('%s did not arrive, so I’m writing %s before I open the page.', implode(', ', array_map(fn (array $file): string => basename($file['path']), $missing)), count($missing) === 1 ? 'it' : 'them'));
            $this->written($step['number'], $missing, 'This file is in the plan but was never written. Write it now, and nothing else.');

            return $this->rendered($step, $at, $tries - 1);
        }

        $this->say(sprintf('I’m opening %s to check the prototype renders before you try it.', $at));
        $opened = $this->timed('render', 'opening '.$at.' in the app to check the prototype renders', fn (): array => $this->renders($at), $step['number'], null, fn (array $page): string => $page['error'] ?? 'renders');
        $error = $opened['error'];
        $again = $error !== null && $this->lastRenderError === $error;
        $this->lastRenderError = $error;

        if ($opened['missing']) {
            $this->say(sprintf('%s is not a page in your app, so I could not open it. Try the prototype where it opens.', $opened['path']));

            return $at;
        }

        if ($error === null) {
            $this->prototypeBroken = null;
            $opened['redirect'] === null && $this->lastRendered = $this->contentsOf($step['files']);
            $this->say($opened['redirect'] === null ? sprintf('%s renders.', $opened['path']) : sprintf('%s sent me on to %s, so I could not see it render.', $opened['path'], $opened['redirect']));

            return $opened['path'];
        }

        if ($tries === 0 || $again) {
            $this->prototypeBroken = $error;
            $this->restored($step, $opened['path'], $error) || $this->say(sprintf('%s still does not render: %s. I’m stopping so you can see it.', $opened['path'], rtrim($error, '.')));

            return $opened['path'];
        }

        $this->say(sprintf('%s does not render: %s. I’m fixing it.', $opened['path'], rtrim($error, '.')));
        $files = $tries === self::PROTOTYPE_RENDER_TRIES ? $this->filesNamed($step, [$error]) : $step['files'];
        $this->written($step['number'], $files, 'Opening '.$opened['path'].' in the app failed with: '.$error);

        return $this->rendered($step, $at, $tries - 1);
    }

    /**
     * @param  list<PlannedFile>  $files
     * @return array<string, string>
     */
    private function contentsOf(array $files): array
    {
        return collect($files)
            ->pluck('path')
            ->filter(fn (string $path): bool => is_file($this->root.'/'.$path))
            ->mapWithKeys(fn (string $path): array => [$path => (string) file_get_contents($this->root.'/'.$path)])
            ->all();
    }

    /**
     * @param  PlannedStep  $step
     */
    private function restored(array $step, string $path, string $error): bool
    {
        if ($this->lastRendered === [] || $this->lastRendered === $this->contentsOf($step['files'])) {
            return false;
        }

        collect($this->lastRendered)->each(fn (string $content, string $file): bool => file_put_contents($this->root.'/'.$file, $content) !== false);
        $this->prototypeBroken = null;
        $this->say(sprintf('That change broke %s (%s), and I could not fix it, so I put back the version that rendered.', $path, rtrim($error, '.')));

        return true;
    }

    /**
     * @param  list<PlannedStep>  $steps
     */
    private function renderedBuilt(array $steps, string $at, int $tries): void
    {
        if (! str_starts_with($at, '/') || $this->halted()) {
            return;
        }

        $this->say(sprintf('I’m opening %s to check what I built renders before you try it.', $at));
        $opened = $this->timed('render', 'opening '.$at.' in the app to check the build renders', fn (): array => $this->renders($at), null, null, fn (array $page): string => $page['error'] ?? 'renders');
        $error = $opened['error'];
        $fixing = $error === null || $opened['missing'] || $opened['denied'] ? null : $this->stepThatWrote($steps, $error);

        if ($error === null && $opened['redirect'] === null && ! $opened['missing'] && ! $opened['denied']) {
            $this->studio->submitScanWork(['session' => $this->session, 'opened' => $opened['path']]);
        }

        if ($fixing === null || $tries === 0) {
            $this->say(match (true) {
                $opened['missing'] => sprintf('%s is not a page in your app, so I could not open it.', $opened['path']),
                $opened['denied'] => sprintf('%s does not let your user in, so I could not see it render.', $opened['path']),
                $opened['redirect'] !== null => sprintf('%s sent me on to %s, so I could not see it render.', $opened['path'], $opened['redirect']),
                $error === null => sprintf('%s renders.', $opened['path']),
                default => sprintf('%s still does not render: %s. I’m stopping so you can see it.', $opened['path'], rtrim($error, '.')),
            });

            return;
        }

        $this->say(sprintf('%s does not render: %s. I’m fixing %s.', $opened['path'], rtrim($error, '.'), implode(', ', array_column($fixing['files'], 'path'))));
        $this->written($fixing['number'], $fixing['files'], 'Opening '.$opened['path'].' in the app failed with: '.$error);
        $this->committed($fixing['number'], $fixing, array_column($fixing['files'], 'path'));
        $this->renderedBuilt($steps, $at, $tries - 1);
    }

    /**
     * @param  list<PlannedStep>  $steps
     * @return PlannedStep|null
     */
    private function stepThatWrote(array $steps, string $error): ?array
    {
        $built = collect($steps)->reject(fn (array $step): bool => $step['prototype'])->reverse()->values();
        $named = $built
            ->map(fn (array $step): array => [...$step, 'files' => array_values(array_filter($step['files'], fn (array $file): bool => str_contains($error, $file['path'])))])
            ->first(fn (array $step): bool => $step['files'] !== []);

        return $named ?? $built->first(fn (array $step): bool => $step['role'] !== self::TEST_ROLE);
    }

    /**
     * Stops at the prototype until the developer signs it off. Each time they
     * say what to change, the prototype is written again and committed.
     *
     * @param  PlannedStep  $step
     */
    private function signedOff(array $step, string $at): bool
    {
        $answer = $this->ask($step['number'], array_column($step['files'], 'path'), $this->prototypeSaid($at, $step), '', ['kind' => 'prototype', 'options' => [self::SIGN_OFF, self::GUIDE]]);

        return match ($answer['decision']) {
            self::SIGN_OFF => true,
            self::GUIDE => $this->guided($step, (string) $answer['note']) && $this->signedOff($step, $this->checkedPrototype($step, $at)),
            default => $this->stoppedAtPrototype(),
        };
    }

    /**
     * @param  PlannedStep  $step
     */
    private function guided(array $step, string $note): bool
    {
        $this->kept = str_starts_with(trim($note), self::DEVELOPER_KEPT);

        if ($this->kept) {
            $this->say('I keep the changes you made to the prototype’s files as they are.');

            return ! $this->halted();
        }

        $asked = str_starts_with(trim($note), self::DEVELOPER_ASKS) ? trim($note) : 'The developer tried the prototype and asks: '.trim($note);
        $this->say('I’m changing the prototype: '.rtrim(Str::after($asked, 'asks: '), '.').'.');
        $this->written($step['number'], $step['files'], $asked);

        return ! $this->halted();
    }

    private function stoppedAtPrototype(): bool
    {
        $this->halt ??= sprintf('You stopped at the prototype, so I built nothing more. It is committed on %s.', $this->branch);

        return false;
    }

    /**
     * @param  PlannedStep|array{}  $step  the stage the developer is asked to sign off
     */
    private function prototypeSaid(string $at, array $step = []): string
    {
        $stage = strtolower((string) ($step['name'] ?? 'prototype'));
        $data = $stage === 'wireframe' ? ', on sample data' : ', every screen on demo data with no database';

        return $at === ''
            ? sprintf('Your %s is written on %s%s. Try it, then sign it off and I build the real thing, or tell me what to change.', $stage, $this->branch, $data)
            : sprintf('Your %s is ready%s. Open it from the links, try it, then sign it off and I plan and build the real thing, or tell me what to change.', $stage, $data);
    }

    private function signedOffStage(): string
    {
        return strtolower((string) (collect($this->planned)->filter(fn (array $step): bool => $step['prototype'])->last()['name'] ?? 'prototype'));
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
            'demo' => (bool) ($step['demo'] ?? false),
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
        $before = $step['prototype'] ? [] : $this->typesBefore($files);

        $this->written($number, $files, null);

        if ($this->halted()) {
            return false;
        }

        $problems = $this->problems($number, $step, $files, $before);
        $problems = $step['role'] === self::TEST_ROLE && $problems !== [] ? $this->codeFixedFor($problems, $number, $step, $files, $before) : $problems;

        if ($problems !== []) {
            $this->say(sprintf('%d %s did not pass %s check. I am writing %s once more with exactly what it said.', count($problems), count($problems) === 1 ? 'file' : 'files', count($problems) === 1 ? 'its' : 'their', count($problems) === 1 ? 'it' : 'them'));
            $this->written($number, array_values(array_filter($files, fn (array $file): bool => isset($problems[$file['path']]))), collect($problems)->map(fn (string $why, string $path): string => $path.': '.$why)->implode("\n\n"));
            $this->failing = [...$this->failing, ...$this->problems($number, $step, $files, $before)];
        }

        $this->worked[$number] = microtime(true) - $started;

        return ! $this->halted() && ($step['prototype'] || $this->committed($number, $step, $paths));
    }

    /**
     * A file the build copied from the signed-off prototype that comes out of
     * its step still holding the prototype's sample data: untouched, or with
     * the figures it was given for its charts and counts still written in.
     * The real build never keeps them.
     *
     * @param  list<PlannedFile>  $files
     * @return array<string, string>
     */
    private function stillSample(array $files): array
    {
        $prototypeOf = array_flip($this->copies);

        return collect($files)
            ->mapWithKeys(fn (array $file): array => [$file['path'] => $this->sampleLeftIn($file['path'], $prototypeOf[$file['path']] ?? null)])
            ->filter()
            ->all();
    }

    private function sampleLeftIn(string $path, ?string $prototype): ?string
    {
        $real = $this->root.'/'.$path;

        if ($prototype === null || ! is_file($real) || ! is_file($this->root.'/'.$prototype)) {
            return null;
        }

        $was = (string) file_get_contents($this->root.'/'.$prototype);
        $now = (string) file_get_contents($real);

        if (preg_replace('/\s+/', '', $now) === preg_replace('/\s+/', '', $was)) {
            return $path.' is still exactly the prototype it was copied from: none of the changes the plan asks for were made. Make them, and replace every sample figure with the data the component gives it.';
        }

        $left = array_values(array_intersect($this->sampleFigures($was), $this->sampleFigures($now)));

        return $left === [] ? null : $path.' still holds the prototype\'s sample data: '.implode('; ', array_slice($left, 0, 4)).'. Replace each with what the component gives it, so the real page never shows made-up figures.';
    }

    /**
     * The figures a prototype writes into its charts and counts.
     *
     * @return list<string>
     */
    private function sampleFigures(string $code): array
    {
        preg_match_all('/(?::items|:values|:series|:data)="(\[[^"]{8,})"|:to="(\d{3,}(?:\.\d+)?)"/', $code, $found);

        return array_values(array_unique(array_filter([...$found[1], ...$found[2]])));
    }

    /**
     * @param  array<string, string>  $problems
     * @param  PlannedStep  $step
     * @param  list<PlannedFile>  $files
     * @param  array<string, list<string>>  $before
     * @return array<string, string>
     */
    /**
     * Fixes the files of an earlier step because the tests of this one failed
     * in them, and shows it as this step's work: the earlier step stays done.
     *
     * @param  PlannedStep  $step  the earlier step whose files are fixed
     */
    private function refixedUnder(int $under, array $step, string $note): bool
    {
        $paths = array_column($step['files'], 'path');
        $this->written($under, $step['files'], $note);

        $this->git->changed($paths) === [] || $this->committed($under, [...$step, 'title' => 'the tests found a fault in '.lcfirst((string) $step['title'])], $paths);

        return ! $this->halted();
    }

    private function codeFixedFor(array $problems, int $number, array $step, array $files, array $before): array
    {
        $why = implode("\n\n", $problems);
        $named = collect($this->planned)
            ->reject(fn (array $each): bool => $each['prototype'] || $each['number'] === $number)
            ->map(fn (array $each): array => [...$each, 'files' => array_values(array_filter($each['files'], fn (array $file): bool => ! str_starts_with($file['path'], 'tests/') && str_contains($why, $file['path'])))])
            ->filter(fn (array $each): bool => $each['files'] !== [])
            ->values();

        if ($named->isEmpty() || $this->halted()) {
            return $problems;
        }

        $this->say(sprintf('The tests fail inside %s, so I’m fixing that code, not the tests.', $named->flatMap(fn (array $each): array => array_column($each['files'], 'path'))->implode(', ')));
        $named->each(fn (array $each): bool => $this->refixed($each, "The tests of this build fail with an error inside this file. Fix the code so it no longer fails; the tests stay as they are.\n\n".$why, $number));

        return $this->problems($number, $step, $files, $before);
    }

    /**
     * Sends the step's files to the studio and writes each one it sends back.
     *
     * @param  list<PlannedFile>  $files
     */
    private function written(int $number, array $files, ?string $failed): void
    {
        $this->timed('write', sprintf('asking the studio to write %d %s%s, and waiting for them', count($files), count($files) === 1 ? 'file' : 'files', $failed === null ? '' : ' again, with what failed'), fn () => $this->writtenNow($number, $files, $failed), $number, null, fn (): string => implode(', ', array_map('basename', array_column($files, 'path'))));
    }

    /**
     * @param  list<PlannedFile>  $files
     */
    private function writtenNow(int $number, array $files, ?string $failed): void
    {
        $paths = array_column($files, 'path');
        $sent = array_map(fn (array $file): array => ['path' => $file['path'], 'content' => $this->contentOf($file)], $files);
        $request = random_int(1, 2_000_000_000);
        $parts = $this->parts($sent);
        $related = $this->related($files);
        $this->record($number, $paths, 'running', null, 0, 0, null);
        $this->step(sprintf('Writing %d %s at once…', count($files), count($files) === 1 ? 'file' : 'files'));
        $this->writing = $paths;
        $this->writingSince = microtime(true);
        $this->stillAt = self::STILL_WRITING_EVERY;

        array_map(fn (array $part, int $index): ?array => $this->studio->submitScanWork(['session' => $this->session, 'propose' => [
            'id' => $request,
            'kind' => self::JOB,
            'step' => $number,
            'files' => $part,
            'failed' => $failed === null ? null : mb_substr($failed, 0, self::LONGEST_FAILED),
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
    /**
     * A refusal before anything was changed: the plan stays as it is and the
     * studio offers the developer to check again once they have put it right.
     */
    private function handedBack(string $name, string $reason): string
    {
        $this->progress->refused($this->session, $name, 'terminal', $reason);
        $this->studio->submitScanWork(['session' => $this->session, 'state' => 'planned', 'refused' => $this->oneLine($reason)]);
        $this->step($reason);

        return $reason;
    }

    private function stillWriting(): void
    {
        $waited = (int) (microtime(true) - $this->writingSince);

        if ($this->writing === [] || $waited < $this->stillAt) {
            return;
        }

        $this->say(sprintf('Still writing %s… %dm %02ds in.', implode(', ', array_map('basename', $this->writing)), intdiv($waited, 60), $waited % 60));
        $this->stillAt += self::STILL_WRITING_EVERY;
    }

    private function landed(): void
    {
        $this->writing = [];

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
        $this->openedInEditor(array_keys($this->arrived));
        $this->arrived = [];
    }

    /**
     * @param  PlannedFile  $file
     */
    private function contentOf(array $file): string
    {
        if (isset($this->attempted[$file['path']])) {
            return $this->attempted[$file['path']];
        }

        $path = $this->root.'/'.$file['path'];

        return ($file['action'] === 'change' || in_array($file['path'], $this->created, true)) && is_file($path) && filesize($path) <= self::LARGEST_FILE ? (string) $this->reads()->readable($file['path']) : '';
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
            ->map(fn (string $path): array => ['path' => $path, 'content' => $this->reads()->readable($path), 'for' => $paths])
            ->filter(fn (array $file): bool => is_string($file['content']))
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
            ($proposal['unneeded'] ?? false) === true && is_file($file) => ['content' => (string) file_get_contents($file)],
            $content !== '' && is_file($file) && ! in_array($path, $this->created, true) => ['error' => 'It is already on your machine, so I did not write over it.'],
            $content !== '' => ['content' => $content],
            $edits !== [] && is_file($file) => EditApplier::apply($this->attempted[$path] ?? (string) file_get_contents($file), $edits),
            default => ['error' => $note !== '' ? $note : Says::named('{name} sent nothing for it.')],
        };
        $checked = isset($next['error']) ? ['content' => '', 'error' => (string) $next['error']] : PhpCheck::of($path, (string) $next['content']);

        if ($checked['error'] !== null) {
            $this->broken[$path] = $checked['error'];
            $checked['content'] === '' || $this->attempted[$path] = $checked['content'];
            $this->step($path.': not written, because '.lcfirst($checked['error']));

            return $path;
        }

        $this->arrived[$path] = ['content' => $checked['content'], 'note' => $note];
        unset($this->broken[$path], $this->attempted[$path]);

        return $path;
    }

    /**
     * @param  list<PlannedFile>  $files
     * @return array<string, list<string>>
     */
    private function typesBefore(array $files): array
    {
        $changed = array_column(array_filter($files, fn (array $file): bool => $file['action'] === 'change'), 'path');

        return $changed === [] || ! $this->checks->canCheckTypes() ? [] : $this->timed('check', sprintf('phpstan on %d files before changing them', count($changed)), fn (): array => $this->checks->types($changed) ?? []);
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
        $this->timed('check', sprintf('pint formatting %d files', count($written)), fn (): bool => $this->checks->format($written), $number);
        $types = $step['prototype'] || ! $this->checks->canCheckTypes() ? [] : $this->timed('check', sprintf('phpstan on %d files', count($written)), fn (): array => StepChecks::added($before, $this->checks->types($written) ?? []), $number, null, fn (array $added): string => $added === [] ? 'nothing new' : count($added).' files with new findings');
        $tests = $step['role'] === self::TEST_ROLE ? $this->failingTests($written) : [];

        return [
            ...($step['prototype'] ? [] : $this->stillSample($files)),
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
        $failures = $this->timed('test', sprintf('pest on %d test %s', count($tests), count($tests) === 1 ? 'file' : 'files'), fn (): ?array => $this->tests->failures($tests), null, null, fn (?array $found): string => $found === null ? 'could not run' : (count($found) === 0 ? 'all passed' : count($found).' failing'));

        return $failures === null
            ? array_fill_keys($tests, 'the tests could not run.')
            : collect($tests)
                ->mapWithKeys(fn (string $test): array => [$test => collect($failures)->filter(fn (string $why, string $name): bool => Str::before($name, ' › ') === $test)->map(fn (string $why, string $name): string => $name.': '.Str::limit(str_replace(rtrim($this->root, '/').'/', '', $why), self::LONGEST_FAILURE))->implode("\n")])
                ->filter()
                ->all();
    }

    /**
     * @param  PlannedStep  $step
     * @param  list<string>  $paths
     */
    private function committed(int $number, array $step, array $paths): bool
    {
        return $this->timed('commit', sprintf('committing step %d: %s', $number, $step['title']), fn (): bool => $this->committedNow($number, $step, $paths), $number);
    }

    /**
     * @param  PlannedStep  $step
     * @param  list<string>  $paths
     */
    private function committedNow(int $number, array $step, array $paths): bool
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
     * @param  list<PlannedStep>  $steps
     * @return array{decision: string, note: ?string}|null
     */
    private function checkedOver(array $steps, string $builtAt): ?array
    {
        $committed = collect($this->batches)->where('state', 'committed');

        if ($committed->isEmpty()) {
            return null;
        }

        $opensAt = $this->routed($builtAt);
        $this->renderedBuilt($steps, $opensAt, self::RENDER_TRIES);
        $this->say('Every step is committed. Checking that the files compile, your tests pass and your app boots…');
        $paths = array_values(array_unique(array_column($this->git->changesSince((string) $this->base), 'path')));
        $broken = collect($paths)
            ->filter(fn (string $path): bool => is_file($this->root.'/'.$path))
            ->mapWithKeys(fn (string $path): array => [$path => (string) PhpCheck::of($path, (string) file_get_contents($this->root.'/'.$path))['error']])
            ->filter()
            ->all();
        $covering = $this->tests?->for($paths) ?? [];
        $tests = array_values(array_unique([...$covering, ...array_filter($paths, fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php'))]));
        $migrated = $this->migrated();
        $failing = $tests === [] || $this->tests === null ? null : $this->tests->failing($tests);
        $this->openedInEditor($paths);

        return $this->ask(0, $paths, $this->overall(array_values($committed->all()), $broken, $tests, $failing, $this->boots(), $migrated, $this->openAt($opensAt)), '', ['kind' => 'final', 'options' => [WorkRun::WORKS, WorkRun::BROKE, self::GUIDE]]);
    }

    private function openAt(string $path): ?string
    {
        $app = rtrim((string) config('app.url'), '/');

        return str_starts_with($path, '/') && ! str_contains($path, '{') && str_starts_with($app, 'http') ? $app.$path : null;
    }

    private function migrated(): string
    {
        return $this->timed('migrate', 'running the migrations I added', fn (): string => $this->migratedNow());
    }

    private function migratedNow(): string
    {
        $paths = array_values(array_filter($this->created, fn (string $path): bool => str_starts_with($path, 'database/migrations/') && is_file($this->root.'/'.$path)));

        if ($paths === []) {
            return '';
        }

        $risky = array_values(array_filter($paths, fn (string $path): bool => preg_match(self::DESTRUCTIVE, (string) preg_replace('/function down\(.*?\n    \}/s', '', (string) file_get_contents($this->root.'/'.$path))) === 1));

        if ($risky !== [] || ! is_file($this->root.'/artisan')) {
            return $risky === [] ? __('studio-cli::build.migrate.no_artisan') : __('studio-cli::build.migrate.risky', ['files' => implode(', ', array_map('basename', $risky))]);
        }

        $answer = $this->ask(0, $paths, __('studio-cli::build.migrate.ask', ['files' => implode(', ', array_map('basename', $paths))]), '', ['kind' => 'migrate', 'options' => [WorkRun::APPROVE, WorkRun::SKIP]]);

        if (($answer['decision'] ?? null) !== WorkRun::APPROVE || $this->halted()) {
            return __('studio-cli::build.migrate.left');
        }

        $failed = collect($paths)
            ->map(fn (string $path) => Process::path($this->root)->timeout(self::MIGRATE_SECONDS)->run([PHP_BINARY, 'artisan', 'migrate', '--path='.$path, '--no-interaction']))
            ->first(fn ($result): bool => ! $result->successful());

        return $failed === null
            ? __('studio-cli::build.migrate.ran')
            : __('studio-cli::build.migrate.failed', ['reason' => Str::limit(trim($failed->errorOutput() !== '' ? $failed->errorOutput() : $failed->output()), self::LONGEST_FAILURE)]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function openedInEditor(array $paths): void
    {
        $unopened = array_values(array_filter(array_diff($paths, $this->inEditor), fn (string $path): bool => is_file($this->root.'/'.$path)));
        $this->inEditor = [...$this->inEditor, ...$unopened];
        $unopened === [] || $this->editor?->open($unopened);
    }

    /**
     * @param  list<PlannedStep>  $steps
     * @return array{decision: string, note: ?string}|null
     */
    private function triedOut(array $steps, string $builtAt): ?array
    {
        $answer = $this->checkedOver($steps, $builtAt);
        $note = trim((string) ($answer['note'] ?? ''));

        return ($answer['decision'] ?? null) === self::GUIDE && $note !== '' && $this->fixedAfterTrying($steps, $note)
            ? $this->triedOut($steps, $builtAt)
            : $answer;
    }

    /**
     * @param  list<PlannedStep>  $steps
     */
    private function fixedAfterTrying(array $steps, string $note): bool
    {
        $this->say(sprintf('I’m changing what I built on %s for what you saw.', $this->branch));

        return collect($steps)
            ->reject(fn (array $step): bool => $step['prototype'])
            ->every(fn (array $step): bool => ! $this->halted() && $this->refixed($step, $note));
    }

    /**
     * @param  PlannedStep  $step
     */
    private function refixed(array $step, string $note, ?int $under = null): bool
    {
        if ($under !== null && $under !== $step['number']) {
            return $this->refixedUnder($under, $step, $note);
        }

        $paths = array_column($step['files'], 'path');
        $before = (array) (collect($this->batches)->firstWhere('number', $step['number']) ?? []);
        $this->written($step['number'], $step['files'], $note);

        if ($this->git->changed($paths) === []) {
            $this->record($step['number'], $paths, (string) ($before['state'] ?? 'committed'), $before['commit'] ?? null, (int) ($before['fixed'] ?? count($paths)), (int) ($before['left'] ?? 0), $before['note'] ?? null);

            return ! $this->halted();
        }

        $this->committed($step['number'], $step, $paths);

        return ! $this->halted();
    }

    /**
     * @param  list<array{rule: string, commit: ?string}>  $committed
     * @param  array<string, string>  $broken
     * @param  list<string>  $tests
     * @param  list<string>|null  $failing
     */
    private function overall(array $committed, array $broken, array $tests, ?array $failing, ?string $boot, string $migrated = '', ?string $open = null): string
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
            $migrated,
            sprintf('Try your app on %s%s, then tell me: does everything work?', $this->branch, $open === null ? '' : ' at '.$open),
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
