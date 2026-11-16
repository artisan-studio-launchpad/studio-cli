<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\AvatarPlayer;

it('finds the player the package ships from where the package is, never from the name it is installed under', function (): void {
    config()->set('studio-cli.presence.player', null);

    $shipped = dirname(__DIR__, 2).'/bin/avatardesktop';

    expect(AvatarPlayer::path())->toBe($shipped)
        ->and(is_file($shipped))->toBeTrue()
        ->and(AvatarPlayer::path())->not->toBe(base_path('vendor/artisan-studio-projects/studio-cli/bin/avatardesktop'))
        ->and(AvatarPlayer::path())->not->toBe(base_path('vendor/artisan-studio-launchpad/studio-cli/bin/avatardesktop'));
});

it('uses a player path that was set, and the shipped one when the setting is empty', function (?string $set, string $expected): void {
    config()->set('studio-cli.presence.player', $set);

    expect(AvatarPlayer::path())->toBe($expected);
})->with([
    'a path that was set' => ['/somewhere/else/avatardesktop', '/somewhere/else/avatardesktop'],
    'nothing set' => [null, dirname(__DIR__, 2).'/bin/avatardesktop'],
    'an empty string' => ['', dirname(__DIR__, 2).'/bin/avatardesktop'],
]);
