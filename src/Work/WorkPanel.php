<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Dashboard\DashboardSnapshot;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Fix\FixLauncher;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Action;
use ArtisanStudio\StudioCli\Terminal\Components\Alert;
use ArtisanStudio\StudioCli\Terminal\Components\Columns\Column;
use ArtisanStudio\StudioCli\Terminal\Components\Component;
use ArtisanStudio\StudioCli\Terminal\Components\Section;
use ArtisanStudio\StudioCli\Terminal\Components\Table;
use ArtisanStudio\StudioCli\Terminal\Components\Text;
use ArtisanStudio\StudioCli\Terminal\Settings;
use ArtisanStudio\StudioCli\Terminal\Spinner;
use Closure;

/**
 * A job worked through with the studio, as the Insights tab tells it: the
 * card that asks for the press of ⏎, what is being done as it happens, each
 * kind of problem as it lands, and the question asked after each one.
 */
final class WorkPanel
{
    /**
     * How a tool's row reads while a job is worked through on it.
     */
    public const string WORKING = 'work-running';

    public const string NEEDS_YOU = 'work-needs-you';

    public const string DONE = 'work-done';

    /**
     * Where the words for fixing a job are, in what is said.
     */
    public const string WORK = 'work';

    /**
     * Where the words are when a build differs from fixing a job.
     */
    public const string BUILD = 'build';

    /**
     * @var array<string, array{label: string, hint: string}>
     */
    private const array PROTOTYPE_ANSWERS = [
        'approve' => ['label' => 'Sign it off and build the real thing', 'hint' => 'Anything to tell {name} before the build starts? ⏎ to send, or leave it empty.'],
        'retry' => ['label' => 'Change the prototype', 'hint' => 'What should {name} change? The prototype is rewritten and you are asked again. ⏎ to send.'],
    ];

    /**
     * @var array<string, array{label: string, hint: string}>
     */
    private const array YOURS_ANSWERS = [
        'approve' => ['label' => 'I fixed it: check it and commit it', 'hint' => 'Why did you change it? Your words become the commit message. ⏎ to send.'],
        'leave' => ['label' => 'Leave it as it is', 'hint' => 'Anything to tell {name}? ⏎ to send, or leave it empty.'],
    ];

    /**
     * @var array<string, array{label: string, hint: string}>
     */
    private const array ANSWERS = [
        'approve' => ['label' => 'It works, commit it and go on', 'hint' => 'Did you fix anything? Say what and why, and {name} commits it with your note. ⏎ to send, or leave it empty.'],
        'always' => ['label' => 'It works, and commit the rest without asking', 'hint' => 'Did you fix anything? Say what and why, and {name} commits it with your note. ⏎ to send, or leave it empty.'],
        'retry' => ['label' => 'Try again', 'hint' => 'What should {name} do differently? The work carries on from your files as they are now. ⏎ to send, or leave it empty.'],
        'skip' => ['label' => 'Keep it and move on', 'hint' => 'Anything to tell {name}? ⏎ to send, or leave it empty.'],
        'leave' => ['label' => 'Skip this and move on', 'hint' => 'Anything to tell {name}? ⏎ to send, or leave it empty.'],
        'talk' => ['label' => 'Talk it through in the app', 'hint' => 'Anything to tell {name}? ⏎ to send, or leave it empty.'],
        'stop' => ['label' => 'Stop here', 'hint' => 'Anything to tell {name}? ⏎ to send, or leave it empty.'],
        'works' => ['label' => 'Everything works', 'hint' => 'Anything to tell {name}? ⏎ to send, or leave it empty.'],
        'broke' => ['label' => 'Something broke', 'hint' => 'What broke, and where? {name} looks at it with you in the app. ⏎ to send.'],
    ];

    private const int ROOMY = 100;

    private function __construct(private readonly bool $builds) {}

    public static function insights(): self
    {
        return new self(false);
    }

    /**
     * An idea being built with the studio, as the Build tab tells it.
     */
    public static function builds(): self
    {
        return new self(true);
    }

    /**
     * The work asked of this panel: builds on the Build tab, every other job
     * on the Insights tab.
     *
     * @return list<array{id: int, job: string, name: string, mode: string, files: int, state: string}>
     */
    public function asks(DashboardSnapshot $data): array
    {
        return array_values(array_filter($data->workAsked, fn (array $ask): bool => ($ask['job'] === BuildRun::JOB) === $this->builds));
    }

