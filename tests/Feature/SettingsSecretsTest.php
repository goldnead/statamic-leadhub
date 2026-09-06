<?php

/**
 * No Control Panel screen of this addon may serialize a credential into the page.
 *
 * The original defect: `SettingsController` handed `config('leadhub')` to Inertia
 * as a single prop. `config/leadhub.php` carries `crm.destinations.*` entries
 * whose `token`, `api_key` and `secret` keys are env-backed, so on any install
 * with a CRM connector configured those credentials were serialized into the CP
 * page's JSON payload — visible in the DOM, in devtools and in the browser
 * cache, to every user who could open Settings.
 *
 * That screen is gone: the editable settings are the suite's shared screen in
 * brand-context, which is generated from `Support\Settings::settingsGroups()` and
 * can therefore only ever ship the keys that list offers — pinned from the other
 * side by SettingsEditorTest's "offers no credential field".
 *
 * The read-only environment panel moved with it, onto the dashboard, and that is
 * the part that is still a hand-written allow-list of config values. So the leak
 * this file exists to prevent is now possible in exactly one place, and that is
 * where these tests point. The permission check moved too: the panel carries
 * recipient addresses, and it sat behind `manage leadhub settings` on the screen
 * it came from.
 */

use Statamic\Facades\Role;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('settings-secrets@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.crm.destinations', [
        'hubspot' => [
            'driver' => 'hubspot',
            'enabled' => true,
            'token' => 'pat-na1-SUPERSECRET-TOKEN',
        ],
        'brevo' => [
            'driver' => 'brevo',
            'enabled' => true,
            'api_key' => 'xkeysib-SUPERSECRET-APIKEY',
        ],
        'webhook' => [
            'driver' => 'webhook',
            'enabled' => true,
            'url' => 'https://example.test/hook',
            'secret' => 'whsec-SUPERSECRET-SIGNING-SECRET',
        ],
    ]);
});

function leadhubDashboardResponse($test)
{
    return $test->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.dashboard'));
}

function leadhubDashboardProps($test): array
{
    return json_decode(leadhubDashboardResponse($test)->getContent(), true)['props'] ?? [];
}

it('does not ship CRM credentials to the browser', function (): void {
    $body = leadhubDashboardResponse($this)->assertStatus(200)->getContent();

    expect($body)->not->toContain('SUPERSECRET-TOKEN');
    expect($body)->not->toContain('SUPERSECRET-APIKEY');
    expect($body)->not->toContain('SUPERSECRET-SIGNING-SECRET');
});

it('does not ship the crm config branch at all', function (): void {
    // Not "no secret value found" — no branch. A driver added later with a
    // differently named credential would slip past a value-by-value check.
    expect(leadhubDashboardProps($this))->not->toHaveKey('config');
});

it('ships only the deployment values the environment panel renders', function (): void {
    $environment = leadhubDashboardProps($this)['environment'] ?? [];

    expect(array_column($environment, 'env'))->toEqualCanonicalizing([
        'LEADHUB_DRIVER',
        'LEADHUB_FLAT_PATH',
        'LEADHUB_NOTIFICATIONS',
        'LEADHUB_NOTIFY_EMAILS',
        'LEADHUB_DIGEST_TIME',
        'LEADHUB_DIGEST_EMAILS',
    ]);

    // Every entry is a rendered string, never a config branch handed over whole:
    // a nested array here is how a credential travels without being named.
    foreach ($environment as $entry) {
        expect($entry['value'])->toBeString();
    }
});

it('still renders what the panel needs', function (): void {
    $environment = collect(leadhubDashboardProps($this)['environment'] ?? [])
        ->keyBy('env');

    expect($environment['LEADHUB_DRIVER']['value'])->toBe(config('leadhub.storage.driver', 'eloquent'))
        ->and($environment['LEADHUB_FLAT_PATH']['value'])->toBe((string) config('leadhub.storage.flat.path', ''));
});

it('withholds the environment panel from a user who may see the dashboard but not the settings', function (): void {
    // The panel carries the notification and digest recipient addresses. On the
    // screen it came from, that sat behind `manage leadhub settings`; the
    // dashboard is gated on `view leadhub`, which is a wider set of people. The
    // move must not widen who sees it — so a reader gets the dashboard and an
    // empty panel, not a 403 and not the addresses.
    Role::make('lh-env-reader')->permissions(['access cp', 'view leadhub'])->save();

    $reader = User::make()->email('settings-env-reader@example.com');
    $reader->save();
    $reader->assignRole('lh-env-reader')->save();

    $this->actingAs($reader);

    $props = leadhubDashboardProps($this);

    expect($props['environment'])->toBe([])
        ->and($props['kpis'])->not->toBeEmpty();
});
