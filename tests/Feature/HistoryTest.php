<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Console\HistoryCommand;
use ArtisanStudio\StudioCli\History\FeatureCommits;
use ArtisanStudio\StudioCli\Saloon\Requests\SubmitRepoHistoryRequest;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;

/**
 * A throwaway git repository with the commits it is given, oldest first.
 *
 * @param  list<array{0: string, 1?: string}>  $commits  subject, then body
 */
function repoWith(array $commits): string
{
    $root = sys_get_temp_dir().'/studio-history-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    $git = fn (array $args): Process => tap(new Process(['git', '-c', 'user.name=Dev', '-c', 'user.email=dev@example.test', ...$args], $root))->mustRun();
    $git(['init', '-q']);

    foreach ($commits as $index => [$subject, $body]) {
        file_put_contents($root.'/file.txt', (string) $index);
        $git(['add', '.']);
        $git(['commit', '-q', '-m', $subject, ...($body === '' ? [] : ['-m', $body])]);
    }

    return $root;
}

beforeEach(function (): void {
    $this->sent = fn (): ?PendingRequest => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response): PendingRequest => $response->getPendingRequest())
        ->first(fn (PendingRequest $request): bool => $request->getRequest() instanceof SubmitRepoHistoryRequest);
});

it('reads only the feat commits of a history that uses conventional messages, newest first, with no code', function (): void {
    $root = repoWith([
        ['feat: invoices can be exported', 'A CSV of every invoice in a month.'],
        ['fix: totals round correctly', ''],
        ['feat(billing): customers pay by card', "Stripe checkout.\n\nCo-Authored-By: Someone <s@example.test>"],
        ['chore: update dependencies', ''],
        ['feat!: reminders replace the old alerts', ''],
        ['docs: explain exports', ''],
        ['Merge the thing', ''],
    ]);

    $read = (new FeatureCommits($root))->read();

    expect($read['conventional'])->toBeTrue()
        ->and($read['total'])->toBe(7)
        ->and(array_column($read['commits'], 'subject'))->toBe(['reminders replace the old alerts', 'customers pay by card', 'invoices can be exported'])
        ->and(array_column($read['commits'], 'scope'))->toBe([null, 'billing', null])
        ->and($read['commits'][1]['body'])->toBe('Stripe checkout.')
        ->and($read['commits'][2]['body'])->toBe('A CSV of every invoice in a month.')
        ->and($read['commits'][0]['hash'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($read['commits'][0]['committed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/')
        ->and($read['commit'])->toBe(trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput()));
});

it('sends nothing from a history that does not use conventional messages, so no one guesses at it', function (): void {
    $root = repoWith([
        ['Added the invoice export', ''],
        ['fixed totals', ''],
        ['feat: one stray feature', ''],
        ['more work on billing', ''],
        ['wip', ''],
        ['updates', ''],
    ]);

    $read = (new FeatureCommits($root))->read();

    expect($read['conventional'])->toBeFalse()
        ->and($read['total'])->toBe(6)
        ->and($read['commits'])->toBe([]);
});

it('says there is nothing to read in a folder that is not a git repository', function (): void {
    $folder = sys_get_temp_dir().'/studio-not-git-'.bin2hex(random_bytes(4));
    mkdir($folder, 0755, true);

    expect((new FeatureCommits($folder))->read())->toBe(['commit' => null, 'total' => 0, 'conventional' => false, 'commits' => []]);
});

it('sends the feat commits to the studio', function (): void {
    $root = repoWith([
        ['feat: invoices can be exported', 'A CSV.'],
        ['feat(billing): customers pay by card', ''],
        ['fix: totals', ''],
        ['feat: reminders', ''],
        ['feat: team access', ''],
        ['chore: deps', ''],
    ]);
    app()->instance(FeatureCommits::class, new FeatureCommits($root));
    Saloon::fake([SubmitRepoHistoryRequest::class => MockResponse::make(['accepted' => 4], 202)]);

    $this->artisan(HistoryCommand::SIGNATURE)
        ->expectsOutputToContain('Sent 4 feat commits')
        ->assertSuccessful();

    $request = ($this->sent)();
    $body = $request->body()->all();

    expect($request->getUrl())->toBe('https://studio.test/api/v1/projects/1/repo-history')
        ->and($body['conventional'])->toBeTrue()
        ->and($body['total'])->toBe(6)
        ->and(array_column($body['commits'], 'subject'))->toBe(['team access', 'reminders', 'customers pay by card', 'invoices can be exported']);
});

it('still tells the studio when the history is not conventional, so it can say so', function (): void {
    app()->instance(FeatureCommits::class, new FeatureCommits(repoWith([['wip', ''], ['more wip', '']])));
    Saloon::fake([SubmitRepoHistoryRequest::class => MockResponse::make(['accepted' => 0], 202)]);

    $this->artisan(HistoryCommand::SIGNATURE)
        ->expectsOutputToContain('do not use conventional messages')
        ->assertSuccessful();

    expect(($this->sent)()->body()->all())->toMatchArray(['conventional' => false, 'commits' => []]);
});

it('prints what it would send, and sends nothing', function (): void {
    app()->instance(FeatureCommits::class, new FeatureCommits(repoWith([['feat: a', ''], ['feat: b', ''], ['feat: c', ''], ['feat: d', ''], ['feat: e', '']])));
    Saloon::fake([SubmitRepoHistoryRequest::class => MockResponse::make(['accepted' => 5], 202)]);

    $this->artisan(HistoryCommand::SIGNATURE, ['--json' => true])
        ->expectsOutputToContain('"conventional": true')
        ->assertSuccessful();

    expect(($this->sent)())->toBeNull();
});

it('says so when the studio does not take it', function (): void {
    app()->instance(FeatureCommits::class, new FeatureCommits(repoWith([['feat: a', '']])));
    Saloon::fake([SubmitRepoHistoryRequest::class => MockResponse::make(['message' => 'Forbidden.'], 403)]);

    $this->artisan(HistoryCommand::SIGNATURE)
        ->expectsOutputToContain('The studio did not take the history')
        ->assertFailed();
});
