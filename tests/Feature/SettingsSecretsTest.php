<?php

/**
 * The settings screen must never serialize a credential into the page.
 *
 * `SettingsController` used to hand `config('leadhub')` to Inertia as a single
 * prop. `config/leadhub.php` carries `crm.destinations.*` entries whose
 * `token`, `api_key` and `secret` keys are env-backed, so on any install with a
 * CRM connector configured those credentials were serialized into the CP page's
 * JSON payload — visible in the DOM, in devtools and in the browser cache, to
 * every user who could open Settings.
 *
 * The screen renders statuses, four behaviour flags, the redaction list and the
 * feature flags. Nothing else. These tests pin that allow-list from both sides:
 * the keys the screen needs are present, and the secrets are gone.
 */

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

function settingsResponse($test)
{
    return $test->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.settings'));
}

it('does not ship CRM credentials to the browser', function (): void {
    $response = settingsResponse($this);

    $response->assertStatus(200);

    $body = $response->getContent();

    expect($body)->not->toContain('SUPERSECRET-TOKEN');
    expect($body)->not->toContain('SUPERSECRET-APIKEY');
    expect($body)->not->toContain('SUPERSECRET-SIGNING-SECRET');
});

it('does not ship the crm config branch at all', function (): void {
    $props = json_decode(settingsResponse($this)->getContent(), true)['props'] ?? [];

    expect($props['config'] ?? [])->not->toHaveKey('crm');
});

it('ships only the keys the settings screen renders', function (): void {
    $props = json_decode(settingsResponse($this)->getContent(), true)['props'] ?? [];

    expect(array_keys($props['config'] ?? []))->toEqualCanonicalizing([
        'statuses',
        'default_status',
        'overwrite_existing_fields_from_submissions',
        'store_full_submission_payload',
        'timeline_payload_redaction',
        'features',
        'exports',
    ]);

    // `exports` is narrowed to the one key the screen shows, not the whole branch.
    expect(array_keys($props['config']['exports'] ?? []))->toBe(['queue_threshold']);
});

it('still renders everything the screen needs', function (): void {
    $props = json_decode(settingsResponse($this)->getContent(), true)['props'] ?? [];

    expect($props['driver'])->toBe(config('leadhub.storage.driver', 'eloquent'));
    expect($props['config']['statuses'])->toBe(config('leadhub.statuses'));
    expect($props['config']['features'])->toBe(config('leadhub.features'));
    expect($props['config']['timeline_payload_redaction'])
        ->toBe(config('leadhub.timeline_payload_redaction'));
    expect($props['config']['default_status'])->toBe(config('leadhub.default_status'));
    expect($props['config']['exports']['queue_threshold'])
        ->toBe(config('leadhub.exports.queue_threshold'));
});

it('refuses the settings screen without the manage permission', function (): void {
    $plain = User::make()->email('settings-nobody@example.com');
    $plain->save();
    $this->actingAs($plain);

    settingsResponse($this)->assertStatus(403);
});
