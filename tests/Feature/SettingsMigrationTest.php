<?php

/**
 * The data migration that carries `leadhub_settings` into `brand_settings`.
 *
 * This is the half of the move that can fail silently. If the rows do not
 * arrive, nothing errors: the new store is simply empty, every setting falls
 * back to `config/leadhub.php`, and the operator finds their choices quietly
 * reverted some time after the upgrade — a feature flag back on, an export disk
 * back to `local`, a redaction list back to the shipped one.
 *
 * The migration is invoked directly rather than through `artisan migrate`. The
 * suite's database is already at head, so it has run once against an empty old
 * table; running its `up()` again over rows put there by hand is the only way to
 * see it actually move something, and it is also the idempotency check.
 */

use Goldnead\BrandContext\Models\BrandSetting;
use Illuminate\Support\Facades\DB;

/** The migration under test, as an object. */
function leadhubSettingsMoveMigration()
{
    return require __DIR__.'/../../database/migrations/2026_09_06_000001_move_leadhub_settings_to_brand_settings.php';
}

/** Put a row in the old table the way the pre-move release would have. */
function writeLegacyLeadhubSetting(string $key, mixed $value): void
{
    DB::table('leadhub_settings')->insert([
        'key' => $key,
        'value' => json_encode($value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function leadhubBrandSettingValue(string $key): mixed
{
    return BrandSetting::query()
        ->where('namespace', 'leadhub')
        ->where('key', $key)
        ->value('value');
}

it('moves a stored setting onto the default brand and the config follows it', function (): void {
    writeLegacyLeadhubSetting('exports.queue_threshold', 250);

    leadhubSettingsMoveMigration()->up();

    $row = BrandSetting::query()
        ->where('namespace', 'leadhub')
        ->where('key', 'exports.queue_threshold')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->brand_id)->toBe(app('brand-context')->currentId())
        ->and($row->value)->toBe(250);

    // The row arriving is not the point; the setting being in force is. The
    // whole addon reads `config('leadhub.…')`, so a migrated row that never
    // reaches the config is a setting the operator can see on the screen and
    // that does nothing.
    $settings = app('brand-context.settings');
    $settings->forget('leadhub');
    $settings->apply(force: true);

    expect(config('leadhub.exports.queue_threshold'))->toBe(250);
});

it('keeps false, zero and the empty list apart from unset', function (): void {
    // The three values most likely to be lost in a copy. `value` is JSON on both
    // sides and is passed through as the stored string precisely so that a
    // `false` does not arrive as `0`, `""` or a one-element array.
    writeLegacyLeadhubSetting('features.tasks', false);
    writeLegacyLeadhubSetting('scoring.default', 0);
    writeLegacyLeadhubSetting('timeline_payload_redaction', []);

    leadhubSettingsMoveMigration()->up();

    expect(leadhubBrandSettingValue('features.tasks'))->toBeFalse()
        ->and(leadhubBrandSettingValue('scoring.default'))->toBe(0)
        ->and(leadhubBrandSettingValue('timeline_payload_redaction'))->toBe([]);
});

it('does not leave the old table behind it', function (): void {
    // Not dropped, not emptied. A site that rolls back to the previous release
    // reads `leadhub_settings` again; an emptied table means every setting is
    // gone rather than merely stale, which is the one failure a rollback exists
    // to avoid.
    writeLegacyLeadhubSetting('exports.queue_threshold', 250);

    leadhubSettingsMoveMigration()->up();

    expect(DB::table('leadhub_settings')->where('key', 'exports.queue_threshold')->value('value'))
        ->not->toBeNull();
});

it('runs twice without duplicating or overwriting', function (): void {
    writeLegacyLeadhubSetting('exports.queue_threshold', 250);

    $migration = leadhubSettingsMoveMigration();
    $migration->up();

    // What the operator changed on the shared screen after the upgrade. On a
    // re-run this is the newer value and the frozen old table holds the older
    // one, so overwriting would hand back a value that was already replaced.
    BrandSetting::query()
        ->where('namespace', 'leadhub')
        ->where('key', 'exports.queue_threshold')
        ->update(['value' => json_encode(400)]);

    $migration->up();

    expect(BrandSetting::query()->where('namespace', 'leadhub')->count())->toBe(1)
        ->and(leadhubBrandSettingValue('exports.queue_threshold'))->toBe(400);
});

it('takes back exactly the rows it moved', function (): void {
    writeLegacyLeadhubSetting('exports.queue_threshold', 250);

    // A key that was never in the old table: added on the shared screen after
    // the upgrade, so it is not this migration's to remove on a rollback.
    BrandSetting::query()->create([
        'brand_id' => app('brand-context')->currentId(),
        'namespace' => 'leadhub',
        'key' => 'features.tasks',
        'value' => false,
    ]);

    // Another addon's row, which a rollback of *this* migration must not touch.
    BrandSetting::query()->create([
        'brand_id' => app('brand-context')->currentId(),
        'namespace' => 'automations',
        'key' => 'exports.queue_threshold',
        'value' => 7,
    ]);

    $migration = leadhubSettingsMoveMigration();
    $migration->up();
    $migration->down();

    expect(leadhubBrandSettingValue('exports.queue_threshold'))->toBeNull()
        ->and(leadhubBrandSettingValue('features.tasks'))->toBeFalse()
        ->and(BrandSetting::query()->where('namespace', 'automations')->count())->toBe(1);
});

it('leaves the old rows alone when there is no brand to put them on', function (): void {
    // An install whose brand-context migrations have not seeded a brand yet.
    // Inventing one here would put settings on a record the rest of the suite
    // does not consider the default; doing nothing lets a later run do the move.
    writeLegacyLeadhubSetting('exports.queue_threshold', 250);

    DB::table('brands')->delete();

    leadhubSettingsMoveMigration()->up();

    expect(BrandSetting::query()->where('namespace', 'leadhub')->count())->toBe(0)
        ->and(DB::table('leadhub_settings')->count())->toBe(1);
});
