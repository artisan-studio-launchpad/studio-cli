<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\ToolsCommand;
use ArtisanStudio\StudioCli\Saloon\Requests\PingRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ReportScanToolProgressRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowScanToolsRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ShowSnapshotRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanToolResultsRequest;
use ArtisanStudio\StudioCli\Scan\ToolStatus;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| The checking tools, one rule at a time
|--------------------------------------------------------------------------
|
| Each rule's result goes to the studio the moment its row is done, so the
| score and Insights move as the scan goes, rather than all at once at the end.
|
*/

beforeEach(function (): void {
    @unlink(app(ToolStatus::class)->path());
});

afterEach(function (): void {
    @unlink(app(ToolStatus::class)->path());
});

it('sends each rule to the studio as soon as it is checked, then everything together at the end', function (): void {
    Saloon::fake([
        ShowScanToolsRequest::class => MockResponse::make(['tools' => ['pint', 'rector', 'tests']]),
        ReportScanToolProgressRequest::class => MockResponse::make(['progress' => []], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);

    Artisan::call('studio:tools', ['--plain' => true]);

    $sent = collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): PendingRequest => $response->getPendingRequest())
        ->filter(fn (PendingRequest $request): bool => $request->getRequest() instanceof SubmitScanToolResultsRequest)
        ->map(fn (PendingRequest $request): array => (array) $request->body()?->all())
        ->values();

    $partials = $sent->filter(fn (array $body): bool => ($body['partial'] ?? false) === true);
    $last = $sent->last();

    expect($partials->map(fn (array $body): array => array_keys($body['tools']))->flatten()->sort()->values()->all())->toBe(['pint', 'rector'])
        ->and($partials->every(fn (array $body): bool => count($body['tools']) === 1))->toBeTrue()
        ->and($last)->not->toHaveKey('partial')
        ->and(array_keys($last['tools']))->toBe(['pint', 'rector'])
        ->and($sent->count())->toBe(3);
});

it('says a rule is done only after its findings have been sent', function (): void {
    Saloon::fake([
        ShowScanToolsRequest::class => MockResponse::make(['tools' => ['pint', 'rector', 'tests']]),
        ReportScanToolProgressRequest::class => MockResponse::make(['progress' => []], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);

    Artisan::call('studio:tools', ['--plain' => true]);

    $calls = collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): PendingRequest => $response->getPendingRequest())
        ->map(fn (PendingRequest $request): array => [$request->getRequest(), (array) $request->body()?->all()])
        ->filter(fn (array $call): bool => $call[0] instanceof SubmitScanToolResultsRequest || ($call[0] instanceof ReportScanToolProgressRequest && in_array($call[1]['state'] ?? null, ['done', 'skipped'], true)))
        ->map(fn (array $call): string => $call[0] instanceof SubmitScanToolResultsRequest ? 'sent:'.implode(',', array_keys($call[1]['tools'])) : 'done:'.$call[1]['tool'])
        ->values();

    foreach (['pint', 'rector'] as $key) {
        $sent = $calls->search('sent:'.$key);
        $done = $calls->search('done:'.$key);

        expect($sent)->not->toBeFalse()->and($done)->not->toBeFalse()->and($sent)->toBeLessThan($done);
    }
});

it('gives the studio more than the usual 30 seconds to take thousands of findings', function (): void {
    expect((new SubmitScanToolResultsRequest('project', []))->config()->get('timeout'))->toBe(180);
});

it('gives up on a slow snapshot or ping soon, so the live counters on screen never freeze waiting for the studio', function (): void {
    expect((new ShowSnapshotRequest('project'))->config()->get('timeout'))->toBe(4)
        ->and((new PingRequest('owner/repo'))->config()->get('timeout'))->toBe(4);
});

it('runs every light check beside the tests, and holds only the heavy ones back for them', function (): void {
    expect(ToolsCommand::waitsForTheTests(['pint']))->toBeFalse()
        ->and(ToolsCommand::waitsForTheTests([]))->toBeFalse()
        ->and(ToolsCommand::waitsForTheTests(['pint', 'phpstan']))->toBeTrue()
        ->and(ToolsCommand::waitsForTheTests(['rector', 'studio-security', 'composer-audit']))->toBeFalse()
        ->and(ToolsCommand::waitsForTheTests(['pest-type-coverage']))->toBeTrue();
});
