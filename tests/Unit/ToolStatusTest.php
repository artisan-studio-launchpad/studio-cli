<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\ToolStatus;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-tool-status-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
});

afterEach(function (): void {
    @unlink((new ToolStatus($this->root))->path());
});

it('adds rules to the run under way without losing what it has done', function (): void {
    $status = new ToolStatus($this->root);
    $status->running(['pint']);
    $status->ran('pint', ['ran' => true, 'findings' => []]);

    $status->alsoRunning(['phpstan', 'pint']);

    expect($status->run()['tools'])->toBe(['pint', 'phpstan'])
        ->and($status->run()['done'])->toBe(['pint'])
        ->and($status->outcome('pint'))->toMatchArray(['ran' => true, 'findings' => 0]);
});

it('starts a run of its own when none is under way', function (): void {
    $status = new ToolStatus($this->root);

    $status->alsoRunning(['phpstan']);

    expect($status->run()['tools'])->toBe(['phpstan'])->and($status->run()['done'])->toBe([]);
});
