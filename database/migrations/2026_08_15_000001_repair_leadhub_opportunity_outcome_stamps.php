<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `won_at` / `lost_at` agree with the deal they belong to.
 *
 * StageTransitionService set those two stamps and never cleared them, while it
 * did clear `status`, `outcome` and `closed_at` beside them. Two shapes of
 * contradictory row came out of that:
 *
 * - a deal reopened out of a terminal stage: `status = open`, `outcome = NULL`,
 *   `closed_at = NULL`, and a `won_at` (or `lost_at`) still standing;
 * - a deal moved from Won straight to Lost, or the other way: closed with one
 *   outcome and *both* stamps set.
 *
 * Nothing rendered these columns, so nobody saw it. The deal screen added in
 * v2.4.0 does, and `won_at` is the column a revenue report groups by — a stale
 * one inflates every period it lands in. The service is fixed; this repairs
 * what is already stored.
 *
 * **What can be reconstructed, and what that depends on.** When a deal was won
 * is a fact about a stage change, and every stage change has its own row in
 * `leadhub_stage_transitions` with its `occurred_at` — verified: no path
 * outside `StageTransitionService` ever writes these two stamps, and the
 * column arrived in the same migration as the transitions table, so there is
 * no pre-table era.
 *
 * But reading a won date back out of a transition means joining `to_stage_id`
 * to `leadhub_stages.terminal_outcome`, and both ends of that join can move:
 * `to_stage_id` carries no foreign key on purpose, an empty stage can be
 * deleted, and `terminal_outcome` is editable in the stage manager. For a
 * reopened deal the former Won stage may well be empty, and therefore
 * deletable. So the claim is *conditional*, not absolute.
 *
 * Which is why the old values are parked before they are cleared, in
 * `metadata_json` under `repaired_outcome_stamps`, with the date this ran.
 * That column already exists, it is nothing this migration has to create, and
 * it makes the difference between a claim that is argued and one that is
 * merely believed. `down()` still does nothing — there is no earlier state
 * worth restoring, only the contradiction to put back — but the values are now
 * on the record for anybody who disagrees.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leadhub_opportunities')) {
            return;
        }

        foreach (['won_at', 'lost_at'] as $column) {
            if (! Schema::hasColumn('leadhub_opportunities', $column)) {
                return;
            }
        }

        // Park what is about to be cleared, before clearing it. One pass over
        // the contradictory rows, keyed by id, so a second run finds nothing
        // left to park and stays idempotent.
        $this->park();

        // An open deal carries neither stamp: it has no outcome to stamp.
        DB::table('leadhub_opportunities')
            ->where('status', 'open')
            ->where(fn ($query) => $query->whereNotNull('won_at')->orWhereNotNull('lost_at'))
            ->update(['won_at' => null, 'lost_at' => null]);

        // A closed deal carries exactly the one its outcome names.
        DB::table('leadhub_opportunities')
            ->where('status', 'closed')
            ->where('outcome', 'won')
            ->whereNotNull('lost_at')
            ->update(['lost_at' => null]);

        DB::table('leadhub_opportunities')
            ->where('status', 'closed')
            ->where('outcome', 'lost')
            ->whereNotNull('won_at')
            ->update(['won_at' => null]);

        // Closed into a terminal stage that declares no outcome — rare, but the
        // schema allows it, and then neither stamp applies.
        DB::table('leadhub_opportunities')
            ->where('status', 'closed')
            ->whereNull('outcome')
            ->where(fn ($query) => $query->whereNotNull('won_at')->orWhereNotNull('lost_at'))
            ->update(['won_at' => null, 'lost_at' => null]);
    }

    public function down(): void
    {
        // Deliberately empty: see the class docblock.
    }

    /**
     * Write the stamps this migration is about to clear into `metadata_json`.
     *
     * Read-modify-write per row rather than a single SQL expression: the
     * column is portable JSON across MySQL and SQLite, and the handful of
     * contradictory rows an install has is not worth a driver-specific
     * `JSON_SET`.
     */
    protected function park(): void
    {
        $contradictory = DB::table('leadhub_opportunities')
            ->select('id', 'status', 'outcome', 'won_at', 'lost_at', 'metadata_json')
            ->where(function ($query): void {
                $query->whereNotNull('won_at')->orWhereNotNull('lost_at');
            })
            ->where(function ($query): void {
                $query->where('status', 'open')
                    ->orWhere(function ($closed): void {
                        $closed->where('status', 'closed')->whereNull('outcome');
                    })
                    ->orWhere(function ($won): void {
                        $won->where('outcome', 'won')->whereNotNull('lost_at');
                    })
                    ->orWhere(function ($lost): void {
                        $lost->where('outcome', 'lost')->whereNotNull('won_at');
                    });
            })
            ->get();

        foreach ($contradictory as $row) {
            $metadata = json_decode((string) ($row->metadata_json ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];

            // Never overwrite an earlier parking: whatever the first run of
            // this migration found is the version closest to the original.
            if (isset($metadata['repaired_outcome_stamps'])) {
                continue;
            }

            $metadata['repaired_outcome_stamps'] = array_filter([
                'won_at' => $row->won_at,
                'lost_at' => $row->lost_at,
                'status' => $row->status,
                'outcome' => $row->outcome,
            ], fn ($value) => $value !== null);

            DB::table('leadhub_opportunities')
                ->where('id', $row->id)
                ->update(['metadata_json' => json_encode($metadata)]);
        }
    }
};
