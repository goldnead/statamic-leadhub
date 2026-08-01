<?php

namespace Goldnead\Leadhub\Tests;

use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\Leadhub\ServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Providers\StatamicServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase rolls the database back between tests. Nothing rolled
        // the flat-file storage back, and its path is per *process*, not per
        // test — so under LEADHUB_DRIVER=flat every test inherited every
        // contact, tag and follow-up written by every test before it in the
        // same process. That is what made the flat suite's failures depend on
        // which tests ran first: a count assertion saw somebody else's
        // records, and a detail page 404'd against an index rebuilt for a
        // different test's data. Give each test the empty store the eloquent
        // driver's tests get for free.
        $this->purgeFlatFileStorage();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Statamic's AddonServiceProvider runs bootAddon() inside
        // Statamic::booted(...). orchestra/testbench doesn't fire those
        // callbacks, so Nav, Permissions, and the rest of bootAddon never
        // register. Force it so HTTP feature tests can actually hit the
        // CP routes with the right ACLs in place.
        $this->bootAddons();
    }

    /**
     * Empty the flat-file store and its JSON index.
     *
     * Both have to go. Deleting the YAML while leaving the index would be
     * worse than leaving both: IndexBuilder decides staleness by comparing the
     * contacts directory's mtime against the index's `rebuilt_at`, both at
     * one-second resolution, so a stale index written in the same second as
     * the deletion is not detected as stale and a test reads records whose
     * files are gone.
     */
    protected function purgeFlatFileStorage(): void
    {
        $tmpRoot = sys_get_temp_dir().'/leadhub-test-'.getmypid();

        foreach ([$tmpRoot, storage_path('app/private/leadhub-test-'.getmypid())] as $path) {
            if (is_dir($path)) {
                (new Filesystem)->deleteDirectory($path);
            }
        }
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
            // Foundation for brand scoping: registers the `brands` table + the
            // always-present default brand so brand_id backfills have a target.
            BrandContextServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Required for any encrypted-cast model attributes; harmless otherwise.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', $this->databaseConnectionConfig());

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

        // Point Statamic's Stache (collections/entries) at a per-process temp
        // dir. Only the email-template tests create Statamic collections/
        // entries; redirecting the native stores keeps those writes out of the
        // shared testbench fixtures and isolated between parallel runs.
        $stacheRoot = sys_get_temp_dir().'/leadhub-stache-'.getmypid();
        $app['config']->set('statamic.stache.stores.collections.directory', $stacheRoot.'/content/collections');
        $app['config']->set('statamic.stache.stores.entries.directory', $stacheRoot.'/content/collections');
    }

    /**
     * In-memory SQLite by default, so the suite keeps running anywhere with no
     * setup. Set `DB_DRIVER=mysql` to point the identical suite at a real MySQL
     * server instead — see phpunit.mysql.xml.
     *
     * SQLite is not a substitute for that run. It has no InnoDB key-length
     * limit, no utf8mb4 byte arithmetic, no fixed column widths, and it reports
     * a different error for the same broken migration. Every migration defect
     * this addon has shipped was invisible on SQLite alone.
     * `tests/Unit/IndexKeyLengthTest.php` closes part of that gap without a
     * server; this closes it with one.
     *
     * The connection keeps the name `sqlite` whatever the driver is, so that
     * nothing in the suite that names a connection has to know which engine is
     * underneath it.
     *
     * @return array<string, mixed>
     */
    protected function databaseConnectionConfig(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'leadhub_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    /**
     * Statamic registers addon CP routes inside Statamic::booted callbacks
     * that orchestra/testbench doesn't fire. For HTTP feature tests we mount
     * them ourselves under the `statamic.cp.` name prefix and `/cp` URL prefix
     * that production uses.
     */
    protected function defineRoutes($router): void
    {
        // SubstituteBindings is part of Statamic's real CP middleware group and
        // is what applies any Route::bind() a sibling addon has registered.
        // Without it here, a route parameter whose name collides with another
        // addon's global binding passes every test and 404s in production —
        // which is exactly what v1.8.0 shipped. Nothing in this addon uses
        // implicit model binding, so adding it changes no other behaviour.
        $router->name('statamic.cp.')
            ->prefix('cp')
            ->middleware(SubstituteBindings::class)
            ->group(__DIR__.'/../routes/cp.php');

        // Public front-end routes (click tracking). Statamic mounts these at the
        // site root via $routes['web']; testbench doesn't fire those callbacks,
        // so mount them here the same way production does.
        $router->middleware('web')
            ->group(__DIR__.'/../routes/web.php');
    }
}