    /**
     * Whether work has been asked for, is under way, or has a run to show.
     */
    public function isOnShow(): bool
    {
        $data = app(SnapshotSource::class)->snapshot();

        return $this->asks($data) !== [] || $this->shown($data) !== null;
    }

    public function canStart(DashboardSnapshot $data): bool
    {
        return app(WorkLauncher::class)->canStart($this->asks($data));
    }

    public function start(DashboardSnapshot $data): string
    {
        return app(WorkLauncher::class)->start($this->asks($data));
    }

    public function isAsking(): bool
    {
        $run = app(WorkProgress::class)->read();

        return $run !== null && $this->isMine($run) && $run['phase'] === WorkProgress::STOPPED && $run['question'] !== null && $run['answer'] === null && app(WorkProgress::class)->isAlive();
    }

    public function label(DashboardSnapshot $data): ?string
    {
        return match (true) {
            $this->isAsking() => Says::named('Answer {name}'),
            $this->canStart($data) => Says::named('Work with {name}'),
            default => null,
        };
    }

    /**
     * @return list<Component>
     */
    public function speech(): array
    {
        return [
            Alert::make(fn (DashboardSnapshot $data): string => $this->says($data)['title'])
                ->colour(fn (DashboardSnapshot $data): string => $this->says($data)['colour'])
                ->description(fn (DashboardSnapshot $data): string => $this->says($data)['line'])
                ->descriptionColour('soft'),
            Text::make(fn (DashboardSnapshot $data): string => $this->below($data))
                ->colour('soft')
                ->wrap(),
        ];
    }

    /**
     * Whether the work has a line of its own to say right now, so no other
     * card speaks over it.
     */
    public function speaks(DashboardSnapshot $data): bool
    {
        return $this->says($data)['title'] !== '';
    }

    /**
     * The work on its own, for when no row of the fixes table carries it.
     *
     * @param  (Closure(DashboardSnapshot): bool)|null  $carried
     * @return list<Component>
     */
    public function progress(?Closure $carried = null): array
    {
        return $this->detail(fn (DashboardSnapshot $data): bool => $this->shown($data) !== null && ($carried === null || ! $carried($data)), withBack: false);
    }

    /**
     * Each kind of problem being worked through, as it lands, and how long
     * each took: what ⏎ opens on the work's row of the fixes table.
     *
     * @param  (Closure(DashboardSnapshot): bool)|null  $visible
     * @return list<Component>
     */
    public function detail(?Closure $visible = null, bool $withBack = true): array
    {
        return array_filter([
            Section::make(fn (DashboardSnapshot $data): string => $this->title($data))
                ->visible(fn (DashboardSnapshot $data): bool => $visible === null ? $this->shown($data) !== null : $visible($data))
                ->aside(fn (DashboardSnapshot $data): string => $this->aside($data))
                ->asideColour('cyan')
                ->components([
                    Table::make(fn (DashboardSnapshot $data): array => $this->rows($data))
                        ->emptyState('')
                        ->columns([
                            Column::make('batch')->label($this->builds ? 'Step' : 'Problem')->bold(),
                            Column::make('files')->label('Files')->width(10)->colour('soft'),
                            Column::make('result')
                                ->label('Result')
                                ->width(fn (int $table): int => $table >= self::ROOMY ? 34 : 22)
                                ->colour(fn (array $row): string => $this->colourOf($row))
                                ->formatStateUsing(fn (mixed $result, array $row): string => (in_array($row['state'], ['running', 'checking'], true) ? $this->spinner().' ' : '').$result),
                            Column::make('commit')->label('Commit')->width(9)->colour('soft'),
                            Column::make('elapsed')
                                ->label('Time')
                                ->width(8)
                                ->colour(fn (array $row): string => in_array($row['state'], ['running', 'checking'], true) ? 'cyan' : 'soft')
                                ->formatStateUsing(fn (mixed $elapsed): string => $elapsed === null ? '—' : self::duration((int) $elapsed)),
                        ]),
                ]),
            $withBack ? Text::make('Esc goes back to the fixes')->colour('dim') : null,
        ]);
    }

    /**
     * The checking tools of the kinds worked on in this run.
     *
     * @return list<string>
     */
    public function tools(DashboardSnapshot $data): array
    {
        return array_values(array_unique(collect($this->shown($data)['batches'] ?? [])->flatMap(fn (array $batch): array => array_values(array_filter((array) ($batch['tools'] ?? []), is_string(...))))->all()));
    }

