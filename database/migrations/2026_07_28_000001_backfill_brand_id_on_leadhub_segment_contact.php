<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-stamp segment memberships written since the last backfill.
 *
 * 2026_07_27_000001 stamped every pivot row that existed at that moment, but
 * only two of the three pivots started stamping their own rows afterwards:
 * `leadhub_contact_company` and `leadhub_contact_tag` went through
 * Models\Concerns\ScopesPivotToBrand, while `leadhub_segment_contact` kept being
 * written by raw inserts in EloquentSegmentRepository that set no brand at all.
 * Every membership recorded since then carries NULL.
 *
 * Now that those reads filter on the column, a NULL row would vanish from the
 * segment — the member count would drop and the contact would silently leave
 * the segment without anything having changed about it. So the gap is closed
 * once more, immediately before the filter goes live.
 *
 * Idempotent and safe to re-run: it only touches rows that are still NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leadhub_segment_contact')
            || ! Schema::hasColumn('leadhub_segment_contact', 'brand_id')) {
            return;
        }

        // The segment owns the membership, so it decides the brand — same
        // direction the brand-scoping migration used for this table.
        DB::statement(
            'UPDATE leadhub_segment_contact SET brand_id = ('
            .'SELECT s.brand_id FROM leadhub_segments s WHERE s.id = leadhub_segment_contact.segment_id'
            .') WHERE brand_id IS NULL'
        );

        // Rows whose segment is itself unstamped (or gone) would stay invisible
        // to every query. Park them on the default brand instead.
        if (! Schema::hasTable('brands')) {
            return;
        }

        $defaultId = DB::table('brands')->where('is_default', true)->value('id')
            ?? DB::table('brands')->min('id');

        if ($defaultId !== null) {
            DB::table('leadhub_segment_contact')->whereNull('brand_id')->update(['brand_id' => $defaultId]);
        }
    }

    public function down(): void
    {
        // Data-only backfill: nothing to reverse. The column itself is owned by
        // 2026_07_24_100000.
    }
};
