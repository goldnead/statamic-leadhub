<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from statamic-notifications v1.0.4, where an oversized unique index
 * took a release down on production and a green SQLite suite proved nothing.
 *
 * InnoDB refuses any index wider than 3072 bytes, and under utf8mb4 a
 * `varchar(255)` costs 1020 of them. Three such columns in one unique are
 * already the limit. SQLite — which this suite runs on — has no key limit, no
 * per-character byte cost and no fixed column widths: the arithmetic that fails
 * on MySQL does not exist there to be tested.
 *
 * So this test does not ask the database. It compiles the addon's own
 * migrations through Laravel's MySQL grammar in pretend mode — no server, no
 * connection, nothing to install in CI — and measures the DDL MySQL would have
 * received. It reads the real migration files, so it cannot drift from them.
 *
 * It also asserts the second, quieter property: a SQL unique does not constrain
 * NULL, on any engine, so an index that leads with a nullable column enforces
 * nothing at all for the rows where that column is null.
 */
const INNODB_MAX_KEY_BYTES = 3072;

/**
 * The six identifiers this addon promises to keep unique inside a brand, and
 * the table each lives on. Every one of them leads with `brand_id`.
 */
const LEADHUB_BRAND_SCOPED_UNIQUES = [
    'leadhub_contacts' => 'email_normalized',
    'leadhub_tags' => 'slug',
    'leadhub_events' => 'dedupe_key',
    'leadhub_form_mappings' => 'form_handle',
    'leadhub_pipelines' => 'slug',
    'leadhub_segments' => 'handle',
];

it('keeps every index the migrations create inside the InnoDB key limit', function () {
    $schema = compileLeadhubMigrationsForMysql();

    expect($schema['indexes'])->not->toBeEmpty();

    foreach ($schema['indexes'] as $index) {
        $bytes = 0;

        foreach ($index['columns'] as $column) {
            $width = $schema['columns'][$index['table']][$column]['bytes'] ?? null;

            expect($width)->not->toBeNull(
                "Index {$index['name']} covers unknown column {$column}."
            );

            $bytes += $width;
        }

        expect($bytes)->toBeLessThanOrEqual(
            INNODB_MAX_KEY_BYTES,
            "Index {$index['name']} on {$index['table']} needs {$bytes} bytes under utf8mb4; ".
            'InnoDB allows '.INNODB_MAX_KEY_BYTES.'. MySQL would refuse this migration with SQLSTATE 1071.'
        );
    }
});

/**
 * Indexes that already exceed half the key limit, with the width they had when
 * this test was introduced.
 *
 * Not an exemption — a record. Each entry is asserted to still have EXACTLY the
 * listed width, so widening one of them fails here just as loudly as a new
 * oversized index would. Narrowing them means altering columns on live tables,
 * which is its own migration and its own release; it is written up in GAPS.md
 * instead of smuggled into a scoring feature.
 */
const LEADHUB_WIDE_INDEXES = [
    // (source_type varchar(255), source_id varchar(255)). 2040 of 3072 bytes:
    // legal, but one more varchar(255) would put it at 3060 and the one after
    // that would break the migration on MySQL.
    'leadhub_events_source_type_source_id_index' => 2040,
    'leadhub_opportunities_source_type_source_id_index' => 2040,
    // (assignee_id varchar(255), status varchar(255), due_at timestamp).
    'leadhub_tasks_assignee_id_status_due_at_index' => 2048,
];