    /**
     * Whether the fixes table can be walked row by row: there is a run to
     * show, and ⏎ is not wanted to start something or to answer a question.
     */
    public function isBrowsable(DashboardSnapshot $data): bool
    {
        return $this->shown($data) !== null && ! $this->canStart($data) && ! $this->isAsking();
    }

    /**
     * What the work's row of the fixes table says while it is worked through.
     *
     * @return array{note: string, state: string, elapsed: ?int}|null
     */
    public function status(DashboardSnapshot $data): ?array
    {
        $run = $this->shown($data);

        if ($run === null) {
            return null;
        }

        $batches = collect($run['batches']);
        $planned = max(1, $run['planned'], $batches->count());
        $done = $batches->whereIn('state', ['committed', 'skipped', 'failed'])->count();
        $working = ! in_array($run['phase'], [WorkProgress::FINISHED, WorkProgress::REFUSED], true) && ! app(WorkProgress::class)->hasStopped();
        $end = match (true) {
            $run['phase'] === WorkProgress::STOPPED => (float) ($run['question']['asked_at'] ?? microtime(true)),
            $working => microtime(true),
            default => $batches->max('finished') ?? $run['finished'] ?? microtime(true),
        };

        return [
            'note' => match (true) {
                $run['phase'] === WorkProgress::STOPPED => Says::named('{name} needs you'),
                $working => $this->spinner().Says::named(' {name} · ').min($done + 1, $planned).' of '.$planned,
                $run['phase'] === WorkProgress::REFUSED => Says::named('{name} could not start'),
                default => Says::named('{name} · ').$batches->where('state', 'committed')->count().' of '.$planned.' committed',
            },
            'state' => match (true) {
                $run['phase'] === WorkProgress::STOPPED, $run['phase'] === WorkProgress::REFUSED => self::NEEDS_YOU,
                $working => self::WORKING,
                default => self::DONE,
            },
            'elapsed' => (int) (max(0.0, $end - $run['at'] - (float) $batches->sum(fn (array $batch): float => (float) ($batch['waited'] ?? 0))) * 1000),
        ];
    }

    /**
     * What ⏎ opens when the work has stopped to ask: one choice each, and a
     * note if the developer wants to give one.
     *
     * @return list<Settings>
     */
    public function questionPanel(): array
    {
        if (! $this->isAsking()) {
            return [];
        }

        return [Settings::make(Says::named('{name} needs you'))
            ->state(fn (): ?array => app(WorkProgress::class)->read())
            ->components([
                Text::make(fn (?array $run): string => match ($run['question']['kind'] ?? null) {
                    'review' => self::readyToCheck($run),
                    'final' => self::committedSoFar($run),
                    default => (string) ($run['question']['reason'] ?? ''),
                })->colour('amber')->wrap(),
                Text::make(fn (?array $run): string => in_array($run['question']['kind'] ?? null, ['review', 'final'], true) ? '' : implode(', ', (array) ($run['question']['files'] ?? [])))->colour('soft')->wrap(),
            ])
            ->actions(array_map(
                fn (string $decision): Action => $this->answerAction($decision, ...array_values(match ($this->asked()['kind'] ?? null) {
                    'prototype' => self::PROTOTYPE_ANSWERS[$decision] ?? null,
                    'yours' => self::YOURS_ANSWERS[$decision] ?? null,
                    default => null,
                } ?? self::ANSWERS[$decision])),
                array_values(array_filter((array) ($this->asked()['options'] ?? []), fn (mixed $option): bool => is_string($option) && isset(self::ANSWERS[$option]))),
            ))];
    }

    /**
     * @return array<string, mixed>
     */
    private function asked(): array
    {
        return (array) (app(WorkProgress::class)->read()['question'] ?? []);
    }

    /**
     * Gives the work the answer here and tells the app, so the conversation there
     * shows it too.
     */
    public function answer(string $decision, ?string $note): string
    {
        $progress = app(WorkProgress::class);
        $run = $progress->read();
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($run === null) {
            return 'There is nothing to answer.';
        }

        $progress->answered($decision, $note);
        app(Studio::class)->submitScanWork(['session' => $run['session'], 'answer' => ['decision' => $decision, 'note' => $note]]);

        return Says::named('Told {name}.');
    }

