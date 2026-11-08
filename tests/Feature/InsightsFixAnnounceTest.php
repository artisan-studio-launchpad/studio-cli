<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\InsightsFixCommand;
use ArtisanStudio\StudioCli\Fix\LocalBranch;
use ArtisanStudio\StudioCli\Saloon\Requests\AuthorizeScanRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\PickScanRulesRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ReportScanToolProgressRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanFixReportRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitScanToolResultsRequest;
use ArtisanStudio\StudioCli\Scan\Tools\Toolbox;
use ArtisanStudio\StudioCli\Studio;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| The headless fix still tells the app
|--------------------------------------------------------------------------
|
| The scan step of the app moves on when the full scan results arrive, so the
| command sends what it read, the way the scan does, and then what is left.
|
*/

beforeEach(function (): void {
    Saloon::fake([
        ReportScanToolProgressRequest::class => MockResponse::make(['progress' => []], 202),
        SubmitScanToolResultsRequest::class => MockResponse::make(['findings' => 0, 'excluded' => [], 'rulesets' => []], 202),
    ]);

    $this->command = new InsightsFixCommand;
    $this->call = function (string $method, array $arguments): void {
        (new ReflectionMethod($this->command, $method))->invoke($this->command, ...$arguments);
    };
    $this->sent = fn (): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): PendingRequest => $response->getPendingRequest())
        ->map(fn (PendingRequest $request): array => [$request->getRequest()::class, (array) $request->body()?->all()])
        ->values()
        ->all();
    $this->finding = fn (string $where): array => ['where' => $where, 'rule' => 'parameter', 'message' => 'No type declared on this parameter.'];
});

it('sends what it read as the full scan result, and tells the app each rule is done', function (): void {
    $found = [
        'results' => ['phpstan' => ['ran' => false, 'reason' => 'It did not run.']],
        'kept' => ['pest-type-coverage' => [($this->finding)('app/A.php:3')]],
    ];

    ($this->call)('announce', [app(Studio::class), new Toolbox, new LocalBranch(base_path()), ['pest-type-coverage', 'phpstan'], $found]);

    $sent = collect(($this->sent)());
    $results = $sent->first(fn (array $call): bool => $call[0] === SubmitScanToolResultsRequest::class)[1];
    $states = $sent->filter(fn (array $call): bool => $call[0] === ReportScanToolProgressRequest::class)->map(fn (array $call): string => $call[1]['tool'].':'.$call[1]['state'])->values()->all();

    expect($results)->not->toHaveKey('partial')
        ->and($results['tools']['pest-type-coverage'])->toMatchArray(['ran' => true, 'findings' => [($this->finding)('app/A.php:3')]])
        ->and($results['tools']['phpstan']['ran'])->toBeFalse()
        ->and($states)->toBe(['pest-type-coverage:done', 'phpstan:skipped']);
});

it('authorizes the scan in the app, because the developer started it here', function (): void {
    Saloon::fake([AuthorizeScanRequest::class => MockResponse::make(['authorized' => true, 'already' => false], 202)]);

    app(Studio::class)->authorizeScan();

    expect(collect(($this->sent)())->pluck(0)->all())->toBe([AuthorizeScanRequest::class]);
});

it('tells the app which rules were run, so the scan begins with them as the picks', function (): void {
    Saloon::fake([PickScanRulesRequest::class => MockResponse::make(['started' => true], 202)]);

    app(Studio::class)->pickScanRules(['composer-audit', 'pint']);

    expect(($this->sent)())->toBe([[PickScanRulesRequest::class, ['tools' => ['composer-audit', 'pint']]]]);
});

it('follows the run live under a sami fixes branch name, as Fix with SAMI does, though no branch is made', function (): void {
    Saloon::fake([SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202)]);

    foreach (['studio' => app(Studio::class), 'run' => 'sami/fixes-2026-11-05-2043', 'chosen' => ['phpstan', 'pint']] as $property => $value) {
        (new ReflectionProperty($this->command, $property))->setValue($this->command, $value);
    }

    ($this->call)('pulse', ['fixing', 'fixing file by file']);

    expect(($this->sent)())->toBe([[SubmitScanFixReportRequest::class, [
        'branch' => 'sami/fixes-2026-11-05-2043',
        'live' => ['phase' => 'fixing', 'step' => 'fixing file by file', 'rulesets' => [
            'phpstan' => ['state' => 'running', 'found' => 0, 'fixed' => 0, 'left' => null],
            'pint' => ['state' => 'running', 'found' => 0, 'fixed' => 0, 'left' => null],
        ]],
    ]]]);
});

it('sends what is left after the fixes as a partial result', function (): void {
    $a = ($this->finding)('app/A.php:3');
    $b = ($this->finding)('app/B.php:4');
    $found = ['results' => [], 'kept' => ['pest-type-coverage' => [$a, $b]]];

    ($this->call)('announce', [app(Studio::class), new Toolbox, new LocalBranch(base_path()), ['pest-type-coverage'], $found]);
    ($this->call)('announceLeft', [app(Studio::class), new LocalBranch(base_path()), ['pest-type-coverage' => [$a]]]);

    $last = collect(($this->sent)())->filter(fn (array $call): bool => $call[0] === SubmitScanToolResultsRequest::class)->last()[1];

    expect($last['partial'])->toBeTrue()
        ->and($last['tools']['pest-type-coverage']['findings'])->toBe([$b]);
});

it('sends the fix report of what is committed, with the commit, as the app does', function (): void {
    Saloon::fake([SubmitScanFixReportRequest::class => MockResponse::make(['recorded' => true], 202)]);

    foreach (['studio' => app(Studio::class), 'run' => 'sami/fixes-2026-11-05-2043', 'from' => 'main', 'chosen' => ['phpstan', 'pint'], 'finished' => ['phpstan'], 'commits' => ['phpstan' => 'abc1234'], 'filesIn' => ['phpstan' => 3], 'report' => ['phpstan' => ['name' => 'PHPStan', 'found' => 5, 'fixed' => 4, 'kinds' => ['Missing type' => 4], 'note' => '']]] as $property => $value) {
        (new ReflectionProperty($this->command, $property))->setValue($this->command, $value);
    }

    ($this->call)('sendReport', []);

    expect(($this->sent)())->toBe([[SubmitScanFixReportRequest::class, [
        'branch' => 'sami/fixes-2026-11-05-2043',
        'from' => 'main',
        'rulesets' => ['phpstan' => ['name' => 'PHPStan', 'ran' => true, 'fixed' => 4, 'kinds' => ['Missing type' => 4], 'files' => 3, 'commit' => 'abc1234']],
        'diffs' => [],
    ]]]);
});