it('leaves room in every index for one more column', function () {
    // Being under the limit by accident is what made the notifications schema
    // fragile: it sat at 3212 bytes only because a fourth column had been added
    // to an index that was already at 2196. Half the limit is the line at which
    // the next column is still safe.
    $schema = compileLeadhubMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        $bytes = collect($index['columns'])
            ->sum(fn ($column) => $schema['columns'][$index['table']][$column]['bytes'] ?? 0);

        if (array_key_exists($index['name'], LEADHUB_WIDE_INDEXES)) {
            expect($bytes)->toBe(
                LEADHUB_WIDE_INDEXES[$index['name']],
                "Index {$index['name']} was recorded at ".LEADHUB_WIDE_INDEXES[$index['name']].
                " bytes and now measures {$bytes}. Known-wide indexes are pinned, not exempt."
            );

            continue;
        }

        expect($bytes)->toBeLessThan(
            INNODB_MAX_KEY_BYTES / 2,
            "Index {$index['name']} on {$index['table']} uses {$bytes} bytes — over half the limit, ".
            'so the next column added to it is likely to break the migration.'
        );
    }
});

it('scopes the scoring rules unique to the brand, with a narrow event type', function () {
    $schema = compileLeadhubMigrationsForMysql();

    $unique = collect($schema['indexes'])
        ->where('table', 'leadhub_scoring_rules')
        ->where('unique', true)
        ->firstWhere('name', 'leadhub_scoring_rule_unique');

    expect($unique)->not->toBeNull()
        ->and($unique['columns'])->toBe(['brand_id', 'event_type']);

    // varchar(100), not the default 255: 400 bytes instead of 1020.
    expect($schema['columns']['leadhub_scoring_rules']['event_type']['bytes'])->toBe(400);
});

it('builds every brand-scoped unique over a brand_id that cannot be NULL', function () {
    // A unique index does not constrain NULL. Where the column it leads with is
    // nullable, the constraint does not apply to rows without a brand at all —
    // the index is present, reads as an enforced rule, and enforces nothing.
    // Until 1.10.1 this was asserted for the scoring table alone, which was the
    // only one born with a NOT NULL brand_id; the other six retrofitted theirs
    // by ALTER and kept it nullable for six releases, so `email_normalized`,
    // `slug`, `dedupe_key`, `form_handle` and `handle` were all unconstrained
    // for any row an import or a raw insert left without a brand.
    //
    // Deliberately *not* asserted for the second column of each pair: a contact
    // with no email address and an event with no dedupe_key are ordinary, and
    // the unique not applying to them is the wanted behaviour rather than a
    // hole. What must never be nullable is the column that scopes the rule.
    $schema = compileLeadhubMigrationsForMysql();

    $tables = array_merge(array_keys(LEADHUB_BRAND_SCOPED_UNIQUES), ['leadhub_scoring_rules']);

    foreach ($tables as $table) {
        $brandId = $schema['columns'][$table]['brand_id'] ?? null;

        expect($brandId)->not->toBeNull("No brand_id column was compiled for {$table}.");

        expect($brandId['nullable'])->toBeFalse(
            "{$table}.brand_id is nullable, and a unique that leads with it therefore guarantees nothing ".
            'for rows without a brand — including the uniqueness its name claims.'
        );
    }
});

it('carries a brand-scoped unique for each of the six identifiers', function () {
    // The counterpart to the assertion above: the NOT NULL is only worth
    // anything while the index it protects is still there. This is what the
    // schema ends up holding after the last migration, drops included.
    $schema = compileLeadhubMigrationsForMysql();

    $uniques = collect($schema['indexes'])->where('unique', true)->keyBy('name');

    foreach (LEADHUB_BRAND_SCOPED_UNIQUES as $table => $column) {
        $name = $table.'_brand_id_'.$column.'_unique';

        expect($uniques->has($name))->toBeTrue("{$table} has no brand-scoped unique over {$column}.");
        expect($uniques[$name]['columns'])->toBe(['brand_id', $column]);
    }
});

