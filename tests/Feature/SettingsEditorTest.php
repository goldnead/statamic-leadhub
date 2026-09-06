<?php

/**
 * LeadHub's settings, now that the screen behind them is the suite's shared one.
 *
 * Nothing about the *properties* changed: a saved value is the value the rest of
 * the addon reads, and a value returned to its default stops being stored at
 * all. What changed is who owns them. `Support\Settings` is a field list
 * implementing `ProvidesSettings`; the store is `brand_settings`, the form is
 * `brand-context::Settings`, and the endpoint takes a namespace.
 *
 * These tests deliberately still go through HTTP rather than calling the manager
 * directly. The thing worth pinning from this side is that LeadHub is actually
 * *registered* — a contract implemented and never announced is a settings screen
 * with no LeadHub section on it, and every assertion below would still pass
 * against the manager.
 *
 * What must NOT reach the browser is pinned separately: SettingsSecretsTest.
 */

use Goldnead\BrandContext\Models\BrandSetting;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Leadhub\Support\Settings;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('settings-editor@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

/** Every field LeadHub offers, as the registry sees it. */
function leadhubSettingFields(): array
{
    return app(SettingsRegistry::class)->fields('leadhub');
}

/**
 * The form always submits every field of the section it saves, so the rules are
 * `present` and a partial payload is a 422 rather than a silent partial write.
 * Tests that care about one key say so, and this fills in the rest from config.
 */
function patchLeadhubSettings($test, array $overrides)
{
    $settings = [];

    foreach (array_keys(leadhubSettingFields()) as $key) {
        $settings[$key] = config('leadhub.'.$key);
    }

    return $test->patchJson(cp_route('brand-context.settings.update'), [
        'namespace' => 'leadhub',
        'settings' => array_replace($settings, $overrides),
    ]);
}

/** The stored override for one key, or null when there is none. */
function leadhubStoredSetting(string $key): ?BrandSetting
{
    return BrandSetting::query()->where('namespace', 'leadhub')->where('key', $key)->first();
}

function leadhubStoredSettingCount(): int
{
    return BrandSetting::query()->where('namespace', 'leadhub')->count();
}

it('registers LeadHub with the suite settings registry', function (): void {
    // The one thing no other assertion here would catch. Everything else works
    // against the manager, which does not care who called register(); a contract
    // implemented and never announced is a shared screen with no LeadHub on it.
    $registry = app(SettingsRegistry::class);

    expect($registry->has('leadhub'))->toBeTrue()
        ->and($registry->provider('leadhub'))->toBe(Settings::class)
        ->and($registry->configPath('leadhub'))->toBe('leadhub')
        // Not derived from the namespace. `manage leadhub settings` is assigned
        // to user groups on installed sites and also gates the custom fields and
        // pipeline screens; a derived name would take all three away silently.
        ->and($registry->permission('leadhub'))->toBe('manage leadhub settings');
});

it('stores a changed setting and applies it to the config', function (): void {
    patchLeadhubSettings($this, ['exports.queue_threshold' => 250])->assertRedirect();

    expect(leadhubStoredSetting('exports.queue_threshold')?->value)->toBe(250)
        ->and(config('leadhub.exports.queue_threshold'))->toBe(250);
});

it('stamps the stored row with the namespace and the current brand', function (): void {
    // The defect this whole move exists to fix: `leadhub_settings` had no brand
    // column, so two brands on one install shared a row.
    patchLeadhubSettings($this, ['exports.queue_threshold' => 250])->assertRedirect();

    $row = leadhubStoredSetting('exports.queue_threshold');

    expect($row->namespace)->toBe('leadhub')
        ->and($row->brand_id)->toBe(app('brand-context')->currentId());
});

it('coerces a number typed into a text field to an integer', function (): void {
    // HTML controls hand back strings, and the threshold is compared against a
    // contact count. A "250" survives until the first strict comparison and then
    // fails somewhere else entirely — including in the comparison that decides
    // whether a row may be deleted.
    patchLeadhubSettings($this, ['exports.queue_threshold' => '250'])->assertRedirect();

    expect(config('leadhub.exports.queue_threshold'))->toBe(250);
});

it('deletes the override when a value goes back to the default', function (): void {
    patchLeadhubSettings($this, ['exports.queue_threshold' => 250])->assertRedirect();
    expect(leadhubStoredSettingCount())->toBe(1);

    // Not "stores 1000" — stores nothing. A row pinning a value to what it
    // already was would freeze that default across package upgrades.
    patchLeadhubSettings($this, ['exports.queue_threshold' => 1000])->assertRedirect();

    expect(leadhubStoredSettingCount())->toBe(0)
        // And the running application has to agree in the same breath. apply()
        // only writes the overrides that exist, so a deleted one would otherwise
        // leave the old value standing until the next boot: the row gone, the
        // screen saying "default", and every reader still getting 250.
        ->and(config('leadhub.exports.queue_threshold'))->toBe(1000);
});

it('deletes a boolean override that goes back to the default in the same process', function (): void {
    // Same property as above for the type most of this screen is made of. `false`
    // back to `false` has to remove the row, and `config()` has to still say
    // `false` afterwards rather than the `true` that was standing a moment ago.
    patchLeadhubSettings($this, ['store_full_submission_payload' => false])->assertRedirect();
    expect(config('leadhub.store_full_submission_payload'))->toBeFalse();

    patchLeadhubSettings($this, ['store_full_submission_payload' => true])->assertRedirect();

    expect(leadhubStoredSetting('store_full_submission_payload'))->toBeNull()
        ->and(config('leadhub.store_full_submission_payload'))->toBeTrue();
});

it('saves a list setting as a trimmed compacted list', function (): void {
    patchLeadhubSettings($this, [
        'timeline_payload_redaction' => ['password', ' iban ', ''],
    ])->assertRedirect();

    // The control is a textarea of lines, and a trailing newline must not become
    // a redaction rule for the empty field name.
    expect(config('leadhub.timeline_payload_redaction'))->toBe(['password', 'iban']);
});

it('refuses an export threshold of zero', function (): void {
    $response = patchLeadhubSettings($this, ['exports.queue_threshold' => 0])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('settings.exports.queue_threshold')
        ->and(config('leadhub.exports.queue_threshold'))->toBe(1000);
});

it('refuses a default status that is not one of the offered ones', function (): void {
    // A status outside `leadhub.statuses` is a contact the Control Panel cannot
    // name, on every lead captured from then on.
    $response = patchLeadhubSettings($this, ['default_status' => 'invented'])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('settings.default_status')
        ->and(config('leadhub.default_status'))->toBe('new');
});

it('allows a dedupe window of zero because that means score every click', function (): void {
    patchLeadhubSettings($this, ['click_tracking.dedupe_window' => 0])->assertRedirect();

    expect(config('leadhub.click_tracking.dedupe_window'))->toBe(0);
});

it('ignores a key the settings definition does not offer', function (): void {
    // `storage.driver` decides where contacts, events and notes live and is
    // switched with `leadhub:storage:migrate`, not through this form. A row for
    // it must not be creatable through this endpoint, and must not reach
    // `config()` even if one somehow existed.
    $driver = config('leadhub.storage.driver');

    patchLeadhubSettings($this, ['storage.driver' => 'invented'])->assertRedirect();

    expect(leadhubStoredSetting('storage.driver'))->toBeNull()
        ->and(config('leadhub.storage.driver'))->toBe($driver);
});

it('offers no credential field', function (): void {
    // The CRM destinations carry `token`, `api_key` and `secret`. A form field
    // for one would move it out of the secret store and into a database backup,
    // which is the same leak SettingsSecretsTest closed at the other end.
    foreach (array_keys(leadhubSettingFields()) as $key) {
        expect($key)->not->toStartWith('crm.');

        foreach (['token', 'secret', 'api_key', 'password'] as $needle) {
            expect(str_contains(strtolower($key), $needle))->toBeFalse(
                "Settings offers a credential field: {$key}"
            );
        }
    }
});

it('refuses the write without the manage permission', function (): void {
    $plain = User::make()->email('settings-editor-nobody@example.com');
    $plain->save();
    $this->actingAs($plain);

    patchLeadhubSettings($this, ['exports.queue_threshold' => 250])->assertStatus(403);

    expect(leadhubStoredSettingCount())->toBe(0)
        ->and(config('leadhub.exports.queue_threshold'))->toBe(1000);
});

it('hands the shared screen a LeadHub section with the form definition and the current values', function (): void {
    patchLeadhubSettings($this, ['exports.queue_threshold' => 250])->assertRedirect();

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('brand-context.settings.index'))
            ->assertStatus(200)
            ->getContent(),
        true
    )['props'] ?? [];

    $section = collect($props['sections'] ?? [])->firstWhere('namespace', 'leadhub');

    expect($section)->not->toBeNull()
        ->and($section['config_path'])->toBe('leadhub')
        ->and($section['groups'])->not->toBeEmpty()
        ->and($section['values']['exports.queue_threshold'])->toBe(250);

    // Every field the form draws has a value handed to it. A field without one
    // renders an empty control that saves an empty value over a good default the
    // first time somebody presses Save.
    foreach ($section['groups'] as $group) {
        foreach ($group['fields'] as $field) {
            expect($section['values'])->toHaveKey($field['key']);
        }
    }
});

