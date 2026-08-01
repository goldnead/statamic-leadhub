<?php

/**
 * Every CP write route refuses a user who holds no LeadHub permission.
 *
 * The individual CRUD test classes each cover their own screen, which means a
 * route added without a guard is only caught if somebody remembers to extend
 * the right file. This test does not enumerate routes by hand: it walks the
 * router, picks every POST/PATCH/PUT/DELETE route in the `leadhub.` namespace
 * and asserts each one answers 403 for an authenticated CP user with no
 * LeadHub permissions. A new unguarded write route fails here on the day it is
 * added.
 *
 * All feature flags are switched on so a 404 from a feature gate cannot mask a
 * missing permission check.
 */

use Illuminate\Support\Facades\Route;
use Statamic\Facades\User;

beforeEach(function (): void {
    foreach (array_keys(config('leadhub.features', [])) as $feature) {
        config()->set("leadhub.features.{$feature}", true);
    }

    $plain = User::make()->email('cp-write-nobody@example.com');
    $plain->save();
    $this->actingAs($plain);
});

/** @return array<int, array{name: string, method: string, uri: string}> */
function leadhubWriteRoutes(): array
{
    $writes = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        // Statamic prefixes CP route names with `statamic.cp.`, so the group's
        // own `leadhub.` prefix sits in the middle of the registered name.
        if (! $name || ! str_contains($name, 'leadhub.')) {
            continue;
        }

        $methods = array_intersect($route->methods(), ['POST', 'PATCH', 'PUT', 'DELETE']);

        if ($methods === []) {
            continue;
        }

        $writes[] = [
            'name' => $name,
            'method' => strtolower(reset($methods)),
            'uri' => $route->uri(),
        ];
    }

    return $writes;
}

/**
 * Fill route parameters with a value the controller will accept syntactically.
 *
 * `whereNumber()` constraints make a non-numeric placeholder 404 before the
 * controller runs, which would silently turn a missing guard into a pass.
 */
function leadhubWriteUrl(array $route): string
{
    $uri = preg_replace('/\{[a-zA-Z_]*[Hh]andle\??\}/', 'contact-form', $route['uri']);

    return '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $uri), '/');
}

it('has write routes to check', function (): void {
    // A floor, not the exact count: if the discovery above silently stops
    // matching (a renamed group, a changed Statamic CP name prefix) the sweep
    // below would pass over an empty list and prove nothing.
    expect(count(leadhubWriteRoutes()))->toBeGreaterThanOrEqual(30);
});

it('refuses every CP write route for a user with no LeadHub permission', function (): void {
    $allowed = [];

    foreach (leadhubWriteRoutes() as $route) {
        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->call($route['method'], leadhubWriteUrl($route));

        if ($response->getStatusCode() !== 403) {
            $allowed[] = sprintf(
                '%s (%s %s) answered %d, expected 403',
                $route['name'],
                strtoupper($route['method']),
                leadhubWriteUrl($route),
                $response->getStatusCode()
            );
        }
    }

    expect($allowed)->toBe([], "Unguarded CP write route(s):\n".implode("\n", $allowed));
});

it('refuses the CSV export for a user without the export permission', function (): void {
    // Called out separately: an export is the route through which contact data
    // leaves the install, and it is the one write route with no FormRequest of
    // its own to carry the guard.
    $this->post(cp_route('leadhub.export'))->assertStatus(403);
});
