<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use ArtisanStudio\StudioCli\Saloon\Requests\FixScanFilesRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ListPresenceStatesRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ListProjectsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\PingRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\PlanNodePatchesRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ReadToolOutputRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ReportScanToolProgressRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\RunScanCheckRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SayGoodbyeRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowConventionDetectorsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanDetectorsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanFixesRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanToolsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanWorkRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSnapshotRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSpeechRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitBlueprintRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitConventionFactsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFixReportRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFlagsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanToolResultsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanWorkRequest;
use ArtisanStudio\StudioCli\Saloon\StudioConnector;
use ArtisanStudio\StudioCli\Scan\Tools\DefinedTool;
use Closure;
use RuntimeException;
use Saloon\Http\Request;
use Throwable;

class Studio
{
    public const string CONNECTED = 'connected';

    public const string UNLINKED = 'unlinked';

    public const string REFUSED = 'refused';

    public const string FORBIDDEN = 'forbidden';

    public const string MISSING = 'missing';

    public const string UNREACHABLE = 'unreachable';

    public const string UNKNOWN = 'unknown';

    private ?string $token = null;

    private ?EventStream $stream = null;

    private string $connection = self::UNKNOWN;

    private bool $hasConnected = false;

    public function isLinked(): bool
    {
        return filled($this->token()) && filled($this->url());
    }

    public function connection(): string
    {
        return $this->isLinked() ? $this->connection : self::UNLINKED;
    }

    public function hasConnected(): bool
    {
        return $this->hasConnected;
    }

    private function answered(string $connection): void
    {
        $this->connection = $connection;
        $this->hasConnected = $this->hasConnected || $connection === self::CONNECTED;
    }

    public function withToken(string $token): self
    {
        $clone = clone $this;
        $clone->token = $token;

        return $clone;
    }

    /** @return list<array{slug: string, name: string, repo: string|null}> */
    public function projects(): array
    {
        try {
            $response = $this->connector()->send(new ListProjectsRequest);
        } catch (Throwable $unreachable) {
            $this->answered(self::UNREACHABLE);

            throw $unreachable;
        }

        $this->answered(match (true) {
            in_array($response->status(), [401, 403], true) => self::REFUSED,
            $response->successful() => self::CONNECTED,
            default => self::UNREACHABLE,
        });

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('The studio does not recognise that token.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException(
                'Nothing at '.$this->url().'/api/v1/projects — is that the right studio?',
            );
        }

        if ($response->failed()) {
            throw new RuntimeException('The studio answered with '.$response->status().'.');
        }

