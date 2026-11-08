<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Bridge\Shell;

it('starts a program with only what it needs of the studio\'s environment, never a project\'s keys', function (): void {
    putenv('STRIPE_SECRET=sk_live_should_not_travel');
    putenv('CLAUDE_CODE_SSE_PORT=51554');
    $_SERVER['AWS_SECRET_ACCESS_KEY'] = 'should_not_travel_either';
    $_ENV['DB_PASSWORD'] = 'nor_this';

    $ran = (new Shell)->run([PHP_BINARY, '-r', 'echo json_encode(array_keys(getenv()));'], '', sys_get_temp_dir(), 20);
    $names = json_decode($ran['out'], true);

    putenv('STRIPE_SECRET');
    putenv('CLAUDE_CODE_SSE_PORT');
    unset($_SERVER['AWS_SECRET_ACCESS_KEY'], $_ENV['DB_PASSWORD']);

    expect($ran['exit'])->toBe(0)
        ->and($names)->toContain('PATH')
        ->and($names)->not->toContain('STRIPE_SECRET')
        ->and($names)->not->toContain('CLAUDE_CODE_SSE_PORT')
        ->and($names)->not->toContain('AWS_SECRET_ACCESS_KEY')
        ->and($names)->not->toContain('DB_PASSWORD');
});

it('tells Claude Code to leave out the extra calls that put its own prompt in front of every task', function (): void {
    $ran = (new Shell)->run([PHP_BINARY, '-r', 'echo getenv("CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC");'], '', sys_get_temp_dir(), 20);

    expect($ran['out'])->toBe('1');
});