it('redirects the old settings URL to the shared screen', function (): void {
    // `/cp/leadhub/settings` has been the settings URL since 1.0 and is in
    // bookmarks and in this addon's own documentation. A 404 there reads as
    // "the settings are gone", which is the wrong thing to tell an operator
    // whose settings were just migrated.
    $this->get(cp_route('leadhub.settings'))
        ->assertRedirect(cp_route('brand-context.settings.index'));
});

it('still reads the config when the shared settings table is missing', function (): void {
    // The flat driver does not require migrations for lead data, so an install
    // that never ran one is a supported state and not a broken one. Reading has
    // to survive it: no rows means the config file, which is exactly the
    // behaviour before any settings screen existed.
    Schema::drop('brand_settings');

    app('brand-context.settings')->forget('leadhub');

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('brand-context.settings.index'))
            ->assertStatus(200)
            ->getContent(),
        true
    )['props'] ?? [];

    $section = collect($props['sections'] ?? [])->firstWhere('namespace', 'leadhub');

    expect($section['values']['exports.queue_threshold'])->toBe(1000);

    // And the screen says so rather than offering a Save that cannot work.
    // brand-context gained `writable` on 06.09.2026 for exactly this state.
    expect($props['writable'])->toBeFalse();
});

it('applies stored settings on a fresh boot', function (): void {
    BrandSetting::query()->create([
        'brand_id' => app('brand-context')->currentId(),
        'namespace' => 'leadhub',
        'key' => 'features.tasks',
        'value' => false,
    ]);

    // The overrides are read once and cached; a queue worker booting later must
    // still see them, which is why apply() runs from the provider's booted
    // callback rather than from a Control-Panel middleware.
    $settings = app('brand-context.settings');
    $settings->forget('leadhub');
    $settings->apply(force: true);

    expect(config('leadhub.features.tasks'))->toBeFalse();
});

