<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Work\StepChecks;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-cli-step-checks-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->root);
});

it('reads the folders the project has PHPStan analyse, so a step is checked only where the codebase checks itself', function (): void {
    file_put_contents($this->root.'/phpstan.neon', "includes:\n    - vendor/larastan/larastan/extension.neon\n\nparameters:\n    paths:\n        - app\n        - 'src/'  # the package\n        - %currentWorkingDirectory%/routes\n    level: 5\n");

    expect(StepChecks::analysedIn($this->root))->toBe(['app', 'src', 'routes']);
});

it('reads the shipped config when there is no local one, and checks everything when there is none at all', function (): void {
    expect(StepChecks::analysedIn($this->root))->toBe([]);

    file_put_contents($this->root.'/phpstan.neon.dist', "parameters:\n  paths:\n    - app\n    - tests\n");

    expect(StepChecks::analysedIn($this->root))->toBe(['app', 'tests']);
});
