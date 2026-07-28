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
 *
 * ---------------------------------------------------------------------------
 * This migration was published in 1.4.0 and rewritten in 1.10.1. Everything
 * below is written for the fact that it therefore has to be correct for three
 * different databases at once. The pattern is the one
 * `goldnead/statamic-marketing` arrived at in its 1.6.4 for the same class of
 * defect.
 *
 * **Never ran it.** The case the 1.4.0 file was written for, and the only one
 * it was ever tested against — on an *empty* database. On a database with
 * contacts in it, the step below that turns `email_normalized` from a plain
 * index into a unique over `(brand_id, email_normalized)` had no duplicate
 * check of any kind. That column was never unique before 1.4.0: two contacts
 * carrying the same normalised address are ordinary data, not a corrupt state,
 * and any install holding a pair of them aborted here.
 *
 * **Ran it successfully.** Untouched — a recorded migration never runs again.
 * The one thing left open for those installs is `brand_id` nullability, which
 * `2026_07_30_000001` picks up, because it cannot be fixed from inside a file
 * that will not be executed again.
 *
 * **Stopped in the middle of it.** The state that has to be picked back up, and
 * the reason every step below is guarded and re-runnable rather than merely
 * ordered. The abort happens *after* `leadhub_contacts_email_normalized_index`
 * has been dropped, no engine rolls DDL back, and the migration is not recorded
 * as run — so a second `php artisan migrate` re-enters at step 1 and, under the
 * 1.4.0 file, died on `duplicate column name: brand_id`. A different error, from
 * a different step, saying nothing about the index that is actually missing, and
 * the single most likely thing to send whoever reads it looking in the wrong
 * place. `php artisan leadhub:brand-integrity` reports the real state.
 * ---------------------------------------------------------------------------
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

    /**
     * The business-identifier uniques that become brand-scoped.
     *
     * table => [indexes this one supersedes, the new columns, the new name].
     *
     * The names are the ones Laravel generates for these column lists, on
     * purpose: an install that already ran 1.4.0 carries exactly them, so this
     * migration recognises its own previous work and does nothing.
     *
     * @return array<string, array{0: list<string>, 1: list<string>, 2: string}>
     */
    private function uniques(): array
    {
        return [
            // email_normalized was index-only; dedup is now enforced per brand.
            'leadhub_contacts' => [
                ['leadhub_contacts_email_normalized_index'],
                ['brand_id', 'email_normalized'],
                'leadhub_contacts_brand_id_email_normalized_unique',
            ],
            'leadhub_tags' => [
                ['leadhub_tags_slug_unique'],
                ['brand_id', 'slug'],
                'leadhub_tags_brand_id_slug_unique',
            ],
            'leadhub_events' => [
                ['leadhub_events_dedupe_key_unique'],
                ['brand_id', 'dedupe_key'],
                'leadhub_events_brand_id_dedupe_key_unique',
            ],
            'leadhub_form_mappings' => [
                ['leadhub_form_mappings_form_handle_unique'],
                ['brand_id', 'form_handle'],
                'leadhub_form_mappings_brand_id_form_handle_unique',
            ],
            'leadhub_pipelines' => [
                ['leadhub_pipelines_slug_unique'],
                ['brand_id', 'slug'],
                'leadhub_pipelines_brand_id_slug_unique',
            ],
            'leadhub_segments' => [
                ['leadhub_segments_handle_unique'],
                ['brand_id', 'handle'],
                'leadhub_segments_brand_id_handle_unique',
            ],
        ];
    }

    public function up(): void
    {
        // 0. Precondition, checked before anything is altered.
        //
        //    The 1.4.0 file read `brands` at step 2 — after all seventeen
        //    tables had already been altered. On an install where brand-context
        //    was not migrated yet, that threw with the schema half-changed and
        //    the migration unrecorded. There is nothing to gain from getting
        //    that far: if rows need stamping and there is no brand to stamp them
        //    with, say so while the database is still untouched.
        $defaultBrandId = $this->defaultBrandId();

        if ($defaultBrandId === null && $this->hasUnstampedRows()) {
            throw new RuntimeException(
                'LeadHub tables hold rows but there is no brand to assign them to. '
                .'Run the goldnead/statamic-brand-context migrations first, then `php artisan migrate` again. '
                .'Nothing was changed.'
            );
        }

        // 1. Add nullable brand_id + index everywhere.
        //
        //    Per table, and only where it is missing: after an interrupted run
        //    some tables have the column and some do not, and re-adding it is
        //    the `duplicate column` error that hid the real failure.
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'brand_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('brand_id')->nullable()->index();
            });
        }

        // 2. Backfill. Existing data belongs to the default brand.
        if ($defaultBrandId !== null) {
            // Baseline: guarantee every row (incl. orphan children / null-FK tasks)
            // is stamped, then refine children from their parent.
            foreach ($this->tables as $name) {
                if (! Schema::hasTable($name)) {
                    continue;
                }

                DB::table($name)->whereNull('brand_id')->update(['brand_id' => $defaultBrandId]);
            }

            foreach ($this->children as $child => [$parent, $fk]) {
                if (! Schema::hasTable($child) || ! Schema::hasTable($parent)) {
                    continue;
                }

                DB::statement(
                    "UPDATE {$child} SET brand_id = COALESCE("
                    ."(SELECT p.brand_id FROM {$parent} p WHERE p.id = {$child}.{$fk}), brand_id)"
                );
            }
        }

        // 3. Unique reworks — business identifiers become brand-scoped.
        foreach ($this->uniques() as $table => [$drop, $columns, $name]) {
            $this->replaceIndex($table, $drop, $columns, $name);
        }
    }

    public function down(): void
    {
        // Reverse the unique reworks.
        $reversals = [
            'leadhub_contacts' => ['leadhub_contacts_brand_id_email_normalized_unique', ['email_normalized'], 'leadhub_contacts_email_normalized_index', false],
            'leadhub_tags' => ['leadhub_tags_brand_id_slug_unique', ['slug'], 'leadhub_tags_slug_unique', true],
            'leadhub_events' => ['leadhub_events_brand_id_dedupe_key_unique', ['dedupe_key'], 'leadhub_events_dedupe_key_unique', true],
            'leadhub_form_mappings' => ['leadhub_form_mappings_brand_id_form_handle_unique', ['form_handle'], 'leadhub_form_mappings_form_handle_unique', true],
            'leadhub_pipelines' => ['leadhub_pipelines_brand_id_slug_unique', ['slug'], 'leadhub_pipelines_slug_unique', true],
            'leadhub_segments' => ['leadhub_segments_brand_id_handle_unique', ['handle'], 'leadhub_segments_handle_unique', true],
        ];

        foreach ($reversals as $table => [$drop, $columns, $name, $unique]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->replaceIndex($table, [$drop], $columns, $name, $unique);
        }

        // Drop brand_id everywhere.
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'brand_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_brand_id_index');
                $table->dropColumn('brand_id');
            });
        }
    }

    private function defaultBrandId(): ?int
    {
        if (! Schema::hasTable('brands')) {
            return null;
        }

        $id = DB::table('brands')->where('is_default', true)->value('id')
            ?? DB::table('brands')->min('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether any LeadHub table holds a row that still needs a brand.
     *
     * An empty install with no brand-context installed is not an error — there
     * is nothing to stamp, and the columns can go in. An install with contacts
     * in it and nowhere to put them is.
     */
    private function hasUnstampedRows(): bool
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            $query = DB::table($name);

            if (Schema::hasColumn($name, 'brand_id')) {
                $query->whereNull('brand_id');
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swap one index for another, in whatever state the table is in.
     *
     * Drops only indexes that are actually there, does nothing at all if the
     * wanted index already exists over the wanted columns, and refuses — by
     * name, with the offending values — rather than letting the driver fail with
     * a bare integrity error if the rows cannot carry a new unique.
     *
     * @param  list<string>  $drop     indexes superseded by this one
     * @param  list<string>  $columns  the new index
     */
    private function replaceIndex(string $table, array $drop, array $columns, string $name, bool $unique = true): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        // `brand_id` is excluded on purpose: this migration created it a few
        // statements ago, so asking the schema about it is a question no schema
        // probe can answer honestly — and it is precisely the "refer forward to
        // a column that is not there yet" mistake this guard exists to prevent
        // for the *other* columns.
        foreach (array_diff($columns, ['brand_id']) as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $indexes = collect(Schema::getIndexes($table))->keyBy('name');

        $wanted = $indexes->get($name);

        if ($wanted && $wanted['columns'] === $columns && (bool) ($wanted['unique'] ?? false) === $unique) {
            return;
        }

        if ($unique) {
            $this->guardAgainstDuplicates($table, $columns, $name);
        }

        foreach ([...$drop, $name] as $obsolete) {
            if ($indexes->has($obsolete)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($obsolete));
            }
        }

        Schema::table($table, fn (Blueprint $blueprint) => $unique
            ? $blueprint->unique($columns, $name)
            : $blueprint->index($columns, $name));
    }

    /**
     * Stop, and say what is in the way, if the rows cannot carry the unique.
     *
     * This cannot pick a winner and must not delete anything. Two contacts with
     * the same normalised address are two records of one person that a form
     * accepted while the column was a plain index — which it was for every
     * install created before 1.4.0. Which of them is *the* contact, and what
     * happens to the timeline, notes, tasks and opportunities hanging off the
     * other, is a question about people and their history, not about rows.
     *
     * NULL is excluded on purpose. No engine constrains a NULL in a unique, so
     * five hundred contacts without an email address are not a collision and
     * must not be reported as one — `email_normalized` and `dedupe_key` are both
     * nullable here, which is the one place this differs from the marketing
     * original it is modelled on.
     *
     * @param  list<string>  $columns
     */
    private function guardAgainstDuplicates(string $table, array $columns, string $name): void
    {
        $query = DB::table($table)
            ->select($columns)
            ->selectRaw('count(*) as occurrences')
            ->groupBy($columns)
            ->havingRaw('count(*) > 1')
            ->limit(25);

        foreach ($columns as $column) {
            $query->whereNotNull($column);
        }

        $duplicates = $query->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $listed = $duplicates
            ->map(fn ($row) => '  '.collect($columns)
                ->map(fn (string $column) => $column.'='.($row->{$column} ?? 'NULL'))
                ->implode(' ')." (x{$row->occurrences})")
            ->implode(PHP_EOL);

        throw new RuntimeException(
            "Cannot build the unique index [{$name}] on [{$table}]: rows already exist that it would reject."
            .PHP_EOL.$listed.PHP_EOL
            .'Nothing was changed and nothing was removed. On leadhub_contacts this is ordinary data rather '
            .'than a fault: email_normalized was a plain index before 1.4.0, so the same address could be '
            .'recorded twice and often was. Run `php artisan leadhub:brand-integrity` for the full list with '
            .'row ids, names and dates, decide which record is the contact, merge or remove the others, then '
            .'migrate again.'
        );
    }
};
