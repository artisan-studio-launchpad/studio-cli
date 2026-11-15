<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\AiUsage;
use ArtisanStudio\StudioCli\Bridge\Shell;
use ArtisanStudio\StudioCli\EnvFile;
use ArtisanStudio\StudioCli\Saloon\Requests\ReadAiUsageRequest;
use ArtisanStudio\StudioCli\Saloon\Requests\ReportAiUsageRequest;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

/*
|--------------------------------------------------------------------------
| How much AI has been used, read on this machine
|--------------------------------------------------------------------------
|
| Asked with the login already here, read and never changed. Only the two
| percentages and their reset times go to the studio.
|
*/

beforeEach(function (): void {
    $this->env = sys_get_temp_dir().'/studio-env-'.uniqid();
    file_put_contents($this->env, "ARTISAN_STUDIO_URL=\"https://studio.test\"\nARTISAN_STUDIO_TOKEN=\"test-token\"\nARTISAN_STUDIO_PROJECT=\"1\"\n");
    app()->instance(EnvFile::class, new EnvFile($this->env));

    $this->home = sys_get_temp_dir().'/studio-ai-'.uniqid();
    mkdir($this->home);
    config(['studio-cli.bridge.claude_config_dir' => $this->home]);

    $this->login = fn (string $token = 'secret-access-token', ?int $expiresAt = null) => file_put_contents($this->home.'/.credentials.json', (string) json_encode([
        'claudeAiOauth' => ['accessToken' => $token, 'refreshToken' => 'secret-refresh-token', 'expiresAt' => $expiresAt ?? (time() + 3600) * 1000],
    ]));
    $this->answers = function (array $answer): void {
        Saloon::fake([
            ReadAiUsageRequest::class => MockResponse::make($answer),
            ReportAiUsageRequest::class => MockResponse::make('', 204),
        ]);
    };
    $this->sent = fn (string $request): array => collect(Saloon::mockClient()->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn ($pending): bool => $pending->getRequest() instanceof $request)
        ->values()
        ->all();
    $this->usage = fn (): AiUsage => app(AiUsage::class);
});

afterEach(function (): void {
    @unlink($this->home.'/.credentials.json');
    @rmdir($this->home);
});

it('reads the two percentages and when they reset', function (): void {
    ($this->login)();
    ($this->answers)(['five_hour' => ['utilization' => 34.4, 'resets_at' => '2026-11-13T15:00:00+00:00'], 'seven_day' => ['utilization' => 61.6, 'resets_at' => '2026-11-18T09:00:00Z']]);

    expect(($this->usage)()->read())->toBe([
        'session' => ['percent' => 34, 'resets_at' => '2026-11-13T15:00:00+00:00'],
        'week' => ['percent' => 62, 'resets_at' => '2026-11-18T09:00:00+00:00'],
    ]);
});

it('asks with the login that is on this machine', function (): void {
    ($this->login)('the-one-on-this-machine');
    ($this->answers)(['five_hour' => ['utilization' => 10, 'resets_at' => null]]);

    ($this->usage)()->read();

    expect(($this->sent)(ReadAiUsageRequest::class)[0]->headers()->get('Authorization'))->toBe('Bearer the-one-on-this-machine');
});

it('tells the studio the numbers and nothing else, never the login', function (): void {
    ($this->login)();
    ($this->answers)(['five_hour' => ['utilization' => 20, 'resets_at' => null]]);

    ($this->usage)()->keep();

    $reports = ($this->sent)(ReportAiUsageRequest::class);
    $body = (string) json_encode($reports[0]->body()->all());

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->body()->all())->toBe(['session' => ['percent' => 20, 'resets_at' => null], 'week' => null])
        ->and($body)->not->toContain('secret');
});

it('asks every couple of minutes and says nothing again while the numbers stay the same', function (): void {
    ($this->login)();
    ($this->answers)(['five_hour' => ['utilization' => 20, 'resets_at' => null]]);
    $usage = ($this->usage)();

    $usage->keep();
    $usage->keep();

    expect(($this->sent)(ReadAiUsageRequest::class))->toHaveCount(1);

    (fn () => $this->askedAt = 0)->call($usage);
    $usage->keep();

    expect(($this->sent)(ReadAiUsageRequest::class))->toHaveCount(2)
        ->and(($this->sent)(ReportAiUsageRequest::class))->toHaveCount(1);

    ($this->answers)(['five_hour' => ['utilization' => 25, 'resets_at' => null]]);
    (fn () => $this->askedAt = 0)->call($usage);
    $usage->keep();

    expect(($this->sent)(ReportAiUsageRequest::class))->toHaveCount(2);
});

