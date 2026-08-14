<?php

/**
 * The settings screen writes, and what it writes reaches the config.
 *
 * The screen was read-only until now: it printed `config/leadhub.php` and told
 * the operator to go and edit a file on the server. Everything here is about the
 * two properties that make the replacement trustworthy — a saved value is the
 * value the rest of the addon reads, and a value returned to its default stops
 * being stored at all.
 *
 * What must NOT reach the browser is pinned separately and deliberately
 * untouched: tests/Feature/SettingsSecretsTest.php.
 */

use Goldnead\Leadhub\Models\Setting;
use Goldnead\Leadhub\Support\Settings;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('settings-editor@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

/**
 * The form always submits every field, so the rules are `present` and a partial
 * payload is a 422 rather than a silent partial write. Tests that care about one
 * key say so, and this fills in the rest from the config.
 */
function patchLeadhubSettings($test, array $overrides)
{
    $settings = [];

    foreach (array_keys(Settings::fields()) as $key) {
        $settings[$key] = config('leadhub.'.$key);
    }

    return $test->patchJson(
        cp_route('leadhub.settings.update'),
        ['settings' => array_replace($settings, $overrides)],
    );
}

it('stores a changed setting and applies it to the config', function (): void {
    patchLeadhubSettings($this, ['exports.queue_threshold' => 250])->assertRedirect();

    expect(Setting::where('key', 'exports.queue_threshold')->first()?->value)->toBe(250)
        ->and(config('leadhub.exports.queue_threshold'))->toBe(250);
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
    expect(Setting::count())->toBe(1);

    // Not "stores 1000" — stores nothing. A row pinning a value to what it
    // already was would freeze that default across package upgrades.
    patchLeadhubSettings($this, ['exports.queue_threshold' => 1000])->assertRedirect();

    expect(Setting::count())->toBe(0)
        // And the running application has to agree in the same breath. `apply()`
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

    expect(Setting::where('key', 'store_full_submission_payload')->exists())->toBeFalse()
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

    expect(Setting::where('key', 'storage.driver')->exists())->toBeFalse()
        ->and(config('leadhub.storage.driver'))->toBe($driver);
});

it('offers no credential field', function (): void {
    // The CRM destinations carry `token`, `api_key` and `secret`. A form field
    // for one would move it out of the secret store and into a database backup,
    // which is the same leak SettingsSecretsTest closed at the other end.
    foreach (array_keys(Settings::fields()) as $key) {
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

    expect(Setting::count())->toBe(0)
        ->and(config('leadhub.exports.queue_threshold'))->toBe(1000);
});

it('hands the page the form definition and the current values', function (): void {
    patchLeadhubSettings($this, ['exports.queue_threshold' => 250])->assertRedirect();

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.settings'))
            ->assertStatus(200)
            ->getContent(),
        true
    )['props'] ?? [];

    expect($props['groups'])->not->toBeEmpty()
        ->and($props['values']['exports.queue_threshold'])->toBe(250)
        ->and($props['canEdit'])->toBeTrue();

    // Every field the form draws has a value handed to it. A field without one
    // renders an empty control that saves an empty value over a good default the
    // first time somebody presses Save.
    foreach ($props['groups'] as $group) {
        foreach ($group['fields'] as $field) {
            expect($props['values'])->toHaveKey($field['key']);
        }
    }
});

it('says so instead of erroring when there is no settings table', function (): void {
    // The flat driver does not require migrations for lead data, so an install
    // that never ran one is a supported state and not a broken one. The screen
    // has to stay readable there: the values are the config file's, and the Save
    // button is gone rather than answering a SQL error.
    Schema::drop('leadhub_settings');

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.settings'))
            ->assertStatus(200)
            ->getContent(),
        true
    )['props'] ?? [];

    expect($props['canEdit'])->toBeFalse()
        ->and($props['values']['exports.queue_threshold'])->toBe(1000);

    patchLeadhubSettings($this, ['exports.queue_threshold' => 250])->assertRedirect();

    expect(config('leadhub.exports.queue_threshold'))->toBe(1000);
});

it('applies stored settings on a fresh boot', function (): void {
    Setting::create(['key' => 'features.tasks', 'value' => false]);

    // The overrides are read once and cached; a queue worker booting later must
    // still see them, which is the whole reason apply() runs in bootAddon rather
    // than in a Control-Panel middleware.
    app(Settings::class)->forget();
    app(Settings::class)->apply();

    expect(config('leadhub.features.tasks'))->toBeFalse();
});

it('refuses to let a stale row set a config path the definition does not offer', function (): void {
    // A row left behind by an older release must not be able to reach an
    // arbitrary config key — `storage.driver` and the CRM credentials are one
    // string away otherwise.
    $driver = config('leadhub.storage.driver');

    Setting::create(['key' => 'crm.destinations', 'value' => ['hacked' => true]]);
    Setting::create(['key' => 'storage.driver', 'value' => 'invented']);

    app(Settings::class)->forget();
    app(Settings::class)->apply();

    expect(config('leadhub.storage.driver'))->toBe($driver)
        ->and(config('leadhub.crm.destinations'))->not->toHaveKey('hacked');
});

it('does not bake overrides into a cached config', function (): void {
    // `config:cache` boots the app and dumps the resolved config to disk. An
    // override written into that dump outlives the row it came from: deleting
    // the setting afterwards has no effect at all until somebody runs
    // `config:clear`. It also poisons the "back to default" rule — the next
    // boot reads the baked file as the packaged default, so a value reset to
    // the file's own default is stored as a row instead of being deleted, and
    // that key is then stuck for good.
    Setting::create(['key' => 'exports.queue_threshold', 'value' => 9999]);

    $packaged = config('leadhub.exports.queue_threshold');

    $settings = app(Settings::class);
    $settings->forget();

    // What the config-cache build looks like from in here.
    $argv = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['artisan', 'config:cache'];

    try {
        $settings->apply();
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect(config('leadhub.exports.queue_threshold'))->toBe($packaged);
});

it('writes the whole form or none of it', function (): void {
    // A failure halfway through leaves a table matching no coherent state, and
    // the screen is then re-rendered from that half-written state as though it
    // were the truth.
    $before = Setting::count();

    expect(fn () => app(Settings::class)->save([
        'exports.queue_threshold' => 2500,
        // A resource has no JSON representation, so the cast throws on the way
        // in. Any mid-write failure would do; this one needs no fixture and no
        // mock, and it happens on the second key, after the first was written.
        // (A closure does not work here — it encodes to `{}` without an error.)
        'exports.directory' => fopen('php://memory', 'r'),
    ]))->toThrow(JsonEncodingException::class);

    expect(Setting::count())->toBe($before);
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

        foreach (Settings::groups() as $group) {
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
