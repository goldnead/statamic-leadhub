<?php

namespace Goldnead\Leadhub\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Answers the question a failed update leaves open: are the six identifiers
 * LeadHub promises to keep unique per brand actually unique per brand?
 *
 * `php artisan migrate` reporting success means the migrations ran. It does not
 * mean the constraints they were supposed to leave behind are there. Under
 * 1.4.0 through 1.10.0 an install with duplicate contact addresses aborted
 * `2026_07_24_100000` *after* it had dropped
 * `leadhub_contacts_email_normalized_index`, so the table ended up with neither
 * the old index nor the new unique, and with the migration not recorded as run.
 * Nothing about that state announces itself: forms keep accepting submissions,
 * they just stop being deduplicated.
 *
 * So this command does not ask whether a migration ran. It reads the indexes
 * that are on the tables right now, and it reads the rows.
 *
 * It never deletes anything. Where duplicates exist they are real records —
 * two contacts are two records of one person, with two timelines, two sets of
 * notes and two sets of tasks — and deciding which one survives is not a
 * decision a command gets to make. `--repair` rebuilds indexes and nothing
 * else, and refuses to run while there is anything for one to reject.
 */
class BrandIntegrityCommand extends Command
{
    protected $signature = 'leadhub:brand-integrity
        {--database= : The connection to inspect (default: the configured one)}
        {--repair : Rebuild the brand-scoped uniques, if and only if nothing would have to be deleted for them}';

    protected $description = 'Check that every brand-scoped LeadHub identifier is actually unique per brand, and say so plainly';

    /**
     * table => [scoped column, the pre-1.4.0 index it replaced, the columns to
     * print for a colliding row].
     */
    private const SCOPED = [
        'leadhub_contacts' => [
            'column' => 'email_normalized',
            'legacy' => 'leadhub_contacts_email_normalized_index',
            'legacy_unique' => false,
            'display' => ['id', 'email', 'full_name', 'status', 'created_at'],
        ],
        'leadhub_tags' => [
            'column' => 'slug',
            'legacy' => 'leadhub_tags_slug_unique',
            'legacy_unique' => true,
            'display' => ['id', 'name', 'slug', 'created_at'],
        ],
        'leadhub_events' => [
            'column' => 'dedupe_key',
            'legacy' => 'leadhub_events_dedupe_key_unique',
            'legacy_unique' => true,
            'display' => ['id', 'type', 'source_type', 'source_id', 'created_at'],
        ],
        'leadhub_form_mappings' => [
            'column' => 'form_handle',
            'legacy' => 'leadhub_form_mappings_form_handle_unique',
            'legacy_unique' => true,
            'display' => ['id', 'form_handle', 'enabled', 'created_at'],
        ],
        'leadhub_pipelines' => [
            'column' => 'slug',
            'legacy' => 'leadhub_pipelines_slug_unique',
            'legacy_unique' => true,
            'display' => ['id', 'name', 'slug', 'created_at'],
        ],
        'leadhub_segments' => [
            'column' => 'handle',
            'legacy' => 'leadhub_segments_handle_unique',
            'legacy_unique' => true,
            'display' => ['id', 'name', 'handle', 'created_at'],
        ],
    ];