    private function answerAction(string $decision, string $label, string $hint): Action
    {
        return Action::make($decision)
            ->label($label)
            ->asks(Says::named($hint), optional: true)
            ->closes()
            ->action(fn (string $note): string => $this->answer($decision, $note));
    }

    /**
     * @return array{title: string, line: string, colour: string}
     */
    private function says(DashboardSnapshot $data): array
    {
        $key = $this->moment($data);

        return match (true) {
            $key === null => ['title' => '', 'line' => '', 'colour' => 'sky'],
            $this->builds && app(Says::class)->has(self::BUILD, $key) => app(Says::class)->line(self::BUILD, $key),
            default => app(Says::class)->line(self::WORK, $key),
        };
    }

    /**
     * What changes from run to run, shown under the fixed line: the job and
     * its files, the branch, or why the work stopped.
     */
    private function below(DashboardSnapshot $data): string
    {
        $run = $this->shown($data);
        $ask = $this->asks($data)[0] ?? null;
        $name = (string) ($run['name'] ?? ($ask['name'] ?? ''));
        $files = (int) ($ask['files'] ?? 0);
        $where = $name.(($run['branch'] ?? '') === '' ? '' : ' on '.$run['branch']);

        return match ($this->moment($data)) {
            null, 'apply' => '',
            'asked', 'tests', 'build' => $name.' · '.number_format($files).' '.($files === 1 ? 'file' : 'files'),
            'review' => self::readyToCheck($run),
            'final' => self::committedSoFar($run),
            'stopped', 'refused' => (string) ($run['question']['reason'] ?? $run['reason'] ?? ''),
            'working' => (string) ($run['says'] ?? $where),
            'done', 'halted' => trim($where."\n".self::doneOf($run)),
            default => $where,
        };
    }

    /**
     * @param  array<string, mixed>|null  $run
     */
    private static function readyToCheck(?array $run): string
    {
        $question = (array) ($run['question'] ?? []);
        $changed = count((array) ($question['files'] ?? []));
        $all = count((array) (collect($run['batches'] ?? [])->firstWhere('number', $question['batch'] ?? 0)['files'] ?? []));

        return sprintf('%s · %d of %d %s changed and checked, on %s.', (string) ($question['rule'] ?? ''), $changed, max($all, $changed), max($all, $changed) === 1 ? 'file' : 'files', (string) ($run['branch'] ?? ''));
    }

    /**
     * @param  array<string, mixed>|null  $run
     */
    private static function committedSoFar(?array $run): string
    {
        $batches = collect($run['batches'] ?? []);
        $committed = $batches->where('state', 'committed')->count();
        $left = $batches->whereIn('state', ['skipped', 'failed'])->count();

        return sprintf('%d of %d %s committed on %s.', $committed, $batches->count(), ($run['job'] ?? null) === BuildRun::JOB ? ($batches->count() === 1 ? 'step' : 'steps') : ($batches->count() === 1 ? 'kind' : 'kinds'), (string) ($run['branch'] ?? '')).($left === 0 ? '' : sprintf(' %d left for us to work through in the app.', $left));
    }

    /**
     * What a finished run did, in the words of its job.
     *
     * @param  array<string, mixed>|null  $run
     */
    private static function doneOf(?array $run): string
    {
        $batches = array_values((array) ($run['batches'] ?? []));

        return match ($run['job'] ?? null) {
            WorkRun::TESTS => self::testsOf($batches),
            BuildRun::JOB => self::builtOf($batches),
            default => self::summaryOf($batches),
        };
    }

    /**
     * Each step built, with its commit, and each that could not be.
     *
     * @param  list<array<string, mixed>>  $batches
     */
    private static function builtOf(array $batches): string
    {
        $built = collect($batches)->where('state', 'committed')->map(fn (array $batch): string => sprintf('%s (%s)', $batch['rule'] ?? '', substr((string) $batch['commit'], 0, 7)));
        $skipped = collect($batches)->whereIn('state', ['skipped', 'failed'])->pluck('rule');

        return trim(($built->isEmpty() ? '' : 'Built: '.$built->implode('; ').'.')."\n".($skipped->isEmpty() ? '' : 'Not built: '.$skipped->implode('; ').'.'));
    }