it('refuses to let a stale row set a config path the definition does not offer', function (): void {
    // A row left behind by an older release must not be able to reach an
    // arbitrary config key — `storage.driver` and the CRM credentials are one
    // string away otherwise.
    $driver = config('leadhub.storage.driver');
    $brandId = app('brand-context')->currentId();

    BrandSetting::query()->create([
        'brand_id' => $brandId,
        'namespace' => 'leadhub',
        'key' => 'crm.destinations',
        'value' => ['hacked' => true],
    ]);
    BrandSetting::query()->create([
        'brand_id' => $brandId,
        'namespace' => 'leadhub',
        'key' => 'storage.driver',
        'value' => 'invented',
    ]);

    $settings = app('brand-context.settings');
    $settings->forget('leadhub');
    $settings->apply(force: true);

    expect(config('leadhub.storage.driver'))->toBe($driver)
        ->and(config('leadhub.crm.destinations'))->not->toHaveKey('hacked');
});

it('translates every group, field and option in both languages', function (): void {
    // `Settings::field()` flattens `exports.queue_threshold` to the lang key
    // `exports_queue_threshold`. A mismatch there is not a fallback and not an
    // empty label: Laravel hands back the key itself, so the raw
    // `leadhub::settings.fields.…label` ends up on screen, in both languages,
    // and the validation message for that field reads the same. The sibling
    // addon shipped exactly that bug through a green suite, because no test
    // ever looked at a label.
    $untranslated = [];

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);

        foreach (Settings::settingsGroups() as $group) {
            $slots = [$group['title'] ?? null, $group['description'] ?? null];

            foreach ($group['fields'] as $field) {
                $slots[] = $field['label'] ?? null;
                $slots[] = $field['description'] ?? null;

                foreach ($field['options'] ?? [] as $option) {
                    $slots[] = $option['label'] ?? null;
                }
            }

            foreach ($slots as $slot) {
                if (is_string($slot) && str_contains($slot, 'leadhub::')) {
                    $untranslated[] = $locale.': '.$slot;
                }
            }
        }
    }

    expect($untranslated)->toBe([]);
});
