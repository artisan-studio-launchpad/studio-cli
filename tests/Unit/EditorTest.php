<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Editor;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    config(['studio-cli.review.editor' => 'phpstorm', 'studio-cli.review.keep_front' => true]);
    $this->apps = fn (string $front): string => "1) \"Finder\" ASN:0x0-0x14014: \n    bundleID=\"com.apple.finder\"\n2) \"{$front}\" ASN:0x0-dc4dc4: (in front) \n    bundleID=\"".($front === 'PhpStorm' ? 'com.jetbrains.PhpStorm' : 'com.google.Chrome')."\"\n";
});

it('opens a file in the editor behind the browser and watches to put the browser back in front if the editor takes it', function (): void {
    Process::fake([
        '*/bin/sh*' => Process::result(),
        '*lsappinfo*' => Process::result(output: ($this->apps)('Google Chrome')),
        '*phpstorm://*' => Process::result(),
    ]);

    expect((new Editor('/project'))->open(['app/Models/Run.php']))->toBeTrue();

    Process::assertRan(fn ($process): bool => $process->command === ['open', '-g', 'phpstorm://open?file=/project/app/Models/Run.php']);
    Process::assertRan(fn ($process): bool => ($process->command[0] ?? null) === '/bin/sh'
        && str_contains((string) $process->command[2], "open -b 'com.google.Chrome'")
        && str_ends_with((string) $process->command[2], ') >/dev/null 2>&1 &'));
})->skip(PHP_OS_FAMILY !== 'Darwin', 'Only a Mac has an app in front.');

it('puts the browser back every time the editor takes the front while files open, and stops once it stays put', function (): void {
    $watch = '';
    Process::fake([
        '*/bin/sh*' => function ($process) use (&$watch) {
            $watch = (string) $process->command[2];

            return Process::result();
        },
        '*lsappinfo*' => Process::result(output: ($this->apps)('Google Chrome')),
        '*phpstorm://*' => Process::result(),
    ]);
    (new Editor('/project'))->open(['app/Models/Run.php', 'app/Models/Step.php']);
    $bin = sys_get_temp_dir().'/editor-watch-'.bin2hex(random_bytes(4));
    mkdir($bin);
    $fronts = ['com.google.Chrome', 'com.jetbrains.PhpStorm', 'com.google.Chrome', 'com.jetbrains.PhpStorm', 'com.google.Chrome'];
    file_put_contents($bin.'/fronts', implode("\n", $fronts)."\n");
    file_put_contents($bin.'/lsappinfo', "#!/bin/sh\nfront=\$(head -n 1 '{$bin}/fronts'); [ -n \"\$front\" ] && sed -i '' 1d '{$bin}/fronts'; printf '1) \"App\" ASN:0x0-1: (in front) \\n    bundleID=\"%s\"\\n' \"\${front:-com.google.Chrome}\"\n");
    file_put_contents($bin.'/open', "#!/bin/sh\necho \"\$*\" >> '{$bin}/opened'\n");
    chmod($bin.'/lsappinfo', 0755);
    chmod($bin.'/open', 0755);
    $loop = substr($watch, 1, (int) strrpos($watch, ') >/dev/null') - 1);

    $ran = (new Symfony\Component\Process\Process(['/bin/sh', '-c', $loop], null, ['PATH' => $bin.':/usr/bin:/bin']))->setTimeout(10);
    $ran->run();

    expect(file($bin.'/opened', FILE_IGNORE_NEW_LINES))->toBe(['-b com.google.Chrome', '-b com.google.Chrome'])
        ->and(file_get_contents($bin.'/fronts'))->toBe('');
})->skip(PHP_OS_FAMILY !== 'Darwin', 'Only a Mac has an app in front.');

it('leaves the front alone when the developer is already in their editor, or has turned it off', function (): void {
    Process::fake([
        '*/bin/sh*' => Process::result(),
        '*lsappinfo*' => Process::result(output: ($this->apps)('PhpStorm')),
        '*phpstorm://*' => Process::result(),
    ]);

    (new Editor('/project'))->open(['app/Models/Run.php']);
    config(['studio-cli.review.keep_front' => false]);
    (new Editor('/project'))->open(['app/Models/Run.php']);

    Process::assertRanTimes(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'phpstorm://'), 2);
    Process::assertRanTimes(fn ($process): bool => ($process->command[0] ?? null) === '/bin/sh', 0);
    Process::assertRanTimes(fn ($process): bool => ($process->command[0] ?? null) === 'lsappinfo', 1);
})->skip(PHP_OS_FAMILY !== 'Darwin', 'Only a Mac has an app in front.');
