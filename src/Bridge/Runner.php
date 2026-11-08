<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * An AI program already installed and signed in on the developer's machine.
 */
interface Runner
{
    public function name(): string;

    /**
     * Why this machine cannot run tasks yet, in words for the developer, or
     * null when it can.
     */
    public function notReady(): ?string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function run(array $payload): Outcome;
}
