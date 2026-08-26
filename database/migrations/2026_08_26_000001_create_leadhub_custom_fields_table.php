<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields a site defines for itself, and the values contacts carry in them.
 *
 * Until now a tag was the only way to record something about a contact that is
 * not their name, email or status — and a tag can only say yes or no. Voice
 * part, choir size, federal state, number of singers: those are values, not
 * switches. Pressed into tags they become `chorgroesse-20-40`,
 * `chorgroesse-40-60`, and a segment nobody can maintain.
 *
 * WHY THE DEFINITIONS ARE A TABLE AND THE VALUES ARE JSON
 * ------------------------------------------------------
 * The definitions go in the Control Panel, not a config file, because the
 * person who needs a new field is the person using the CRM — a field that
 * requires a deploy is a field that never gets added.
 *
 * The values ride as JSON on the contact rather than in a pivot table, because
 * segment membership is evaluated in PHP over each contact
 * (SegmentService::sweepSegment → SegmentEvaluator::matches), not as SQL. A
 * pivot would buy indexed lookups nothing here performs, and cost the flat-file
 * driver a relation it cannot hydrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();

            // The key the value is stored under and the segment builder refers
            // to. Immutable in practice: renaming it would orphan every value
            // already written, so the CP offers no rename.
            $table->string('handle', 64);
            $table->string('label');

            // text | number | select | date | boolean. A string rather than an
            // enum column: the set grows, and a migration to add one is a
            // deploy the site owner cannot do themselves.
            $table->string('type', 16)->default('text');

            // Only for `select`. Kept as a list of {value,label} so a label can
            // change without rewriting every contact that holds the value.
            $table->json('options')->nullable();

            $table->text('instructions')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            // Per brand, because two brands may want the same handle to mean
            // different things — and because everything else here is scoped
            // that way.
            $table->unique(['brand_id', 'handle'], 'leadhub_custom_fields_brand_handle_unique');
        });

        Schema::table('leadhub_contacts', function (Blueprint $table): void {
            $table->json('custom_fields')->nullable()->after('utm_content');
        });
    }

    public function down(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table): void {
            $table->dropColumn('custom_fields');
        });

        Schema::dropIfExists('leadhub_custom_fields');
    }
};
