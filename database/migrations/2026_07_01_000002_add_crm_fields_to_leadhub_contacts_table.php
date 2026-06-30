<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            // Phone-based deduplication, mirroring email_normalized.
            $table->string('phone_normalized')->nullable()->after('phone')->index();

            // Lightweight lead scoring.
            $table->integer('engagement_score')->default(0)->after('status')->index();

            // Last time the contact was seen active (login, visit, …).
            $table->timestamp('last_seen_at')->nullable()->after('last_activity_at');

            // Compliance / opt-out switch honoured by all CRM connectors.
            $table->boolean('do_not_contact')->default(false)->after('consent_at')->index();

            // Free-form custom fields.
            $table->json('metadata_json')->nullable()->after('do_not_contact');

            // Link to the host app's authenticated user, when the contact has
            // an account. String to support UUID/Statamic user ids.
            $table->string('user_id')->nullable()->after('assigned_to')->index();

            // Contact merge — the surviving record this one was merged into.
            $table->unsignedBigInteger('merged_into_contact_id')->nullable()->after('user_id');
            $table->index('merged_into_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            $table->dropIndex('leadhub_contacts_phone_normalized_index');
            $table->dropIndex('leadhub_contacts_engagement_score_index');
            $table->dropIndex('leadhub_contacts_do_not_contact_index');
            $table->dropIndex('leadhub_contacts_user_id_index');
            $table->dropIndex('leadhub_contacts_merged_into_contact_id_index');
            $table->dropColumn([
                'phone_normalized',
                'engagement_score',
                'last_seen_at',
                'do_not_contact',
                'metadata_json',
                'user_id',
                'merged_into_contact_id',
            ]);
        });
    }
};
