<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1 Brand-Scoping for LeadHub.
 *
 * Adds a `brand_id` to every stateful LeadHub table so the brand-context
 * foundation (goldnead/statamic-brand-context) can isolate data per brand.
 *
 * - Root tables carry brand_id as their own scoping column.
 * - Child/pivot tables carry brand_id DENORMALIZED (query-time defense: every
 *   read filters on brand_id instead of trusting a join not to be forgotten).
 * - All existing rows are backfilled onto the default brand ("bestand = default").
 * - Business-identifier uniques are reworked to `(brand_id, …)` so the same
 *   handle/slug/email/dedupe-key may exist independently in different brands.
 *
 * Schema is identical in single- and multi-brand mode; enabling multi-brand
 * later needs no further migration.
 */
return new class extends Migration
{
    /** Every stateful LeadHub table gets a brand_id. */
    private array $tables = [
        'leadhub_contacts',
        'leadhub_tags',
        'leadhub_contact_tag',
        'leadhub_events',
        'leadhub_notes',
        'leadhub_followups',
        'leadhub_form_mappings',
        'leadhub_sync_logs',
        'leadhub_companies',
        'leadhub_contact_company',
        'leadhub_tasks',
        'leadhub_pipelines',
        'leadhub_stages',
        'leadhub_opportunities',
        'leadhub_stage_transitions',
        'leadhub_segments',
        'leadhub_segment_contact',
    ];

    /**
     * Child table => [parent table, foreign key on the child].
     * Used to backfill the denormalized brand_id from the parent row.
     */
    private array $children = [
        'leadhub_contact_tag' => ['leadhub_contacts', 'contact_id'],
        'leadhub_events' => ['leadhub_contacts', 'contact_id'],
        'leadhub_notes' => ['leadhub_contacts', 'contact_id'],
        'leadhub_followups' => ['leadhub_contacts', 'contact_id'],
        'leadhub_contact_company' => ['leadhub_contacts', 'contact_id'],
        'leadhub_tasks' => ['leadhub_contacts', 'contact_id'],
        'leadhub_stages' => ['leadhub_pipelines', 'pipeline_id'],
        'leadhub_opportunities' => ['leadhub_contacts', 'contact_id'],
        'leadhub_stage_transitions' => ['leadhub_opportunities', 'opportunity_id'],
        'leadhub_segment_contact' => ['leadhub_segments', 'segment_id'],
    ];

    public function up(): void
    {
        // 1. Add nullable brand_id + index everywhere.
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('brand_id')->nullable()->index();
            });
        }

        // 2. Backfill. Existing data belongs to the default brand.
        $defaultId = DB::table('brands')->where('is_default', true)->value('id')
            ?? DB::table('brands')->min('id');

        if ($defaultId !== null) {
            // Baseline: guarantee every row (incl. orphan children / null-FK tasks)
            // is stamped, then refine children from their parent.
            foreach ($this->tables as $name) {
                DB::table($name)->whereNull('brand_id')->update(['brand_id' => $defaultId]);
            }

            foreach ($this->children as $child => [$parent, $fk]) {
                DB::statement(
                    "UPDATE {$child} SET brand_id = COALESCE("
                    ."(SELECT p.brand_id FROM {$parent} p WHERE p.id = {$child}.{$fk}), brand_id)"
                );
            }
        }

        // 3. Unique reworks — business identifiers become brand-scoped.
        Schema::table('leadhub_contacts', function (Blueprint $table): void {
            // email_normalized was index-only; dedup is now enforced per brand.
            $table->dropIndex('leadhub_contacts_email_normalized_index');
            $table->unique(['brand_id', 'email_normalized']);
        });

        Schema::table('leadhub_tags', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->unique(['brand_id', 'slug']);
        });

        Schema::table('leadhub_events', function (Blueprint $table): void {
            $table->dropUnique('leadhub_events_dedupe_key_unique');
            $table->unique(['brand_id', 'dedupe_key']);
        });

        Schema::table('leadhub_form_mappings', function (Blueprint $table): void {
            $table->dropUnique(['form_handle']);
            $table->unique(['brand_id', 'form_handle']);
        });

        Schema::table('leadhub_pipelines', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->unique(['brand_id', 'slug']);
        });

        Schema::table('leadhub_segments', function (Blueprint $table): void {
            $table->dropUnique(['handle']);
            $table->unique(['brand_id', 'handle']);
        });
    }

    public function down(): void
    {
        // Reverse the unique reworks.
        Schema::table('leadhub_contacts', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'email_normalized']);
            $table->index('email_normalized');
        });

        Schema::table('leadhub_tags', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'slug']);
            $table->unique('slug');
        });

        Schema::table('leadhub_events', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'dedupe_key']);
            $table->unique('dedupe_key');
        });

        Schema::table('leadhub_form_mappings', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'form_handle']);
            $table->unique('form_handle');
        });

        Schema::table('leadhub_pipelines', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'slug']);
            $table->unique('slug');
        });

        Schema::table('leadhub_segments', function (Blueprint $table): void {
            $table->dropUnique(['brand_id', 'handle']);
            $table->unique('handle');
        });

        // Drop brand_id everywhere.
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_brand_id_index');
                $table->dropColumn('brand_id');
            });
        }
    }
};