    /**
     * Each kind fixed, with what landed, and each skipped.
     *
     * @param  list<array<string, mixed>>  $batches
     */
    private static function summaryOf(array $batches): string
    {
        $fixed = collect($batches)->where('state', 'committed')->map(fn (array $batch): string => sprintf('%s: %d fixed (%s)', $batch['rule'] ?? '', (int) $batch['fixed'], substr((string) $batch['commit'], 0, 7)));
        $skipped = collect($batches)->whereIn('state', ['skipped', 'failed'])->pluck('rule');

        return trim(($fixed->isEmpty() ? '' : 'Fixed: '.$fixed->implode('; ').'.')."\n".($skipped->isEmpty() ? '' : 'Skipped: '.$skipped->implode('; ').'.'));
    }

    /**
     * @param  list<array<string, mixed>>  $batches
     */
    private static function testsOf(array $batches): string
    {
        $batch = collect($batches)->first() ?? [];
        $written = (int) ($batch['fixed'] ?? 0);
        $without = (int) ($batch['left'] ?? 0);

        return trim(($written === 0 ? 'No test written.' : sprintf('%d %s written and committed (%s); Failing tests runs them last.', $written, $written === 1 ? 'test' : 'tests', substr((string) ($batch['commit'] ?? ''), 0, 7))).($without === 0 ? '' : sprintf(' %d %s without one: why is in the app.', $without, $without === 1 ? 'file' : 'files')));
    }

    private function moment(DashboardSnapshot $data): ?string
    {
        $run = $this->shown($data);
        $ask = $this->asks($data)[0] ?? null;

        return match (true) {
            $run === null && $ask === null => null,
            $run === null => match (true) {
                $ask['mode'] === 'app' => 'apply',
                ($ask['job'] ?? null) === WorkRun::TESTS => 'tests',
                $ask['job'] === BuildRun::JOB => 'build',
                default => 'asked',
            },
            $run['phase'] === WorkProgress::REFUSED => 'refused',
            app(WorkProgress::class)->hasStopped() => 'died',
            $run['phase'] === WorkProgress::STOPPED => match ($run['question']['kind'] ?? 'stuck') {
                'review' => 'review',
                'final' => 'final',
                'prototype' => 'prototype',
                'yours' => 'yours',
                default => 'stopped',
            },
            $run['phase'] === WorkProgress::FINISHED => $run['reason'] === null ? 'done' : 'halted',
            default => 'working',
        };
    }

    /**
     * The run this panel shows: a finished one only until the developer asks
     * for something new, or a scan has run since.
     *
     * @return array{phase: string, session: int, job: string, name: string, mode: string, branch: string, files: list<string>, planned: int, at: float, finished: ?float, pid: ?int, batches: list<array<string, mixed>>, question: ?array<string, mixed>, answer: ?array<string, mixed>, reason: ?string}|null
     */
    private function shown(DashboardSnapshot $data): ?array
    {
        $run = app(WorkProgress::class)->read();

        if ($run === null || ! $this->isMine($run)) {
            return null;
        }

        $over = in_array($run['phase'], [WorkProgress::FINISHED, WorkProgress::REFUSED], true);
        $dropped = ! $over && app(WorkProgress::class)->hasStopped() && ! collect($data->workAsked)->contains(fn (array $ask): bool => $ask['id'] === $run['session']);
        $newer = collect($data->workAsked)->contains(fn (array $ask): bool => $ask['id'] !== $run['session']) || app(FixLauncher::class)->canStart($data->fixesAsked);

        return $dropped || ($over && ($newer || app(ToolStatus::class)->scannedSince($run['finished'] ?? $run['at']))) ? null : $run;
    }

    /**
     * Whether a run belongs on this panel's tab.
     *
     * @param  array<string, mixed>  $run
     */
    private function isMine(array $run): bool
    {
        return (($run['job'] ?? null) === BuildRun::JOB) === $this->builds;
    }

    private function title(DashboardSnapshot $data): string
    {
        $run = $this->shown($data);

        return $run === null ? '' : match (true) {
            $run['job'] === BuildRun::JOB => 'Building ',
            $run['mode'] === 'app' => 'What you agreed in ',
            default => 'Working through ',
        }.$run['name'].($run['branch'] === '' ? '' : ' on '.$run['branch']);
    }

