<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who owns a segment, when it is not the person looking at it.
 *
 * Another addon can create segments for its own purposes — statamic-marketing
 * makes one per concert term, „Konzert: Köln 50667 (30 km)", and pulls its name
 * and rule even on every sync. Edited by hand in the CP, such a segment would
 * look changed and quietly change back. The column lets the owner say so:
 *
 *     { "source": "statamic-marketing", "label": "Serie: …", "url": "https://…" }
 *
 * JSON rather than three columns because nothing queries it; it is read with
 * the segment and shown beside it. Nullable, because almost every segment is
 * nobody's but the editor's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leadhub_segments', 'managed_by')) {
            return;
        }

        Schema::table('leadhub_segments', function (Blueprint $table) {
            $table->json('managed_by')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leadhub_segments', 'managed_by')) {
            return;
        }

        Schema::table('leadhub_segments', function (Blueprint $table) {
            $table->dropColumn('managed_by');
        });
    }
};
