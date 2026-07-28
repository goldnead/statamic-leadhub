<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `brand_id` NOT NULL on the six tables whose business identifier is
 * unique per brand, so that the six uniques hold what their names claim.
 *
 * Since 1.4.0 those identifiers are scoped: `unique(brand_id, email_normalized)`,
 * `unique(brand_id, slug)`, `unique(brand_id, dedupe_key)`,
 * `unique(brand_id, form_handle)`, `unique(brand_id, handle)`. The column every
 * one of them leads with was added nullable, and a SQL unique does not
 * constrain NULL — on any engine. For a row with no brand_id the index applies
 * to nothing: `email_normalized`, `dedupe_key`, `slug`, `form_handle` and
 * `handle` may repeat freely, without limit, and the contact dedupe, the event
 * idempotency key and the per-form mapping are all unenforced for exactly those
 * rows.
 *
 * The models stamp brand_id on create (`HasBrand`), which is why the hole never
 * opened in ordinary use. It is reachable from everything that writes these
 * tables without going through Eloquent — a raw insert, an upsert, a CSV
 * import, a data fix run from tinker, and in this addon's own history
 * `EloquentSegmentRepository`, which wrote `leadhub_segment_contact` rows with
 * no brand at all until 1.9.0. A constraint that depends on every future writer
 * remembering something is not a constraint.
 *
 * Sibling precedent: `goldnead/statamic-automations` 1.5.4
 * (`2026_07_28_000003`), which found the same class on its `automations` table.
 * The shape is taken from there; the collision handling is not, and the
 * difference is the point — see below.
 *
 * **Which tables.** Only the six that carry a brand-scoped unique. The other
 * eleven keep a nullable, denormalised brand_id: none of them constrains
 * anything with it, and changing a column's nullability on MySQL rebuilds the
 * table with ALGORITHM=COPY. `leadhub_events` is the one uncomfortable entry —
 * it is a timeline log and grows without bound — but its unique *is* the
 * idempotency promise this addon makes to every webhook and import that retries,
 * so it is tightened with the rest and the copy is the price. Expect that ALTER
 * to be the long one on a large install.
 *
 * Idempotent: a no-op where the column is already NOT NULL, which is every
 * table on an install that has run this once and every table on a fresh install
 * from 1.10.1 onward.
 */
