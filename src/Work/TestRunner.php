<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

/**
 * What a work run needs from a project's tests: which of them cover the files
 * it changed, an example of how they are written, and which of them fail. It
 * only asks and runs. Which tests matter for a file is for Artisan Studio to
 * say, so the CLI holds no rule for it.
 */
interface TestRunner
{
    /**
     * The tests that cover these files.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function for(array $paths): array;

    /**
     * A test of the project to copy the style of, for a file that has none.
     *
     * @return array{path: string, content: string}|null
     */
    public function example(string $path): ?array;

    /**
     * The tests that fail when these run, or null when they could not run.
     *
     * @param  list<string>  $tests
     * @return list<string>|null
     */
    public function failing(array $tests): ?array;

    /**
     * Each failing test with what it said, or null when they could not run.
     *
     * @param  list<string>  $tests
     * @return array<string, string>|null
     */
    public function failures(array $tests): ?array;
}
