<?php

namespace Goldnead\Leadhub\Tests;

use Goldnead\Leadhub\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Providers\StatamicServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('leadhub.default_status', 'new');
        $app['config']->set('leadhub.timeline_payload_redaction', [
            'password',
            'token',
            'secret',
        ]);

        // Default test driver: eloquent.
        $app['config']->set('leadhub.storage.driver', 'eloquent');
        $app['config']->set('leadhub.storage.flat', [
            'path' => sys_get_temp_dir().'/leadhub-test-flat',
            'index_disk' => 'local',
            'index_path' => 'leadhub/index',
        ]);
    }
}