return new class extends Migration
{
    /**
     * table => the brand-scoped identifier column, and whether a colliding
     * value may be renamed to make room for the backfill.
     *
     * @return array<string, array{column: string, renameable: bool, prefix: string}>
     */
    private function scoped(): array
    {
        return [
            // A machine handle. Suffixing one is visible, greppable and
            // reversible by hand, which is what automations settled on.
            'leadhub_tags' => ['column' => 'slug', 'renameable' => true, 'prefix' => 'tag'],
            'leadhub_pipelines' => ['column' => 'slug', 'renameable' => true, 'prefix' => 'pipeline'],
            'leadhub_segments' => ['column' => 'handle', 'renameable' => true, 'prefix' => 'segment'],
            // A form_handle points at a Statamic form. Renaming it parks the
            // mapping instead of losing it; the form keeps working through the
            // mapping that kept the handle.
            'leadhub_form_mappings' => ['column' => 'form_handle', 'renameable' => true, 'prefix' => 'form'],

            // Neither of these may be rewritten. An email address is a person's
            // address — changing it to free up an index is a lie about a
            // record, and the merge it stands in for is not a decision a
            // migration gets to make. A dedupe_key is the identity of a source
            // event: rewriting one re-opens the door for the duplicate it
            // exists to keep out.
            'leadhub_contacts' => ['column' => 'email_normalized', 'renameable' => false, 'prefix' => ''],
            'leadhub_events' => ['column' => 'dedupe_key', 'renameable' => false, 'prefix' => ''],
        ];
    }

    public function up(): void
    {
        foreach ($this->scoped() as $table => $definition) {
            $this->tighten($table, $definition['column'], $definition['renameable'], $definition['prefix']);
        }
    }

    /**
     * Loosening the columns back is the honest reversal. Values this migration
     * renamed are not restored: they were never distinguishable from each other
     * in the first place, which is the defect being repaired. Every rename is
     * written to the log so it can be undone by hand.
     */
    public function down(): void
    {
        foreach (array_keys($this->scoped()) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'brand_id')) {
                continue;
            }

            if ($this->brandIdIsNullable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('brand_id')->nullable()->change();
            });
        }
    }

    private function tighten(string $table, string $column, bool $renameable, string $prefix): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'brand_id')) {
            return;
        }

        if (! $this->brandIdIsNullable($table)) {
            return; // Already tightened — fresh install, or this ran before.
        }

        if (DB::table($table)->whereNull('brand_id')->exists()) {
            $defaultId = $this->defaultBrandId();

            if ($defaultId === null) {
                throw new RuntimeException(
                    "[{$table}] holds rows without a brand_id and no brand was found to assign them to. "
                    .'Run the goldnead/statamic-brand-context migrations first.'
                );
            }

            $this->makeRoomFor($table, $column, $defaultId, $renameable, $prefix);

            DB::table($table)->whereNull('brand_id')->update(['brand_id' => $defaultId]);
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('brand_id')->nullable(false)->change();
        });
    }

    /**
     * Clear the way for the backfill, or refuse and say what is in the way.
     *
     * The unique never applied to brand-less rows, so two of them may share an
     * identifier with each other or with a row that already belongs to the
     * default brand. Stamping them all with the same brand_id walks straight
     * into the unique and aborts the migration — with a bare integrity error
     * that names an index and not a record.
     *
     * NULL identifiers are skipped throughout: a unique does not constrain
     * NULL, so contacts without an email and events without a dedupe_key never
     * collide and are not a problem to solve.
     */
    private function makeRoomFor(string $table, string $column, int $defaultId, bool $renameable, string $prefix): void
    {
        $taken = DB::table($table)
            ->where('brand_id', $defaultId)
            ->whereNotNull($column)
            ->pluck($column)
            ->flip()
            ->map(fn () => true)
            ->all();

        $blocked = [];

        DB::table($table)
            ->whereNull('brand_id')
            ->whereNotNull($column)
            ->orderBy('id')
            ->each(function ($row) use (&$taken, &$blocked, $table, $column, $renameable, $prefix): void {
                $value = (string) $row->{$column};

                if ($value !== '' && ! isset($taken[$value])) {
                    $taken[$value] = true;

                    return;
                }

                if (! $renameable) {
                    $blocked[$value][] = (int) $row->id;

                    return;
                }

                $base = $value === '' ? $prefix.'-'.$row->id : $value.'-'.$row->id;
                $candidate = $base;
                $suffix = 2;

                while (isset($taken[$candidate])) {
                    $candidate = $base.'-'.$suffix++;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $candidate]);
                $taken[$candidate] = true;

                info(sprintf(
                    '[statamic-leadhub] Renamed duplicate %s.%s "%s" to "%s" (id %d) so brand_id could be '
                    .'made NOT NULL. The old value was never unique.',
                    $table,
                    $column,
                    $value,
                    $candidate,
                    $row->id
                ));
            });

        if ($blocked !== []) {
            $listed = collect($blocked)
                ->take(25)
                ->map(fn (array $ids, string $value) => "  {$column}={$value} (ids ".implode(', ', $ids).')')
                ->implode(PHP_EOL);

            throw new RuntimeException(
                "Cannot require a brand on [{$table}]: rows without a brand_id share a [{$column}] with rows "
                .'that already have one, and the brand-scoped unique would reject them once they are stamped.'
                .PHP_EOL.$listed.PHP_EOL
                .'Nothing was changed and nothing was removed. These values are not rewritten to make room: '
                .($column === 'email_normalized'
                    ? 'an email address identifies a person, and merging two records of that person — with '
                     .'their timeline, notes, tasks and opportunities — is not something a migration can decide.'
                    : 'a dedupe_key is the identity of a source event, and rewriting one re-opens the door for '
                     .'the duplicate it exists to keep out.')
                .PHP_EOL
                .'Run `php artisan leadhub:brand-integrity` for the full list with row ids and dates, resolve '
                .'them by hand, then migrate again.'
            );
        }
    }

    private function brandIdIsNullable(string $table): bool
    {
        foreach (Schema::getColumns($table) as $column) {
            if (($column['name'] ?? null) === 'brand_id') {
                return (bool) ($column['nullable'] ?? true);
            }
        }

        return true;
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
};
