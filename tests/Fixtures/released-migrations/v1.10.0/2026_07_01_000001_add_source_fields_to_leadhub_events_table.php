<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leadhub_events', function (Blueprint $table) {
            // Polymorphic origin of the event, so non-form sources (purchases,
            // bookings, logins, inbound webhooks) can project into the timeline.
            $table->string('source_type')->nullable()->after('actor_id')->index();
            $table->string('source_id')->nullable()->after('source_type');

            // Idempotency key — guarantees re-processing/retries of the same
            // source event never create duplicate timeline entries.
            $table->string('dedupe_key')->nullable()->after('source_id')->unique();

            // The real moment the event happened (may differ from created_at,
            // e.g. a back-dated webhook delivery).
            $table->timestamp('occurred_at')->nullable()->after('dedupe_key')->index();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leadhub_events', function (Blueprint $table) {
            // Drop every index referencing these columns before the columns
            // themselves — SQLite refuses to drop an indexed column otherwise.
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropIndex('leadhub_events_source_type_index');
            $table->dropIndex('leadhub_events_occurred_at_index');
            $table->dropUnique('leadhub_events_dedupe_key_unique');
            $table->dropColumn(['source_type', 'source_id', 'dedupe_key', 'occurred_at']);
        });
    }
};