/**
 * Runs every migration in the addon against a MySQL connection that is never
 * opened, and returns the column definitions and index definitions MySQL would
 * see after the last migration.
 *
 * Two connections, because a migration that branches on the schema needs both
 * halves and no single connection can give them:
 *
 * - the **probe** compiles the DDL. Its grammar is MySQL's, `pretend()` stops
 *   every statement before it reaches a driver, and the rendered SQL is what
 *   gets measured. Under `pretend()` a `select` returns an empty array, so
 *   anything asked of it about the current schema comes back as "nothing is
 *   there".
 * - the **state** is a real SQLite database the same migrations are run against
 *   for real, one file behind. It answers `Schema::hasTable()`,
 *   `Schema::hasColumn()`, `Schema::getColumns()` and `Schema::getIndexes()`.
 *
 * That split is not incidental. Since 1.10.1 `2026_07_24_100000` asks which
 * indexes are present before dropping any and whether each table already has
 * `brand_id`, and `2026_07_30_000001` asks whether `brand_id` is still
 * nullable. A probe that answered "nothing is there" to all of it would measure
 * a schema no install ever holds — and would go green on exactly the defect
 * this file is here to catch.
 *
 * The two run interleaved, probe first: the DDL for migration N is compiled
 * against the schema as it stood after N-1, which is what the server sees.
 *
 * @return array{columns: array<string, array<string, array{bytes: int, nullable: bool}>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>}>}
 */
function compileLeadhubMigrationsForMysql(): array
{
    static $compiled = null;

    if ($compiled !== null) {
        return $compiled;
    }

    config()->set('database.connections.key_length_probe', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'key_length_probe',
        'username' => 'probe',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);

    config()->set('database.connections.key_length_state', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $previous = DB::getDefaultConnection();

    DB::purge('key_length_probe');
    DB::purge('key_length_state');

    $probe = DB::connection('key_length_probe');
    $state = DB::connection('key_length_state');

    // pretend() renders every logged statement with its bindings substituted,
    // and substituting a *string* binding goes through PDO::quote. Without a
    // PDO the probe would try to reach a MySQL server after all — for the query
    // log, not for the query. A throwaway SQLite handle quotes strings and is
    // never asked to run anything: pretending short-circuits every statement
    // before it reaches the driver, and the grammar stays MySQL's, which is the
    // thing being measured.
    $probe->setPdo(new PDO('sqlite::memory:'));

    // The brand every existing row is backfilled onto. brand-context creates
    // this table; the state database only needs enough of it to answer the
    // lookups the migrations make.
    $state->getSchemaBuilder()->create('brands', function ($table) {
        $table->id();
        $table->string('handle');
        $table->boolean('is_default')->default(false);
    });

    $state->table('brands')->insert(['handle' => 'default', 'is_default' => true]);

    // A connection resolves its schema grammar lazily, inside
    // getSchemaBuilder(). The oracle is constructed directly, so it has to be
    // asked for explicitly or every Blueprint the probe compiles gets a null
    // grammar.
    $probe->useDefaultSchemaGrammar();

    $oracle = new LeadhubProbeSchemaBuilder($probe, $state);

    $queries = [];

    try {
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
            $migration = require $file;

            // 1. What MySQL would be sent, decided on the schema as it stands.
            DB::setDefaultConnection('key_length_probe');
            app()->instance('db.schema', $oracle);
            Schema::clearResolvedInstance('db.schema');

            $queries = array_merge($queries, $probe->pretend(fn () => $migration->up()));

            // 2. Advance the real schema, so the next file branches on truth.
            DB::setDefaultConnection('key_length_state');
            app()->forgetInstance('db.schema');
            Schema::clearResolvedInstance('db.schema');

            $migration->up();
        }
    } finally {
        DB::setDefaultConnection($previous);
        app()->forgetInstance('db.schema');
        Schema::clearResolvedInstance('db.schema');
        DB::purge('key_length_probe');
        DB::purge('key_length_state');
    }

    $columns = [];
    $indexes = [];

    foreach (array_column($queries, 'query') as $sql) {
        if (preg_match('/^create table `(\w+)` \((.*)\)(?: default character set| collate|$)/s', $sql, $match)) {
            foreach (leadhubSplitTopLevel($match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = describeLeadhubMysqlColumn($column[2]);
                }
            }

            continue;
        }

        // Columns added later (`Schema::table(…)->…`) and columns redefined by
        // `->change()`, which MySQL compiles to `modify`. Both overwrite what
        // the create-table statement said, so the last word wins — that is the
        // shape the index is finally built on, and it is how brand_id turns
        // from nullable into not null.
        if (preg_match('/^alter table `(\w+)` ((?:add|modify) .+)$/', $sql, $match)) {
            foreach (leadhubSplitTopLevel($match[2]) as $definition) {
                if (preg_match('/^(?:add|modify) `(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = describeLeadhubMysqlColumn($column[2]);
                }
            }
        }

        // Keyed by name, so an index that is rebuilt later in the chain counts
        // once, in the shape the last migration left it.
        if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
            $indexes[$match[1].'.'.$match[3]] = [
                'table' => $match[1],
                'name' => $match[3],
                'unique' => $match[2] === 'unique',
                'columns' => array_map(
                    fn ($column) => trim($column, ' `'),
                    explode(',', $match[4])
                ),
            ];
        }

        if (preg_match('/^alter table `(\w+)` drop index `(\w+)`$/', $sql, $match)) {
            unset($indexes[$match[1].'.'.$match[2]]);
        }
    }

    return $compiled = ['columns' => $columns, 'indexes' => array_values($indexes)];
}