        return $response->json('projects', []);
    }

    /**
     * @return array<mixed>|null
     */
    public function snapshot(): ?array
    {
        return $this->fetch(new ShowSnapshotRequest($this->project()));
    }

    /**
     * @param  array{commit: ?string, models: array<int, array<string, mixed>>}  $blueprint
     * @return array<mixed>|null
     */
    public function submitBlueprint(array $blueprint): ?array
    {
        return $this->fetch(new SubmitBlueprintRequest($this->project(), $blueprint));
    }

    /**
     * @return array{checks: array<string, array<string, mixed>>}|null
     */
    public function conventionDetectors(): ?array
    {
        $detectors = $this->quietly(new ShowConventionDetectorsRequest($this->project()));

        return is_array($detectors['checks'] ?? null) ? $detectors : null;
    }

    /**
     * @return array{checks: array<string, array<string, mixed>>}|null
     */
    public function scanDetectors(): ?array
    {
        $detectors = $this->quietly(new ShowScanDetectorsRequest($this->project()));

        return is_array($detectors['checks'] ?? null) ? $detectors : null;
    }

    /**
     * @return list<string>|null
     */
    public function scanTools(): ?array
    {
        $answer = $this->quietly(new ShowScanToolsRequest($this->project()));
        is_array($answer['definitions'] ?? null) && DefinedTool::keep(base_path(), $answer['definitions']);

        return is_array($answer['tools'] ?? null) ? array_values(array_filter($answer['tools'], is_string(...))) : null;
    }

    /**
     * One round of a security check run in the studio: what it needs next,
     * or what it found.
     *
     * @param  array<string, mixed>  $sent
     * @return array<mixed>|null
     */
    public function runScanCheck(string $check, array $sent): ?array
    {
        return $this->quietly(new RunScanCheckRequest($this->project(), $check, $sent));
    }

    /**
     * Who speaks in the terminal, and what they say on each tab.
     *
     * @return array<mixed>|null
     */
    public function speech(): ?array
    {
        $answer = $this->isLinked() ? $this->quietly(new ShowSpeechRequest) : null;

        return is_array($answer['says'] ?? null) ? $answer : null;
    }

    /**
     * What a tool the studio describes printed, read by the studio into
     * findings, a summary and what it could not read.
     *
     * @param  array<string, mixed>  $read
     * @return array<mixed>|null
     */
    public function readToolOutput(string $tool, array $read): ?array
    {
        return $this->quietly(new ReadToolOutputRequest($this->project(), $tool, $read));
    }

    /**
     * The rules the developer asked the studio to fix, by key. Only keys: what fixing
     * one runs is decided on this machine.
     *
     * @return list<string>|null
     */
    public function scanFixes(): ?array
    {
        $answer = $this->quietly(new ShowScanFixesRequest($this->project()));
        is_string($answer['author'] ?? null) && config(['studio-cli.author' => $answer['author']]);
        is_array($answer['rector'] ?? null) && config(['studio-cli.rector' => $answer['rector']]);
        is_array($answer['added'] ?? null) && config(['studio-cli.added' => $answer['added']]);

        return is_array($answer['fixes'] ?? null) ? array_values(array_filter($answer['fixes'], is_string(...))) : null;
    }

    /**
     * One batch of flagged files through the fixers in the studio: the
     * changed files, and anything the fixers need first.
     *
     * @param  array<string, mixed>  $batch
     * @return array<mixed>|null
     */
    public function fixScanFiles(array $batch): ?array
    {
        return $this->quietly(new FixScanFilesRequest($this->project(), $batch));
    }

    /**
     * Which npm packages to patch and how far, planned by the studio from the
     * audit's advisories.
     *
     * @param  list<array{module_name: string, patched_versions: string, findings: list<array{version: string}>}>  $advisories
     * @param  array{dependencies?: array<string, string>, devDependencies?: array<string, string>}  $manifest
     * @return array<mixed>|null
     */
    public function planNodePatches(array $advisories, array $manifest): ?array
    {
        return $this->quietly(new PlanNodePatchesRequest($this->project(), $advisories, $manifest));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<mixed>|null
     */
    public function submitScanFixReport(array $report): ?array
    {
        return $this->quietly(new SubmitScanFixReportRequest($this->project(), $report));
    }

    /**
     * The jobs the developer asked the studio to work through, the changes they
     * agreed in the app, and the places to check again.
     *
     * @return array{sessions: list<array<string, mixed>>, rechecks: list<array{id: int, tool: string, rule: string, path: string, line: ?int}>, sends: list<array{id: int, branch: string, base: string}>}|null
     */
    public function scanWork(): ?array
    {
        $answer = $this->quietly(new ShowScanWorkRequest($this->project()));
        $work = $answer['work'] ?? null;

        return is_array($work) ? [
            'sessions' => array_values(array_filter((array) ($work['sessions'] ?? []), is_array(...))),
            'rechecks' => array_values(array_filter((array) ($work['rechecks'] ?? []), is_array(...))),
            'sends' => array_values(array_filter((array) ($work['sends'] ?? []), is_array(...))),
        ] : null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<mixed>|null
     */
    public function submitScanWork(array $report): ?array
    {
        return $this->quietly(new SubmitScanWorkRequest($this->project(), $report));
    }

    /**
     * Tells the studio where a tool is, so the app can follow each rule as it
     * installs, runs and finishes. Nothing waits on the answer.
     */
    public function reportToolProgress(string $tool, string $state, ?string $reason = null): void
    {
        $this->quietly(new ReportScanToolProgressRequest($this->project(), $tool, $state, $reason === null ? null : mb_substr($reason, 0, 200)));
    }

    /**
     * @param  array<string, mixed>  $results
     * @return array<mixed>|null
     */
    public function submitScanToolResults(array $results): ?array
    {
        return $this->quietly(new SubmitScanToolResultsRequest($this->project(), $results));
    }

    /**
     * @param  array<string, mixed>  $flags
     * @return array<mixed>|null
     */
    public function submitScanFlags(array $flags): ?array
    {
        return $this->quietly(new SubmitScanFlagsRequest($this->project(), $flags));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<mixed>|null
     */
    public function submitConventionFacts(array $facts): ?array
    {
        return $this->quietly(new SubmitConventionFactsRequest($this->project(), $facts));
    }

    /**
     * @return array<mixed>|null
     */
    private function quietly(Request $request): ?array
    {
        $response = rescue(fn () => $this->connector()->send($request), report: false);

        return $response !== null && $response->successful() ? $response->json() : null;
    }

    public function ping(?string $repository, bool $leaving = false): void
    {
        if ($repository === null || blank($this->url())) {
            return;
        }

        $request = new PingRequest($repository, $leaving);
        $request->config()->merge(['timeout' => 2, 'connect_timeout' => 1]);

        rescue(fn () => $this->connector()->send($request), report: false);
    }

    public function sayGoodbye(): void
    {
        if (! $this->isLinked()) {
            return;
        }

        $request = new SayGoodbyeRequest($this->project());
        $request->config()->merge(['timeout' => 2, 'connect_timeout' => 1]);

        rescue(fn () => $this->connector()->send($request), report: false);
    }

    /**
     * @return array<mixed>|null
     */
    private function fetch(Request $request): ?array
    {
        try {
            $response = $this->connector()->send($request);
        } catch (Throwable) {
            $this->answered(self::UNREACHABLE);

            return null;
        }

        $this->answered(match ($response->status()) {
            401 => self::REFUSED,
            403 => self::FORBIDDEN,
            404 => self::MISSING,
            default => $response->successful() ? self::CONNECTED : self::UNREACHABLE,
        });

        return $response->successful() ? $response->json() : null;
    }

    /** @param  Closure(array<string, mixed>): void  $onEvent */
    public function stream(Closure $onEvent): void
    {
        $this->stream ??= new EventStream($this->streamUrl(), $this->token());

        $this->stream->read($onEvent);
    }

    /**
     * @return list<array{state_key: string, desktop: array{url: string, updated: int|null}|null}>
     */
    public function presenceStates(): array
    {
        try {
            $response = $this->connector()->send(new ListPresenceStatesRequest);
        } catch (Throwable) {
            return [];
        }

        return $response->successful()
            ? array_values(array_map($this->presenceState(...), array_filter((array) $response->json('states', []), is_array(...))))
            : [];
    }

    /**
     * @param  array<mixed>  $state
     * @return array{state_key: string, desktop: array{url: string, updated: int|null}|null}
     */
    private function presenceState(array $state): array
    {
        $desktop = $state['desktop'] ?? null;

        return [
            'state_key' => (string) ($state['state_key'] ?? ''),
            'desktop' => is_array($desktop) && is_string($desktop['url'] ?? null)
                ? ['url' => $desktop['url'], 'updated' => is_numeric($desktop['updated'] ?? null) ? (int) $desktop['updated'] : null]
                : null,
        ];
    }

    public function listener(): EventStream
    {
        return new EventStream($this->streamUrl(), $this->token());
    }

    private function streamUrl(): string
    {
        return rtrim($this->url(), '/').'/api/v1/projects/'.$this->project().'/stream';
    }

    private function connector(): StudioConnector
    {
        return new StudioConnector($this->url(), $this->token());
    }

    public function url(): string
    {
        return (string) config('studio-cli.url');
    }

    private function token(): string
    {
        return $this->token ?? (string) config('studio-cli.token');
    }

    public function project(): string
    {
        return (string) config('studio-cli.project', '');
    }
}
