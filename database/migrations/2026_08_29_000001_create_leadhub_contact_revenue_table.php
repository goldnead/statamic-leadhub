<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a contact is worth, as a ledger plus a cached total.
 *
 * LeadHub knew what a person did and never what they paid. The opportunity's
 * `value_estimate` is a human's guess about a deal that has not happened;
 * this is the opposite fact, and the two must not be confused.
 *
 * Deliberately neutral: nothing here names a payment provider, a checkout or a
 * sibling addon. Any contributor with an amount, a currency and a stable
 * reference may write into it. That is the same courtesy the timeline shows —
 * LeadHub requires nobody, and this must not be the column that changes that.
 *
 * Two decisions worth the space:
 *
 * 1. **A ledger, not a counter.** An incremented total cannot be made
 *    idempotent: a webhook delivered twice adds twice, and no later reader can
 *    tell. One row per contributed fact, `reference` unique, makes the second
 *    delivery a no-op that the database enforces rather than the caller
 *    remembering to.
 *
 * 2. **The aggregates are cached, not authoritative.** They exist so a segment
 *    can compare against them and a listing can sort by them — neither is
 *    possible over a relation evaluated in PHP. They are recomputed from the
 *    ledger in a single statement after every write, so they can be wrong only
 *    for the instant between the two, and never drift permanently.
 *
 * A refund is a column on the entry, not a second entry, for the same reason a
 * payment records `refunded_cent` rather than a status: money half returned is
 * one fact with two numbers, and splitting it into two rows makes "how many
 * purchases" ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadhub_contact_revenue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->unsignedBigInteger('contact_id')->index();

            /**
             * The contributor's own stable identity for this fact, namespaced
             * by it — `payments:payment:41`, not `41`. Unique across brands on
             * purpose: two brands cannot own the same payment, and a reference
             * that collides is a bug worth a hard failure rather than a total
             * that is quietly too large.
             */
            $table->string('reference', 191)->unique();

            /** Who contributed it, so a panel can say where a line came from. */
            $table->string('source', 64)->nullable();

            $table->unsignedBigInteger('amount_cent');
            $table->unsignedBigInteger('refunded_cent')->default(0);
            $table->string('currency', 3);
            $table->timestamp('occurred_at')->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'occurred_at'], 'leadhub_revenue_contact_time_index');
        });

        Schema::table('leadhub_contacts', function (Blueprint $table) {
            // Indexed because the point of caching them here is a segment that
            // compares and a listing that sorts. An unindexed cache would be a
            // duplicate with none of the benefit.
            $table->unsignedBigInteger('revenue_cent')->default(0)->after('engagement_score');
            $table->unsignedBigInteger('revenue_refunded_cent')->default(0)->after('revenue_cent');
            $table->unsignedInteger('purchase_count')->default(0)->after('revenue_refunded_cent');
            $table->string('revenue_currency', 3)->nullable()->after('purchase_count');
            $table->timestamp('first_purchase_at')->nullable()->after('revenue_currency');
            $table->timestamp('last_purchase_at')->nullable()->after('first_purchase_at');

            // Named, because the rollback has to drop them by name before it
            // can drop the columns: SQLite refuses to remove a column that an
            // index still mentions, and an auto-generated name is a guess at
            // exactly the moment guessing is expensive.
            $table->index('revenue_cent', 'leadhub_contacts_revenue_index');
            $table->index('purchase_count', 'leadhub_contacts_purchase_count_index');
            $table->index('last_purchase_at', 'leadhub_contacts_last_purchase_index');
        });
    }

    public function down(): void
    {
        Schema::table('leadhub_contacts', function (Blueprint $table) {
            $table->dropIndex('leadhub_contacts_revenue_index');
            $table->dropIndex('leadhub_contacts_purchase_count_index');
            $table->dropIndex('leadhub_contacts_last_purchase_index');

            $table->dropColumn([
                'revenue_cent',
                'revenue_refunded_cent',
                'purchase_count',
                'revenue_currency',
                'first_purchase_at',
                'last_purchase_at',
            ]);
        });

        Schema::dropIfExists('leadhub_contact_revenue');
    }
};