    private function aside(DashboardSnapshot $data): string
    {
        $run = $this->shown($data);
        $batches = collect($run['batches'] ?? []);

        if ($run !== null && $run['mode'] === 'app') {
            $changes = count($run['files']);

            return number_format($changes).' '.($changes === 1 ? 'change' : 'changes').' you agreed · '.match ($run['phase']) {
                WorkProgress::FINISHED => 'applied',
                WorkProgress::STOPPED => 'waiting for you',
                default => 'applying…',
            };
        }

        $planned = max(1, $run['planned'] ?? 0);
        $elapsed = collect($this->rows($data))->pluck('elapsed')->filter(fn (?int $milliseconds): bool => $milliseconds !== null);

        $building = ($run['job'] ?? null) === BuildRun::JOB;

        return $run === null ? '' : number_format((int) $batches->sum('fixed')).($building ? ' written · ' : ' fixed · ').number_format($batches->where('state', 'committed')->count()).' of '.number_format($planned).' '.($building ? ($planned === 1 ? 'step' : 'steps') : ($planned === 1 ? 'problem' : 'problems')).($elapsed->isEmpty() ? '' : ' · '.self::duration((int) $elapsed->sum()));
    }

    /**
     * @return list<array{batch: string, files: string, state: string, result: string, commit: string, elapsed: ?int}>
     */
    private function rows(DashboardSnapshot $data): array
    {
        $run = $this->shown($data);
        $asked = $run !== null && $run['phase'] === WorkProgress::STOPPED ? (int) ($run['question']['batch'] ?? 0) : 0;
        $gone = app(WorkProgress::class)->hasStopped();

        return array_values(collect($run['batches'] ?? [])->map(fn (array $batch): array => [
            'elapsed' => ($gone && in_array($batch['state'], ['running', 'checking'], true)) || $batch['state'] === 'queued' ? null : self::workedOn($batch, $asked === $batch['number'] ? (float) ($run['question']['asked_at'] ?? microtime(true)) : null),
            'batch' => (string) ($batch['rule'] ?? 'Problem '.$batch['number']),
            'files' => number_format(count($batch['files'])).' '.(count($batch['files']) === 1 ? 'file' : 'files'),
            'state' => (string) $batch['state'],
            'result' => match (true) {
                $asked === $batch['number'] => 'waiting for you',
                $batch['state'] === 'queued' => 'next',
                $batch['state'] === 'running' && (int) ($batch['done'] ?? 0) > 0 => 'working… '.(int) $batch['done'].' of '.count($batch['files']).' files',
                $batch['state'] === 'running' => 'working…',
                $batch['state'] === 'checking' => 'checking…',
                $batch['state'] === 'ready' => 'checked',
                $batch['state'] === 'committed' && ($run['job'] ?? null) === WorkRun::TESTS => $batch['fixed'].' '.($batch['fixed'] === 1 ? 'test' : 'tests').' written',
                $batch['state'] === 'committed' && ($run['job'] ?? null) === BuildRun::JOB => $batch['fixed'].' '.($batch['fixed'] === 1 ? 'file' : 'files').' written'.($batch['left'] === 0 ? '' : ' · '.$batch['left'].' not'),
                $batch['state'] === 'committed' => $batch['fixed'].' '.($batch['fixed'] === 1 ? 'finding' : 'findings').' fixed'.($batch['left'] === 0 ? '' : ' · '.$batch['left'].' left'),
                $batch['state'] === 'skipped' => 'skipped',
                default => 'stopped here',
            },
            'commit' => substr((string) ($batch['commit'] ?? ''), 0, 7) ?: '—',
        ])->all());
    }

    /**
     * How long the work has spent on a kind: never the time it waited for the
     * developer.
     *
     * @param  array<string, mixed>  $batch
     */
    private static function workedOn(array $batch, ?float $askedAt): ?int
    {
        if (! isset($batch['started'])) {
            return null;
        }

        $until = match (true) {
            $askedAt !== null => $askedAt,
            isset($batch['finished']) => (float) $batch['finished'],
            default => microtime(true),
        };
        $spent = isset($batch['worked']) && ! in_array($batch['state'], ['running', 'checking'], true) ? (float) $batch['worked'] : $until - (float) $batch['started'] - (float) ($batch['waited'] ?? 0);

        return (int) (max(0.0, $spent) * 1000);
    }

    private function spinner(): string
    {
        return Spinner::frame();
    }

    private static function duration(int $milliseconds): string
    {
        $seconds = intdiv(max(0, $milliseconds), 1000);

        return $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';
    }

    /**
     * @param  array{state: string}  $row
     */
    private function colourOf(array $row): string
    {
        return match ($row['state']) {
            'running', 'checking', 'ready' => 'cyan',
            'committed' => 'green',
            'queued' => 'soft',
            default => 'amber',
        };
    }
}