    public function handle(): int
    {
        $connection = DB::connection($this->option('database') ?: null);
        $schema = Schema::connection($this->option('database') ?: null);

        if (! $schema->hasTable('leadhub_contacts')) {
            $this->components->info('There are no LeadHub tables on this connection; nothing to check.');

            return self::SUCCESS;
        }

        $findings = [];

        foreach (self::SCOPED as $table => $definition) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $definition['column'])) {
                continue;
            }

            $findings[$table] = $this->inspect($connection, $schema, $table, $definition);
        }

        $this->report($findings);

        if ($this->option('repair')) {
            return $this->repair($schema, $findings);
        }

        $healthy = collect($findings)->every(
            fn (array $f) => $f['index_ok'] && $f['duplicates']->isEmpty() && $f['null_brands'] === 0
        );

        if ($healthy) {
            $this->line('');
            $this->components->info(
                'Every brand-scoped identifier is unique inside its brand, and the database is what enforces it.'
            );

            return self::SUCCESS;
        }

        $this->line('');
        $this->components->bulletList(array_filter([
            collect($findings)->contains(fn (array $f) => $f['duplicates']->isNotEmpty())
                ? 'Decide which of the rows above is the record to keep, and merge or remove the others. '
                 .'Nothing else can make that call.'
                : null,
            collect($findings)->contains(fn (array $f) => $f['null_brands'] > 0)
                ? 'Rows without a brand_id are stamped onto the default brand by `php artisan migrate`.'
                : null,
            'Then run `php artisan migrate`, which rebuilds the indexes and finishes the interrupted update.',
            'Or run this command again with --repair to rebuild only the indexes.',
        ]));

        return self::FAILURE;
    }

    /**
     * @param  array{column: string, legacy: string, legacy_unique: bool, display: list<string>}  $definition
     * @return array{table: string, column: string, expected: string, present: array<string, mixed>|null, index_ok: bool, has_brand: bool, null_brands: int, duplicates: Collection, display: list<string>}
     */
    private function inspect(Connection $connection, Builder $schema, string $table, array $definition): array
    {
        $hasBrand = $schema->hasColumn($table, 'brand_id');
        $column = $definition['column'];

        $expectedName = $table.'_brand_id_'.$column.'_unique';
        $expectedColumns = ['brand_id', $column];

        $indexes = collect($schema->getIndexes($table))->keyBy('name');
        $present = $indexes->get($expectedName) ?? $indexes->get($definition['legacy']);

        $indexOk = $hasBrand
            && $present !== null
            && $present['name'] === $expectedName
            && $present['columns'] === $expectedColumns
            && ($present['unique'] ?? false)
            && ! $this->brandIdIsNullable($schema, $table);

        return [
            'table' => $table,
            'column' => $column,
            'expected' => $expectedName,
            'expected_columns' => $expectedColumns,
            'present' => $present,
            'index_ok' => $indexOk,
            'has_brand' => $hasBrand,
            'brand_nullable' => $hasBrand && $this->brandIdIsNullable($schema, $table),
            'null_brands' => $hasBrand ? $connection->table($table)->whereNull('brand_id')->count() : 0,
            'duplicates' => $this->duplicates($connection, $table, $column, $hasBrand, $definition['display']),
            'display' => $definition['display'],
        ];
    }

    /**
     * Rows that share an identifier inside a brand.
     *
     * NULL is excluded, in both columns and deliberately. No engine constrains
     * a NULL in a unique index, so contacts without an email address and events
     * without a dedupe_key are not collisions and reporting them as such would
     * bury the ones that are — on any real install they are the majority of the
     * events table.
     */
    private function duplicates(Connection $connection, string $table, string $column, bool $hasBrand, array $display): Collection
    {
        $columns = array_values(array_filter([$hasBrand ? 'brand_id' : null, $column]));

        $query = $connection->table($table)
            ->select($columns)
            ->selectRaw('count(*) as occurrences')
            ->groupBy($columns)
            ->havingRaw('count(*) > 1')
            ->orderBy($column);

        foreach ($columns as $c) {
            $query->whereNotNull($c);
        }

        return $query->get()->map(function ($group) use ($connection, $table, $columns, $display) {
            $rows = $connection->table($table)
                ->where(collect($columns)->mapWithKeys(fn ($c) => [$c => $group->{$c}])->all())
                ->orderBy('id')
                ->get($display);

            return (object) ['group' => $group, 'rows' => $rows];
        });
    }

    private function brandIdIsNullable(Builder $schema, string $table): bool
    {
        foreach ($schema->getColumns($table) as $column) {
            if (($column['name'] ?? null) === 'brand_id') {
                return (bool) ($column['nullable'] ?? true);
            }
        }

        return true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $findings
     */
    private function report(array $findings): void
    {
        foreach ($findings as $table => $finding) {
            $this->line('');
            $this->components->twoColumnDetail('<fg=cyan>'.$table.'</>', '<fg=gray>unique per brand on '.$finding['column'].'</>');
            $this->components->twoColumnDetail(
                '<fg=gray>brand_id column</>',
                $finding['has_brand']
                    ? ($finding['brand_nullable'] ? '<fg=yellow>yes, nullable</>' : 'yes, not null')
                    : '<fg=red>missing</>'
            );
            $this->components->twoColumnDetail(
                '<fg=gray>index</>',
                $finding['present']
                    ? $finding['present']['name'].' ('.implode(', ', $finding['present']['columns']).')'
                     .(($finding['present']['unique'] ?? false) ? '' : ' <fg=red>not unique</>')
                    : '<fg=red>none</>'
            );

            if (! $finding['present']) {
                $this->components->error(sprintf(
                    'There is no index protecting %s.%s at all. The update that was supposed to replace it '
                    .'dropped the old one and stopped before building the new one, and duplicates have been '
                    .'accepted ever since.',
                    $table,
                    $finding['column'],
                ));
            } elseif (! $finding['index_ok'] && $finding['present']['name'] !== $finding['expected']) {
                $this->components->warn(sprintf(
                    'The index is [%s]; this schema expects [%s] over (%s). Run `php artisan migrate`.',
                    $finding['present']['name'],
                    $finding['expected'],
                    implode(', ', $finding['expected_columns']),
                ));
            }

            if ($finding['null_brands'] > 0) {
                $this->components->warn(sprintf(
                    '%d row(s) have no brand_id. The unique leads with that column and no engine constrains '
                    .'a NULL, so %s is unconstrained for those rows whatever the index says. '
                    .'`php artisan migrate` stamps them onto the default brand.',
                    $finding['null_brands'],
                    $finding['column'],
                ));
            } elseif ($finding['brand_nullable']) {
                $this->components->warn(
                    'brand_id is nullable here. Every row has one today, so the unique holds — but nothing '
                    .'stops a raw insert from writing a row without one, and that row would be unconstrained. '
                    .'`php artisan migrate` tightens the column.'
                );
            }

            $this->reportDuplicates($finding);
        }
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function reportDuplicates(array $finding): void
    {
        if ($finding['duplicates']->isEmpty()) {
            $this->components->twoColumnDetail('<fg=gray>duplicates</>', 'none');

            return;
        }

        $this->components->error(sprintf(
            '%d value(s) of %s occur more than once inside one brand. These were accepted while no unique '
            .'was in place.',
            $finding['duplicates']->count(),
            $finding['column'],
        ));

        foreach ($finding['duplicates'] as $duplicate) {
            $this->line('');
            $this->line('  <fg=yellow>'.$duplicate->group->{$finding['column']}.'</>'
                .(property_exists($duplicate->group, 'brand_id')
                    ? ' (brand '.($duplicate->group->brand_id ?? 'NULL').')'
                    : ''));

            $this->table(
                $finding['display'],
                $duplicate->rows->map(fn ($row) => collect($finding['display'])
                    ->map(fn (string $c) => $row->{$c} ?? '—')
                    ->all())->all(),
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $findings
     */
    private function repair(Builder $schema, array $findings): int
    {
        $this->line('');

        $blocked = collect($findings)->filter(fn (array $f) => $f['duplicates']->isNotEmpty());

        if ($blocked->isNotEmpty()) {
            $this->components->error(
                'Refusing to rebuild: the rows above would be rejected by the index. Which of them is the '
                .'record to keep is a question about people and their history, not about rows, so this '
                .'command will not answer it. Resolve them, then run --repair again.'
            );

            return self::FAILURE;
        }

        $missingBrand = collect($findings)->filter(fn (array $f) => ! $f['has_brand'] || $f['null_brands'] > 0);

        if ($missingBrand->isNotEmpty()) {
            $this->components->error(
                'Refusing to rebuild: '.$missingBrand->keys()->implode(', ').' still hold rows with no '
                .'brand_id, and an index built over them would not constrain those rows. Run '
                .'`php artisan migrate`, which backfills them first.'
            );

            return self::FAILURE;
        }

        $rebuilt = 0;

        foreach ($findings as $table => $finding) {
            if ($finding['index_ok'] || ($finding['present'] && $finding['present']['name'] === $finding['expected']
                && $finding['present']['columns'] === $finding['expected_columns']
                && ($finding['present']['unique'] ?? false))) {
                continue;
            }

            if ($finding['present']) {
                $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($finding['present']['name']));
            }

            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->unique(
                $finding['expected_columns'],
                $finding['expected'],
            ));

            $rebuilt++;

            $this->components->info(sprintf(
                'Rebuilt [%s] over (%s).',
                $finding['expected'],
                implode(', ', $finding['expected_columns']),
            ));
        }

        if ($rebuilt === 0) {
            $this->components->info('Every brand-scoped unique is already in place; nothing to repair.');

            return self::SUCCESS;
        }

        $this->components->warn(
            'This restored the constraints only. Run `php artisan migrate` to finish the update itself — '
            .'the migration that was interrupted is still recorded as not run, and brand_id is still nullable.'
        );

        return self::SUCCESS;
    }
}