/**
 * Compiles against MySQL's grammar, answers questions from a real database.
 *
 * Everything that writes goes to the probe connection and is measured.
 * Everything that reads is delegated, because the probe has nothing to read: it
 * has no schema, and `pretend()` would answer "empty" to every question a
 * migration asks about the one it is modifying.
 */
class LeadhubProbeSchemaBuilder extends \Illuminate\Database\Schema\MySqlBuilder
{
    public function __construct(
        \Illuminate\Database\Connection $probe,
        private \Illuminate\Database\Connection $state,
    ) {
        parent::__construct($probe);
    }

    public function hasTable($table)
    {
        return $this->state->getSchemaBuilder()->hasTable($table);
    }

    public function hasColumn($table, $column)
    {
        return $this->state->getSchemaBuilder()->hasColumn($table, $column);
    }

    public function hasColumns($table, $columns)
    {
        return $this->state->getSchemaBuilder()->hasColumns($table, $columns);
    }

    public function getTables($schema = null)
    {
        return $this->state->getSchemaBuilder()->getTables();
    }

    public function getColumnListing($table)
    {
        return $this->state->getSchemaBuilder()->getColumnListing($table);
    }

    public function getColumns($table)
    {
        return $this->state->getSchemaBuilder()->getColumns($table);
    }

    public function getIndexes($table)
    {
        return $this->state->getSchemaBuilder()->getIndexes($table);
    }
}

/** Splits a definition list on commas that are not inside parentheses. */
function leadhubSplitTopLevel(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    return array_merge($parts, [$buffer]);
}

/**
 * Worst-case index bytes and nullability for one compiled column definition.
 *
 * @return array{bytes: int, nullable: bool}
 */
function describeLeadhubMysqlColumn(string $type): array
{
    return [
        'bytes' => leadhubMysqlIndexBytes($type),
        // Laravel's MySQL grammar always states one or the other, and `not
        // null` is what a NOT NULL column reads as. Anything else is nullable.
        'nullable' => ! str_contains($type, 'not null'),
    ];
}

/** Worst-case bytes this column type occupies in an index under utf8mb4. */
function leadhubMysqlIndexBytes(string $type): int
{
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match)) {
        return (int) $match[1] * 4;
    }

    return match (true) {
        str_starts_with($type, 'tinyint') => 1,
        str_starts_with($type, 'smallint') => 2,
        str_starts_with($type, 'mediumint') => 3,
        str_starts_with($type, 'int') => 4,
        str_starts_with($type, 'bigint') => 8,
        str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => 8,
        str_starts_with($type, 'date') => 3,
        // Blobs and JSON cannot be indexed whole at all. Reported as oversized
        // so an index that reaches for one fails here rather than on MySQL.
        default => INNODB_MAX_KEY_BYTES + 1,
    };
}
