<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests;

use ArtisanStudio\StudioCli\Dashboard\SampleSnapshots;
use ArtisanStudio\StudioCli\Dashboard\SnapshotSource;
use ArtisanStudio\StudioCli\Says;
use ArtisanStudio\StudioCli\StateFile;
use ArtisanStudio\StudioCli\StudioCliServiceProvider;
use ArtisanStudio\StudioCli\Work\WorkPanel;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Saloon\Laravel\SaloonServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SaloonServiceProvider::class,
            StudioCliServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $state = sys_get_temp_dir().'/studio-cli-tests-'.(getenv('TEST_TOKEN') ?: 'solo');
        @mkdir($state, 0755, true);
        StateFile::keepIn($state);

        $this->app->instance(SnapshotSource::class, new SampleSnapshots);
        config(['studio-cli.branches' => ['fixes' => 'studio/fixes-', 'build' => 'studio/build-', 'commit_scope' => 'studio', 'lock' => 'studio-fixes.lock']]);
        $this->app->instance(Says::class, new class extends Says
        {
            public function __construct() {}

            public function name(): string
            {
                return 'SAMI';
            }

            public function line(string $tab, string $moment): array
            {
                return ['title' => "[{$tab}.{$moment}]", 'line' => "[{$tab}.{$moment} line]", 'colour' => 'sky'];
            }

            public function has(string $tab, string $moment): bool
            {
                return $tab !== WorkPanel::BUILD || in_array($moment, ['prototype', 'working', 'final', 'done', 'halted', 'died'], true);
            }
        });
    }

    /**
     * Linked, because that is the state nearly every test is about.
     *
     * The dev tab only registers for a project that has been linked — an
     * unlinked one would never use it — so a suite that left this out would be
     * testing a package nobody had set up. The unlinked case has its own test.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('studio-cli.url', 'https://studio.test');
        $app['config']->set('studio-cli.token', 'test-token');
        $app['config']->set('studio-cli.project', '1');
        $app['config']->set('studio-cli.tabs.only_when_asked', false);
    }
}
