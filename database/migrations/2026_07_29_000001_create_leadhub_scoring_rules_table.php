<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scoring rules move out of config/leadhub.php and into the database, so they
 * can be edited in the Control Panel and — the actual reason for the expensive
 * variant — differ per brand.
 *
 * The table models exactly what `leadhub.scoring` could already express and
 * nothing more: an activity type, the points it awards, and a catch-all for
 * everything not listed (`event_type = '*'`, the old `scoring.default`).
 * `enabled` is the one addition, because a CP needs a way to park a rule
 * without losing it — a disabled rule behaves exactly as if it were absent.
 *
 * Two deliberate schema choices:
 *
 * - `brand_id` is NOT NULL. Every other LeadHub table carries it nullable
 *   because the column was retrofitted onto existing rows; this table is born
 *   after brand-context, so there is no legacy row to accommodate. It matters
 *   for the unique below: a unique index does not constrain NULLs, so a
 *   nullable brand_id would silently enforce nothing for exactly the rows that
 *   have no tenant — the ones where duplicate rules would do the most damage.
 * - `event_type` is varchar(100), not the default 255. Under utf8mb4 that is
 *   400 bytes instead of 1020 in the unique index, and event types are handles
 *   like `purchase.completed`, not prose. See tests/Unit/IndexKeyLengthTest.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_scoring_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('event_type', 100);
            $table->integer('points')->default(0);
            $table->string('label')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // One rule per activity type per brand. 8 + 400 = 408 bytes.
            $table->unique(['brand_id', 'event_type'], 'leadhub_scoring_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_scoring_rules');
    }
};
