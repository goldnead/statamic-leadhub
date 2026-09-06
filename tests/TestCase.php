<?php

namespace Goldnead\Leadhub\Tests;

use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\Leadhub\ServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Licensing\Outpost;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;
use Statamic\Version as StatamicVersion;

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
            // In production Laravel discovers this one from the installed
            // package; testbench discovers nothing, so it was simply absent.
            // Its absence is invisible until a request runs through Statamic's
            // own CP middleware: HandleAuthenticatedInertiaRequests calls
            // $request->inertia(), a macro this provider registers, and without
            // it every core CP page dies on a BadMethodCallException — which
            // looks exactly like the addon breaking the Control Panel.
            InertiaServiceProvider::class,
            // Foundation for brand scoping: registers the `brands` table + the
            // always-present default brand so brand_id backfills have a target.
            BrandContextServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    /**
     * The root-namespace `Statamic` alias.
     *
     * A real Statamic install has it in config/app.php; testbench loads no
     * package aliases. Statamic's own CP views call `Statamic::pro()` against
     * the alias, so without it every core Control Panel page dies inside
     * `nav/updates.blade.php` — long before anything this addon registers gets
     * a chance to be wrong.
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Statamic' => Statamic::class];
    }

    protected function defineEnvironment($app): void
    {
        // Required for any encrypted-cast model attributes; harmless otherwise.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Statamic's CP middleware asks for its own version in two places (the
        // licensing outpost and the Inertia props it shares with every CP page),
        // and `Statamic\Version` reads it out of composer.lock — which
        // orchestra/testbench's application skeleton does not have. Every CP
        // route registered the normal way (Statamic::pushCpRoutes) therefore
        // answers 500 in this suite. That never showed up before because this
        // addon's own CP routes are mounted by hand in defineRoutes(), outside
        // the middleware group; the suite-wide settings screen its settings now
        // live on is registered the normal way and does go through it.
        //
        // Answered from this repository's own lock file rather than a made-up
        // constant, so a Statamic upgrade cannot leave the tests asserting
        // against a version nothing here runs.
        $app->bind(StatamicVersion::class, fn () => new class extends StatamicVersion
        {
            public function get()
            {
                $lock = json_decode((string) file_get_contents(__DIR__.'/../composer.lock'), true);

                foreach ($lock['packages'] ?? [] as $package) {
                    if (($package['name'] ?? null) === 'statamic/cms') {
                        return ltrim((string) $package['version'], 'v');
                    }
                }

                return '6.0.0';
            }
        });

        // The outpost itself is silenced rather than given a working version: a
        // real HTTP request to statamic.com from a test run would be worse than
        // none, and nothing here asserts anything about licensing.
        $app->singleton(Outpost::class, fn () => new class extends Outpost
        {
            public function __construct() {}

            public function radio() {}

            public function response()
            {
                return [];
            }
        });

        // Same reason, one middleware further along: CountUsers throws
        // "Statamic Pro is required for multiple users" on the Solo edition, and
        // several tests here create a second user to check a permission. Nothing
        // in this addon behaves differently by edition.
        $app['config']->set('statamic.editions.pro', true);

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
