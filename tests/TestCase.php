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

        // Statamic's AddonServiceProvider runs bootAddon() inside
        // Statamic::booted(...). orchestra/testbench doesn't fire those
        // callbacks, so Nav, Permissions, and the rest of bootAddon never
        // register. Force it so HTTP feature tests can actually hit the
        // CP routes with the right ACLs in place.
        $this->bootAddons();
    }

    /**
     * Manually fire the Statamic addon bootAddon() callbacks that
     * orchestra/testbench skips. Subclasses that need a peer addon booted
     * first (e.g. the live webhook-manager integration) override this.
     */
    protected function bootAddons(): void
    {
        $this->app->getProvider(ServiceProvider::class)?->bootAddon();
    }

    /**
     * Register the addon's $listen event listeners. Statamic does this in
     * bootEvents() from the Statamic::booted callback that testbench never
     * fires, so event-driven behaviour is otherwise inert in tests. Tests that
     * assert on event-listener side effects opt in by calling this.
     */
    protected function bootAddonEvents(): void
    {
        $provider = $this->app->getProvider(ServiceProvider::class);

        if ($provider && method_exists($provider, 'bootEvents')) {
            $provider->bootEvents();
        }
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
        // Required for any encrypted-cast model attributes; harmless otherwise.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

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

        // Driver: read from LEADHUB_DRIVER env so the CI matrix can flip
        // between eloquent and flat per job. Defaults to eloquent for local dev.
        $driver = env('LEADHUB_DRIVER', 'eloquent');
        $app['config']->set('leadhub.storage.driver', $driver);

        // Flat-file driver paths — use a unique per-process tmp dir so
        // parallel test runs don't trample each other.
        $tmpRoot = sys_get_temp_dir().'/leadhub-test-'.getmypid();
        $app['config']->set('leadhub.storage.flat', [
            'path' => $tmpRoot.'/content',
            'index_disk' => 'local',
            'index_path' => 'leadhub-test-'.getmypid().'/index',
        ]);
    }

    /**
     * Statamic registers addon CP routes inside Statamic::booted callbacks
     * that orchestra/testbench doesn't fire. For HTTP feature tests we mount
     * them ourselves under the `statamic.cp.` name prefix and `/cp` URL prefix
     * that production uses.
     */
    protected function defineRoutes($router): void
    {
        $router->name('statamic.cp.')
            ->prefix('cp')
            ->group(__DIR__.'/../routes/cp.php');
    }
}