it('says nothing when there is no login, or it has run out, and never renews or writes one', function (): void {
    ($this->answers)(['five_hour' => ['utilization' => 20, 'resets_at' => null]]);

    expect(($this->usage)()->read())->toBeNull();

    ($this->login)('old-token', (time() - 60) * 1000);
    $before = file_get_contents($this->home.'/.credentials.json');

    expect(($this->usage)()->read())->toBeNull()
        ->and(file_get_contents($this->home.'/.credentials.json'))->toBe($before)
        ->and(($this->sent)(ReadAiUsageRequest::class))->toBeEmpty();
});

it('says nothing when the answer is refused or has no numbers in it', function (): void {
    ($this->login)();
    Saloon::fake([ReadAiUsageRequest::class => MockResponse::make(['error' => 'nope'], 401)]);

    expect(($this->usage)()->read())->toBeNull();

    ($this->answers)(['something' => 'else']);

    expect(($this->usage)()->read())->toBeNull();
});

it('says in plain words why nothing was read, with no secret in it', function (): void {
    ($this->usage)()->read();

    expect(($this->usage)()->problem())->toBeNull();

    $usage = ($this->usage)();
    $usage->read();

    expect($usage->problem())->toBe('no login found on this machine');

    ($this->login)('old-secret-token', (time() - 60) * 1000);
    $usage->read();

    expect($usage->problem())->toContain('run out')->not->toContain('secret');

    ($this->login)();
    Saloon::fake([ReadAiUsageRequest::class => MockResponse::make(['error' => 'nope'], 401)]);
    $usage->read();

    expect($usage->problem())->toBe('the AI would not say (HTTP 401)');

    ($this->answers)(['five_hour' => ['utilization' => 20, 'resets_at' => null]]);

    expect($usage->read())->not->toBeNull()
        ->and($usage->problem())->toBeNull();
});

it('keeps its note in the studio folder out of git, and writes nothing where that folder is not', function (): void {
    $storage = sys_get_temp_dir().'/studio-store-'.uniqid();
    $folder = $storage.'/studio';
    mkdir($storage);
    app()->useStoragePath($storage);

    ($this->login)();
    ($this->answers)(['five_hour' => ['utilization' => 20, 'resets_at' => null]]);

    ($this->usage)()->keep();

    expect(is_dir($folder))->toBeFalse();

    mkdir($folder);
    app()->forgetInstance(AiUsage::class);
    ($this->usage)()->keep();

    expect(file_get_contents($folder.'/.gitignore'))->toBe('*'.PHP_EOL)
        ->and(json_decode((string) file_get_contents($folder.'/ai-usage.json'), true)['outcome'])->toBe('read');

    array_map('unlink', glob($folder.'/{.gitignore,ai-usage.json}', GLOB_BRACE) ?: []);
    @rmdir($folder);
    @rmdir($storage);
});

it('looks in the keychain under the user\'s own name first, so a stale item of the same name is never the one it finds', function (): void {
    if (PHP_OS_FAMILY !== 'Darwin') {
        $this->markTestSkipped('The keychain is a Mac thing.');
    }

    $asked = [];
    $fresh = (string) json_encode(['claudeAiOauth' => ['accessToken' => 'fresh-token', 'expiresAt' => (time() + 3600) * 1000]]);
    $stale = (string) json_encode(['claudeAiOauth' => ['accessToken' => 'stale-token', 'expiresAt' => (time() - 86400) * 1000]]);
    app()->instance(Shell::class, new class($asked, $fresh, $stale) extends Shell
    {
        public function __construct(public array &$asked, private readonly string $fresh, private readonly string $stale) {}

        public function run(array $command, string $input, string $cwd, int $timeout): array
        {
            $this->asked[] = $command;

            return ['exit' => 0, 'out' => in_array('-a', $command, true) ? $this->fresh : $this->stale, 'err' => '', 'timedOut' => false];
        }
    });
    app()->forgetInstance(AiUsage::class);
    ($this->answers)(['five_hour' => ['utilization' => 12, 'resets_at' => null]]);

    $usage = ($this->usage)()->read();

    expect($usage['session']['percent'])->toBe(12)
        ->and($asked)->toHaveCount(1)
        ->and($asked[0])->toContain('-a')
        ->and(($this->sent)(ReadAiUsageRequest::class)[0]->headers()->get('Authorization'))->toBe('Bearer fresh-token');
});
