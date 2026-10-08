<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Fix\Security\TargetedSecurityUpdates;

/*
|--------------------------------------------------------------------------
| Composer patches move the lockfile, never vendor
|--------------------------------------------------------------------------
|
| Updating vendor in place swaps the code under the running site, queue
| workers and studio, and breaks all three until it is done.
|
*/

it('patches only composer.lock, never vendor, and runs none of the project\'s scripts', function (): void {
    $root = sys_get_temp_dir().'/studio-composer-'.bin2hex(random_bytes(4));
    mkdir($root.'/bin', 0755, true);
    file_put_contents($root.'/composer.lock', (string) json_encode(['packages' => [['name' => 'guzzlehttp/guzzle', 'version' => '7.8.0']]]));
    file_put_contents($root.'/bin/composer', <<<'SH'
        #!/bin/sh
        echo "$@" >> "$(dirname "$0")/../calls.log"
        case "$1" in
            audit) if grep -q '7.8.0' "$(dirname "$0")/../composer.lock"; then echo '{"advisories":{"guzzlehttp/guzzle":[{"cve":"CVE-1"}]}}'; else echo '{"advisories":[]}'; fi ;;
            update) printf '{"packages":[{"name":"guzzlehttp/guzzle","version":"7.8.2"}]}' > "$(dirname "$0")/../composer.lock" ;;
        esac
        SH);
    chmod($root.'/bin/composer', 0755);

    $path = (string) getenv('PATH');
    putenv('PATH='.$root.'/bin:'.$path);

    try {
        $result = (new TargetedSecurityUpdates($root, fn (): array => ['PATH' => $root.'/bin:'.$path]))->fix('composer-audit');
    } finally {
        putenv('PATH='.$path);
    }

    $update = collect(file($root.'/calls.log', FILE_IGNORE_NEW_LINES))->first(fn (string $call): bool => str_starts_with($call, 'update'));

    expect($result)->toMatchArray(['ran' => true, 'fixed' => 1, 'changed' => ['guzzlehttp/guzzle 7.8.0 → 7.8.2']])
        ->and($update)->toContain('--no-install')->toContain('--no-scripts')
        ->and(collect(file($root.'/calls.log', FILE_IGNORE_NEW_LINES))->contains(fn (string $call): bool => str_starts_with($call, 'install')))->toBeFalse();
});
