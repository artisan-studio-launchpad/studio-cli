<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Console;

use ArtisanStudio\StudioCli\AvatarClips;
use ArtisanStudio\StudioCli\AvatarPlayer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BuildPresenceCommand extends Command
{
    public const string SIGNATURE = 'studio:build-avatar';

    protected $signature = self::SIGNATURE;

    protected $aliases = ['artisan-studio:build-presence'];

    protected $description = 'Compile the desktop player that shows your avatar while Artisan Studio is open';

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->components->error('The desktop player is macOS only: it uses AppKit for the transparent window.');

            return self::FAILURE;
        }

        if (! $this->hasSwift()) {
            $this->components->error('No Swift compiler found.');
            $this->components->bulletList(['Install Xcode command line tools: xcode-select --install']);

            return self::FAILURE;
        }

        $source = dirname(__DIR__).'/AvatarDesktop.swift';
        $target = AvatarPlayer::path();

        if (! is_dir($directory = dirname($target))) {
            mkdir($directory, 0755, true);
        }

        $this->components->task('Compiling the player', fn (): bool => $this->compile($source, $target));

        if (! is_executable($target)) {
            $this->components->error('The compiler ran but produced nothing usable.');

            return self::FAILURE;
        }

        $this->components->info('Your avatar can appear now. It shows up while `php artisan studio` is open.');

        if (app(AvatarClips::class)->fromTheStudio()) {
            $this->call(AvatarSyncCommand::SIGNATURE);
        }

        $this->components->bulletList([
            'The avatar\'s clips are kept in '.app(AvatarClips::class)->folder().', one .mov per state.',
            'Turn the avatar off any time with STUDIO_CLI_PRESENCE=false.',
        ]);

        return self::SUCCESS;
    }

    private function compile(string $source, string $target): bool
    {
        $slices = [];

        foreach (['x86_64', 'arm64'] as $architecture) {
            $slice = sys_get_temp_dir().'/avatardesktop-'.$architecture;

            $built = Process::run([
                'swiftc', '-O',
                '-target', $architecture.'-apple-macos11',
                '-o', $slice, $source,
            ]);

            if (! $built->successful()) {
                return false;
            }

            $slices[] = $slice;
        }

        $joined = Process::run(['lipo', '-create', ...$slices, '-output', $target]);

        foreach ($slices as $slice) {
            @unlink($slice);
        }

        return $joined->successful();
    }

    private function hasSwift(): bool
    {
        return Process::run(['which', 'swiftc'])->successful();
    }
}
