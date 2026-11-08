<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests\Support;

use ArtisanStudio\StudioCli\Work\TestRunner;
use Closure;

/**
 * A stand-in for the tests a project has: it covers nothing and nothing fails,
 * and a test overrides only what it needs. No test file is run.
 */
abstract class FakeTestRunner implements TestRunner
{
    public function for(array $paths): array
    {
        return [];
    }

    public function example(array $paths): ?array
    {
        return null;
    }

    public function failing(array $tests): ?array
    {
        return [];
    }

    public function failingLater(array $tests): Closure
    {
        return fn (): ?array => $this->failing($tests);
    }

    public function failures(array $tests): ?array
    {
        return [];
    }
}
