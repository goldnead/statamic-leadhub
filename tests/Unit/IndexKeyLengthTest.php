<?php

use Illuminate\Support\Facades\DB;

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
 * LeadHub's own reason for having it now: v1.8.0 adds `leadhub_scoring_rules`
 * with a unique over `(brand_id, event_type)`. That is exactly the shape that
 * broke notifications, which is why `event_type` is varchar(100) and not the
 * default 255.
 *
 * Difference from the notifications original: LeadHub's `brand_id` was
 * retrofitted by an ALTER rather than declared in a CREATE, so the compiler
 * below reads added columns too. And two backfill migrations call
 * Schema::hasTable(), which would need a real connection — those are skipped by
 * name-checking their source, not swallowed silently.
 */
const INNODB_MAX_KEY_BYTES = 3072;

it('keeps every index the migrations create inside the InnoDB key limit', function () {
    $schema = compileLeadhubMigrationsForMysql();

    expect($schema['indexes'])->not->toBeEmpty();

    foreach ($schema['indexes'] as $index) {
        $bytes = 0;

        foreach ($index['columns'] as $column) {
            $width = $schema['columns'][$index['table']][$column] ?? null;

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
        $bytes = collect($index['columns'])->sum(fn ($column) => $schema['columns'][$index['table']][$column] ?? 0);

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
    expect($schema['columns']['leadhub_scoring_rules']['event_type'])->toBe(400);
});

it('declares brand_id NOT NULL on the scoring rules table', function () {
    // A unique index does not constrain NULLs. With a nullable brand_id the
    // unique would enforce nothing at all for rows without a tenant — the rows
    // where a duplicate rule would do the most damage, because both copies
    // would compute.
    $ddl = leadhubCompiledDdl();

    $create = collect($ddl)->first(fn ($sql) => str_starts_with($sql, 'create table `leadhub_scoring_rules`'));

    expect($create)->not->toBeNull()
        ->and($create)->toContain('`brand_id` bigint unsigned not null');
});

/**
 * Every statement the addon's migrations would send to MySQL.
 *
 * @return list<string>
 */
function leadhubCompiledDdl(): array
{
    static $queries = null;

    if ($queries !== null) {
        return $queries;
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

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('key_length_probe');

    try {
        // pretend() short-circuits every statement before a PDO instance is
        // needed, so this compiles the DDL without a server anywhere in sight.
        $log = DB::connection('key_length_probe')->pretend(function () {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                try {
                    (require $file)->up();
                } catch (\Throwable $e) {
                    // Data backfills ask the schema what exists (Schema::hasTable),
                    // which needs a live connection. They create no indexes, so
                    // skipping them costs nothing — but only they may be skipped:
                    // anything else failing here is a real problem and is re-thrown.
                    if (! str_contains((string) file_get_contents($file), 'Schema::hasTable')) {
                        throw $e;
                    }
                }
            }
        });
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('key_length_probe');
    }

    return $queries = array_column($log, 'query');
}

/**
 * Column widths and index definitions as MySQL would see them.
 *
 * @return array{columns: array<string, array<string, int>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>}>}
 */
function compileLeadhubMigrationsForMysql(): array
{
    $columns = [];
    $indexes = [];

    foreach (leadhubCompiledDdl() as $sql) {
        if (preg_match('/^create table `(\w+)` \((.*)\)(?: default character set| collate|$)/s', $sql, $match)) {
            foreach (leadhubSplitTopLevel($match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = leadhubMysqlIndexBytes($column[2]);
                }
            }

            continue;
        }

        if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
            $indexes[] = [
                'table' => $match[1],
                'name' => $match[3],
                'unique' => $match[2] === 'unique',
                'columns' => array_map(
                    fn ($column) => trim($column, ' `'),
                    explode(',', $match[4])
                ),
            ];

            continue;
        }

        // Columns added later. LeadHub's brand_id arrived this way, and every
        // brand-scoped unique below covers it — without this branch the widths
        // of exactly the columns under test would be unknown.
        if (preg_match('/^alter table `(\w+)` add (.+)$/', $sql, $match)) {
            foreach (explode(', add ', $match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = leadhubMysqlIndexBytes($column[2]);
                }
            }
        }
    }

    return ['columns' => $columns, 'indexes' => $indexes];
}

/** Splits a column list on commas that are not inside parentheses. */
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
