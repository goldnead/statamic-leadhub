<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a contact is, coarsely.
 *
 * A real column rather than a custom field, for the same reason the cached
 * revenue totals are columns: a segment over "within 35 km of Cologne" has to
 * be answerable by the database, and `SegmentEvaluator::FIELDS` is a fixed
 * allow-list that custom fields do not enter.
 *
 * `country` sits next to it because a postal code alone is ambiguous — 1070 is
 * Vienna in Austria and nothing in Germany. Default `DE` is a convenience for
 * the common case, not a claim; a contact whose country is unknown is still
 * stored as DE and will simply not match an Austrian radius.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            $table->string('postal_code', 16)->nullable()->after('company');
            $table->string('country', 2)->nullable()->after('postal_code');

            $table->index(['country', 'postal_code'], 'leadhub_contacts_postal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            $table->dropIndex('leadhub_contacts_postal_idx');
            $table->dropColumn(['postal_code', 'country']);
        });
    }
};
