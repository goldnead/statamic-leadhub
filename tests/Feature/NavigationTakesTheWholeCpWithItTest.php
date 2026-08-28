<?php

/**
 * A navigation entry whose route does not exist takes down every Control Panel
 * page, not its own.
 *
 * `NavItem->route()` resolves the name through `cp_route()` at the moment the
 * entry is created, and the navigation is assembled on every CP request. A
 * missing route name therefore does not hide a screen; it throws
 * RouteNotFoundException while the nav is being built, and Statamic's
 * `HandleAuthenticatedInertiaRequests` is in the middleware stack of every
 * page. Collections, users, utilities, the dashboard: all 500.
 *
 * 2.7.0 shipped exactly that. The custom fields nav entry was registered for
 * the eloquent driver, `routes/cp.php` never defined the route, and the
 * addon's own 600-test suite stayed green — because no test in it had ever
 * rendered a Control Panel page that was not a LeadHub page. The addon's
 * controllers answer Inertia directly and never build the nav, so the suite
 * could see a broken LeadHub screen and was blind to a broken Control Panel.
 *
 * The two tests below are the two halves of that lesson. The first reproduces
 * the damage: an unrelated core CP page. The second names the cause and lists
 * it, so a third gap arrives as a filename and a route name rather than as a
 * white screen at a customer.
 */

use Illuminate\Support\Facades\Route;
use Statamic\Facades\User;
use Statamic\Http\Middleware\CP\ContactOutpost;
use Statamic\Version;
use Symfony\Component\Finder\Finder;

beforeEach(function (): void {
    // Rendering a core CP page inside a package test bed needs three props that
    // have nothing to do with this addon. Each one is a fact about testbench,
    // not about LeadHub, and each would otherwise show up as a 500 that looks
    // like the bug under test.
    //
    // 1. Pro: the file user repository keeps its YAML between runs, so the
    //    suite passes Statamic's "more than one user without Pro" gate sooner
    //    or later, depending on what ran before.
    config()->set('statamic.editions.pro', true);

    // 2. The version: `Statamic\Version` reads composer.lock from the
    //    application root, and testbench's application root is a fixture
    //    directory that has none.
    $this->app->instance(Version::class, new class extends Version
    {
        public function get()
        {
            return '6.0.0';
        }
    });

    // 3. The licensing outpost, which would otherwise make an HTTP call.
    $this->withoutMiddleware(ContactOutpost::class);

    $this->actingAs(User::make()->email('nav-sweep@example.com')->makeSuper()->save());
});

/**
 * A Control Panel page that belongs to Statamic, not to LeadHub.
 *
 * The point of asserting on this one and not on a LeadHub screen: the failure
 * being guarded against is not "the custom fields page is broken", it is "the
 * Control Panel is broken". A test that only asked for
 * `leadhub.custom-fields.index` would prove the route resolves and would still
 * have let 2.7.0 out of the door, because the seven pages that actually broke
 * for the customer were contacts, entries and users.
 */
it('serves a control panel page that has nothing to do with this addon', function (array $config): void {
    foreach ($config as $schluessel => $wert) {
        config()->set($schluessel, $wert);
    }

    // The full page, not an Inertia payload. `withoutVite()` is what Statamic's
    // own AddonTestCase does and it removes the only obstacle to rendering the
    // blade root view here, a compiled manifest a package test bed has no
    // reason to own. Worth the extra prop: `HandleAuthenticatedInertiaRequests`
    // splits its shared props, and only the full-page branch also builds
    // `protectedProps()`. The navigation sits in `alwaysProps()` and would be
    // built either way; this way the assertion covers both halves.
    $antwort = $this->withoutVite()->get(cp_route('collections.index'));

    expect($antwort->getStatusCode())->toBe(
        200,
        'die Navigation wird auf jeder CP-Seite gebaut; ein Eintrag ohne Route nimmt sie alle mit'
    );
})->with([
    // The configuration the bug needed. The nav entry for custom fields is
    // registered on this driver and on no other.
    'eloquent, alle Module aus' => [[
        'leadhub.storage.driver' => 'eloquent',
        'leadhub.features.pipelines' => false,
        'leadhub.features.tasks' => false,
        'leadhub.features.companies' => false,
        'leadhub.features.scoring' => false,
    ]],
    // Every conditional entry at once, so a second gap behind a feature flag
    // cannot hide behind a default that happens to be off.
    'eloquent, alle Module an' => [[
        'leadhub.storage.driver' => 'eloquent',
        'leadhub.features.pipelines' => true,
        'leadhub.features.tasks' => true,
        'leadhub.features.companies' => true,
        'leadhub.features.scoring' => true,
    ]],
    // The other side of the driver condition: fewer entries, and the ones that
    // remain still have to resolve.
    'flat' => [[
        'leadhub.storage.driver' => 'flat',
        'leadhub.features.pipelines' => true,
        'leadhub.features.tasks' => true,
        'leadhub.features.companies' => true,
        'leadhub.features.scoring' => true,
    ]],
]);

/**
 * Every LeadHub route name the addon names anywhere in its own source.
 *
 * Both spellings, because both fail on a name that does not resolve, only at
 * different moments: `->route()` on a nav entry throws while the navigation is
 * assembled, which is every CP page, and a bare `cp_route()` in a notification
 * or a console command throws when that notification is sent. The first is an
 * outage, the second is a mail that never arrives. Neither should be found by
 * a customer.
 *
 * Read with a pattern, so the limits are worth naming: it sees the two call
 * spellings and a bare `route()` on the fully qualified name, in `src/` and
 * `resources/`, under either quote. A name assembled by interpolation is
 * invisible to it, which is why the count assertion below sits close to the
 * real number instead of at a token floor.
 *
 * Read statically and not by building the nav, deliberately. Building it only
 * exercises the entries whose condition happens to be true for the
 * configuration under test, and half of these sit behind a feature flag or a
 * driver check. Reading the calls out of the files covers all of them at once,
 * including the combinations nobody thought to configure.
 *
 * @return list<string>
 */
function referencedRouteNames(): array
{
    $namen = [];

    $dateien = Finder::create()
        ->files()
        ->in([__DIR__.'/../../src', __DIR__.'/../../resources'])
        ->name(['*.php', '*.vue', '*.js']);

    foreach ($dateien as $datei) {
        preg_match_all(
            '/(?:->route|cp_route|\broute)\(\s*[\'"](?:statamic\.cp\.)?(leadhub\.[^\'"]+)[\'"]/',
            $datei->getContents(),
            $treffer
        );
        $namen = array_merge($namen, $treffer[1]);
    }

    return array_values(array_unique($namen));
}

it('defines a route for every name it points at', function (): void {
    $namen = referencedRouteNames();

    // A floor close to the actual count, not a token one. At the time of
    // writing the sweep finds 71 names. A floor of 15 would have been passed
    // by a pattern that had silently degraded to a quarter of its matches,
    // which is the failure this assertion exists to catch: an empty or
    // thinned-out list makes the sweep below green while proving nothing.
    expect(count($namen))->toBeGreaterThanOrEqual(65);

    $fehlend = array_values(array_filter(
        $namen,
        // Statamic mounts addon CP routes under its own `statamic.cp.` prefix,
        // which is what `cp_route()` prepends. Asking the router directly says
        // "missing" instead of throwing, so the failure is a list of names.
        fn (string $name): bool => Route::has('statamic.cp.'.$name) === false
    ));

    expect($fehlend)->toBe([], 'diese Routennamen loesen nicht auf; als Navigationseintrag nimmt jeder davon jede CP-Seite mit');
});
