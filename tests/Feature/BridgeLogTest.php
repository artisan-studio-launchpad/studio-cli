<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\BridgeLog;
use ArtisanStudio\StudioCli\Bridge\Outcome;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The bridge log is private to this machine
|--------------------------------------------------------------------------
|
| Its files are private to the user, git ignores the folder, and a file older
| than two weeks is removed whenever a new task is noted.
|
*/

beforeEach(function (): void {
    $this->folder = sys_get_temp_dir().'/studio-bridge-log-'.bin2hex(random_bytes(4));
    $this->task = ['id' => 'a-task', 'kind' => 'text', 'payload' => ['model' => 'haiku', 'prompt' => 'Reply', 'instructions' => 'Short']];
});

afterEach(fn () => File::deleteDirectory($this->folder));

it('writes the log files private to the user, in a folder that git is told to ignore', function (): void {
    $log = new BridgeLog($this->folder);

    $log->note($this->task, 'claude', new Outcome(true, 'pong'), 10);

    expect(decoct(fileperms($log->path()) & 0777))->toBe('600')
        ->and(decoct(fileperms($this->folder) & 0777))->toBe('700')
        ->and(trim((string) file_get_contents($this->folder.'/.gitignore')))->toBe('*');
});

it('narrows the permissions of a log file that was left open by an earlier version', function (): void {
    mkdir($this->folder, 0755, true);
    $log = new BridgeLog($this->folder);
    file_put_contents($log->path(), '');
    chmod($log->path(), 0644);

    $log->note($this->task, 'claude', new Outcome(true, 'pong'), 10);

    expect(decoct(fileperms($log->path()) & 0777))->toBe('600');
});

it('narrows the permissions of every earlier day\'s file too, not only today\'s', function (): void {
    mkdir($this->folder, 0755, true);
    $earlier = $this->folder.'/2026-01-02.jsonl';
    file_put_contents($earlier, "{}\n");
    chmod($earlier, 0644);
    touch($earlier, time() - 86_400);

    (new BridgeLog($this->folder))->note($this->task, 'claude', new Outcome(true, 'pong'), 10);

    expect(decoct(fileperms($earlier) & 0777))->toBe('600');
});

it('writes a plain local file at its own path and never reaches for a storage disk', function (): void {
    Storage::fake('local');
    Storage::fake('s3');
    $log = new BridgeLog($this->folder);

    $log->note($this->task, 'claude', new Outcome(true, 'pong'), 10);

    expect(is_file($log->path()))->toBeTrue()
        ->and(str_starts_with($log->path(), $this->folder.'/'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

arch('the bridge log does not use the Storage facade or a configurable disk')
    ->expect(BridgeLog::class)
    ->not->toUse([Storage::class, Filesystem::class, FilesystemManager::class]);

it('removes the log files older than fourteen days and keeps the newer ones', function (): void {
    mkdir($this->folder, 0700, true);
    $old = $this->folder.'/2026-01-01.jsonl';
    $recent = $this->folder.'/2026-01-02.jsonl';
    file_put_contents($old, "{}\n");
    file_put_contents($recent, "{}\n");
    touch($old, time() - (BridgeLog::KEPT_DAYS + 1) * 86_400);
    touch($recent, time() - (BridgeLog::KEPT_DAYS - 1) * 86_400);

    (new BridgeLog($this->folder))->note($this->task, 'claude', new Outcome(true, 'pong'), 10);

    expect(is_file($old))->toBeFalse()
        ->and(is_file($recent))->toBeTrue();
});

it('keeps what was asked out of the log unless debug is on', function (bool $debug): void {
    $log = new BridgeLog($this->folder, $debug);

    $log->note($this->task, 'claude', new Outcome(true, 'pong'), 10);

    $line = json_decode(trim((string) file_get_contents($log->path())), true);

    expect(str_contains(json_encode($line), 'Reply'))->toBe($debug)
        ->and($line['prompt_chars'])->toBe(5)
        ->and($line['instructions_chars'])->toBe(5);
})->with(['by default' => false, 'with debug on' => true]);
