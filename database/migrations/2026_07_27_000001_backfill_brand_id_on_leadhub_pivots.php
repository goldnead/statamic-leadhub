<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the denormalized pivot `brand_id` usable.
 *
 * 2026_07_24_100000 added `brand_id` to the pivot tables and backfilled the
 * rows that existed at that moment, but nothing stamped it afterwards: every
 * link written since then carries NULL. Now that the relations actually filter
 * on the column (Models\Concerns\ScopesPivotToBrand), those NULL rows would
 * silently disappear from the UI — so they are re-stamped from their parent
 * contact here, once, before the filter goes live.
 */
return new class extends Migration
{
    /** pivot table => [parent table, foreign key on the pivot] */
    private array $pivots = [
        'leadhub_contact_company' => ['leadhub_contacts', 'contact_id'],
        'leadhub_contact_tag' => ['leadhub_contacts', 'contact_id'],
        'leadhub_segment_contact' => ['leadhub_contacts', 'contact_id'],
    ];

    public function up(): void
    {
        foreach ($this->pivots as $pivot => [$parent, $fk]) {
            if (! Schema::hasTable($pivot) || ! Schema::hasColumn($pivot, 'brand_id')) {
                continue;
            }

            DB::statement(
                "UPDATE {$pivot} SET brand_id = ("
                ."SELECT p.brand_id FROM {$parent} p WHERE p.id = {$pivot}.{$fk}"
                .') WHERE brand_id IS NULL'
            );
        }

        // Anything still NULL has no resolvable parent (orphan row). Park it on
        // the default brand rather than leaving it invisible to every query.
        $defaultId = DB::table('brands')->where('is_default', true)->value('id')
            ?? DB::table('brands')->min('id');

        if ($defaultId !== null) {
            foreach (array_keys($this->pivots) as $pivot) {
                if (! Schema::hasTable($pivot) || ! Schema::hasColumn($pivot, 'brand_id')) {
                    continue;
                }

                DB::table($pivot)->whereNull('brand_id')->update(['brand_id' => $defaultId]);
            }
        }
    }

    public function down(): void
    {
        // Data-only backfill: nothing to reverse. The column itself is owned by
        // 2026_07_24_100000.
    }
};
