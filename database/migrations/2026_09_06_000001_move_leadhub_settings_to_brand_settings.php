<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the Control Panel settings from this addon's own table into the suite's
 * shared one.
 *
 * `leadhub_settings` was `key`/`value`, one row per changed key, with no brand
 * column. `brand_settings` is the same pair plus `namespace` and `brand_id`, and
 * `Support\Settings` now only declares the field list — the store, the screen and
 * the config override live in `goldnead/statamic-brand-context`. Without this
 * migration every setting an operator changed would silently revert to
 * `config/leadhub.php` on upgrade: the rows would still be there, and nothing
 * would read them.
 *
 * **Every row lands on the default brand.** An un-branded row says nothing about
 * which brand meant it, and the default brand is the only brand that certainly
 * existed when it was written — a single-brand install has exactly one, and on a
 * multi-brand install the value was in force for all of them, so putting it on
 * the default is the reading that changes the fewest brands. Spreading it across
 * every brand would invent a stored value for brands that never had one, and
 * those rows would then stop following a future package default.
 *
 * **The old table is not dropped.** It stays for one minor version. A site that
 * rolls back to the previous release reads `leadhub_settings` again, and a
 * dropped table means every setting is gone rather than merely stale — the one
 * failure mode a rollback exists to avoid. Dropping it is a separate migration
 * in a later release, once no supported version reads it any more.
 *
 * **Idempotent.** A key already present for the default brand is left alone
 * rather than overwritten: on a re-run the row in `brand_settings` is the newer
 * one — it is what the live screen has been writing since the upgrade — and the
 * copy in the frozen old table is the older. Overwriting would hand the operator
 * back a value they changed after migrating.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leadhub_settings') || ! Schema::hasTable('brand_settings')) {
            return;
        }

        $brandId = $this->defaultBrandId();

        // No brand, no row that can own a value. Silently inventing a brand row
        // here would put settings on a record the rest of the suite does not
        // consider the default, so the old table simply stays untouched and a
        // later run of this migration (after brand-context has seeded a brand)
        // does the move.
        if ($brandId === null) {
            return;
        }

        $existing = DB::table('brand_settings')
            ->where('brand_id', $brandId)
            ->where('namespace', 'leadhub')
            ->pluck('key')
            ->all();

        $existing = array_flip($existing);
        $now = now();
        $rows = [];

        foreach (DB::table('leadhub_settings')->get(['key', 'value']) as $setting) {
            if (isset($existing[$setting->key])) {
                continue;
            }

            $rows[] = [
                'brand_id' => $brandId,
                'namespace' => 'leadhub',
                'key' => $setting->key,
                // Copied as the stored string, not decoded and re-encoded. Both
                // columns are JSON and both sides cast with `json`, so passing
                // the raw text through keeps `false`, `0`, `null` and `[]` as
                // what they were — which is the whole reason the value is JSON
                // rather than text in either table.
                'value' => $setting->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('brand_settings')->insert($rows);
        }
    }

    /**
     * Take back exactly what up() could have put there: the default brand's
     * `leadhub` rows for keys the old table still holds. Narrower than "every
     * leadhub row" on purpose — a key added on the shared screen after the
     * upgrade was never in `leadhub_settings`, so it is not this migration's to
     * remove.
     */
    public function down(): void
    {
        if (! Schema::hasTable('leadhub_settings') || ! Schema::hasTable('brand_settings')) {
            return;
        }

        $brandId = $this->defaultBrandId();

        if ($brandId === null) {
            return;
        }

        $keys = DB::table('leadhub_settings')->pluck('key')->all();

        if ($keys === []) {
            return;
        }

        DB::table('brand_settings')
            ->where('brand_id', $brandId)
            ->where('namespace', 'leadhub')
            ->whereIn('key', $keys)
            ->delete();
    }

    /**
     * The default brand, resolved the way Goldnead\BrandContext\Models\Brand
     * does it: the configured handle first, the `is_default` flag second.
     * Read here rather than through the model so this migration does not depend
     * on a class that a later release of brand-context may move.
     */
    private function defaultBrandId(): ?int
    {
        if (! Schema::hasTable('brands')) {
            return null;
        }

        $id = DB::table('brands')
            ->where('handle', config('brand-context.default_handle', 'default'))
            ->value('id')
            ?? DB::table('brands')->where('is_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }
};
