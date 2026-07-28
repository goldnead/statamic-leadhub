<?php

use Goldnead\Leadhub\Tests\Fixtures\LeadhubDataFixture;
use Goldnead\Leadhub\Tests\MigrationPathTestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * The migrations, run against a database that already holds data.
 *
 * Every migration check this addon had ran against empty tables, because every
 * bed it had was a fresh install. That is not a thin spot in the coverage, it is
 * the coverage pointing away from the only case a migration can be wrong about:
 * a table with rows in it, created by an older release.
 *
 * What it let through, published in 1.4.0 and unchanged through 1.10.0:
 * `2026_07_24_100000` drops `leadhub_contacts_email_normalized_index` and then
 * builds a unique over `(brand_id, email_normalized)` with no duplicate check.
 * Before 1.4.0 that column was a plain index — two contacts with the same
 * normalised address are ordinary data on any install that took the same
 * person's enquiry twice — so the statement after the drop fails, no engine
 * rolls DDL back, and the migration is not recorded as run. What is left is a
 * contacts table with no dedupe index of any kind and an install that does not
 * know it.
 *
 * The cases below cover the three states an install can be in — never ran it,
 * ran it successfully, stopped in the middle of it. The last one is produced by
 * actually running the published 1.10.0 migrations out of
 * `tests/Fixtures/released-migrations/` and watching them die, rather than by
 * writing down what we think they leave behind.
 *
 * Every assertion about a uniqueness guarantee is behavioural. "The migration
 * ran" and "the constraint is there" are not the same statement, and mistaking
 * one for the other is the entire defect — so nothing here checks an exit code
 * or an index name on its own. It writes the row the constraint is supposed to
 * refuse and requires the database to refuse it.
 */
it('runs every migration against tables that already hold rows', function (): void {
    $fixture = new LeadhubDataFixture($this->isolated());
    $batch = 0;

    // Seed before each migration, not just at the start: a migration that only
    // ever meets rows written by *earlier* migrations' schema is still only
    // being tested against a fresh install with a bit of data in it. Every
    // migration in the directory is covered, including ones added after this
    // test was written — that is the point of walking the directory rather than
    // naming the file that was broken.
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch): void {
        $fixture->seed($batch++);
    });

    // The last batch, not the first: seeding happens *before* each migration,
    // so batch 0 was handed a database in which none of these tables existed
    // yet and wrote nothing.
    expect($this->duplicateContactIsAccepted(LeadhubDataFixture::duplicateProbe($batch - 1)))
        ->toBeFalse('the contact dedupe unique does not bite after a stepwise migration over populated tables');

    // Contacts without an address are not duplicates of each other and must
    // still be writable. A unique does not constrain NULL, and a migration that
    // treated NULLs as collisions would abort on every real install.
    expect($this->isolated()->table('leadhub_contacts')->whereNull('email_normalized')->count())
        ->toBeGreaterThan(1);
});

it('upgrades a populated install from every released schema', function (string $version): void {
    // The install as it stood on that release, with its data.
    $this->migratePath($this->releasedMigrations($version));

    $fixture = new LeadhubDataFixture($this->isolated());

    expect($fixture->seed(0))->toBe(27);

    $before = $fixture->counts();

    // Then the upgrade, with the tables filling up further as it goes.
    $batch = 1;
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch): void {
        $fixture->seed($batch++);
    });

    $probe = LeadhubDataFixture::duplicateProbe(0);

    expect($this->duplicateContactIsAccepted($probe))
        ->toBeFalse("the contact dedupe unique does not bite after upgrading a populated {$version} install");

    // Nothing that was there before may have gone missing.
    foreach ($before as $table => $count) {
        expect($this->isolated()->table($table)->count())
            ->toBeGreaterThanOrEqual($count, "rows disappeared from {$table}");
    }

    expect($this->isolated()->table('leadhub_contacts')->whereNull('brand_id')->count())->toBe(0);
})->with(['v1.3.0', 'v1.4.0', 'v1.10.0']);

it('all six brand-scoped identifiers are refused a second time inside one brand', function (): void {
    $this->migratePath($this->releasedMigrations('v1.3.0'));

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    $this->migratePath($this->currentMigrations());

    // Names of indexes prove nothing. Each of these writes the row the unique
    // is supposed to reject.
    expect($this->duplicateContactIsAccepted(LeadhubDataFixture::duplicateProbe(0)))->toBeFalse();
    expect($this->duplicateIsAccepted('leadhub_tags', 'slug', 'vip-0'))->toBeFalse();
    expect($this->duplicateIsAccepted('leadhub_events', 'dedupe_key', 'event-0-submitted'))->toBeFalse();
    expect($this->duplicateIsAccepted('leadhub_form_mappings', 'form_handle', 'contact-0'))->toBeFalse();
    expect($this->duplicateIsAccepted('leadhub_pipelines', 'slug', 'sales-0'))->toBeFalse();
    expect($this->duplicateIsAccepted('leadhub_segments', 'handle', 'hot-0'))->toBeFalse();
});

it('leaves the published migration nowhere to hide: it dies and takes the dedupe index with it', function (): void {
    // 1. An install from before brand-scoping, with two records of one person —
    //    which is what a plain index allowed and what any install that took the
    //    same enquiry twice actually holds.
    $this->migratePath($this->releasedMigrations('v1.3.0'));

    $fixture = new LeadhubDataFixture($this->isolated());
    $fixture->seed(0);

    $probe = LeadhubDataFixture::duplicateProbe(0);
    $this->writeDuplicateContact($probe);

    expect($this->isolated()->table('leadhub_contacts')->where('email_normalized', $probe)->count())->toBe(2);

    // 2. Update to 1.10.0, exactly as published. It dies.
    expect(fn () => $this->migratePath($this->releasedMigrations('v1.10.0')))
        ->toThrow(QueryException::class);

    // 3. The damage, which is the part nobody sees: the migration is not
    //    recorded, and the old index is gone without a replacement.
    expect($this->ranMigrations())->not->toContain('2026_07_24_100000_add_brand_id_to_leadhub_tables');

    $indexes = collect(Schema::connection(MigrationPathTestCase::CONNECTION)->getIndexes('leadhub_contacts'))
        ->pluck('name');

    expect($indexes)->not->toContain('leadhub_contacts_email_normalized_index')
        ->and($indexes)->not->toContain('leadhub_contacts_brand_id_email_normalized_unique');
});

it('names the duplicate contacts instead of deleting them', function (): void {
    $this->migratePath($this->releasedMigrations('v1.3.0'));

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    $probe = LeadhubDataFixture::duplicateProbe(0);
    $this->writeDuplicateContact($probe);

    $countBefore = $this->isolated()->table('leadhub_contacts')->count();

    // The fix must not pick a winner, and must not fail with a driver error
    // either. It has to say what it found, by address.
    expect(fn () => $this->migratePath($this->currentMigrations()))
        ->toThrow(RuntimeException::class, $probe);

    // And it must not have deleted anything on the way out.
    expect($this->isolated()->table('leadhub_contacts')->count())->toBe($countBefore);
});

it('recovers an install that the published migration stopped halfway through', function (): void {
    $this->migratePath($this->releasedMigrations('v1.3.0'));

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    $probe = LeadhubDataFixture::duplicateProbe(0);
    $duplicateId = $this->writeDuplicateContact($probe);

    expect(fn () => $this->migratePath($this->releasedMigrations('v1.10.0')))->toThrow(QueryException::class);

    // Half of the seventeen tables carry brand_id now and the migration is not
    // recorded. Re-running the *published* file from here fails on `duplicate
    // column`, which is the error that sends everybody looking in the wrong
    // place; re-running the current one has to pick the state up instead.
    expect(fn () => $this->migratePath($this->releasedMigrations('v1.10.0')))->toThrow(QueryException::class);

    // The operator resolves the pair by hand, which is the only party that can.
    $this->isolated()->table('leadhub_contacts')->where('id', $duplicateId)->delete();

    $this->migratePath($this->currentMigrations());

    expect($this->ranMigrations())->toContain('2026_07_24_100000_add_brand_id_to_leadhub_tables');

    expect($this->duplicateContactIsAccepted($probe))
        ->toBeFalse('the dedupe unique was not restored on a half-migrated install');

    expect($this->isolated()->table('leadhub_contacts')->whereNull('brand_id')->count())->toBe(0);
});

it('refuses to start when there is no brand to stamp the rows with', function (): void {
    $this->migratePath($this->releasedMigrations('v1.3.0'));

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    // brand-context not migrated: the 1.4.0 file read this table at step 2,
    // after all seventeen tables had already been altered.
    Schema::connection(MigrationPathTestCase::CONNECTION)->drop('brand_user');
    Schema::connection(MigrationPathTestCase::CONNECTION)->drop('brands');

    expect(fn () => $this->migratePath($this->currentMigrations()))
        ->toThrow(RuntimeException::class, 'brand-context');

    // Nothing was altered, so nothing has to be undone before retrying.
    expect(Schema::connection(MigrationPathTestCase::CONNECTION)->hasColumn('leadhub_contacts', 'brand_id'))
        ->toBeFalse();
});

it('requires a brand on every table whose unique leads with one', function (): void {
    $this->migratePath($this->releasedMigrations('v1.4.0'));

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    // A raw insert with no brand_id — an import, an upsert, a fix run from
    // tinker. Under 1.4.0 the column was nullable, so this row went in and its
    // identifier was unconstrained from then on.
    $this->isolated()->table('leadhub_tags')->insert([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'name' => 'Unbranded',
        'slug' => 'vip-0',
        'brand_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->isolated()->table('leadhub_tags')->where('slug', 'vip-0')->count())
        ->toBe(2, 'a nullable brand_id let the per-brand unique be bypassed entirely');

    // The upgrade renames the colliding handle rather than deleting the row,
    // then tightens the column.
    $this->migratePath($this->currentMigrations());

    expect($this->isolated()->table('leadhub_tags')->whereNull('brand_id')->count())->toBe(0);
    expect($this->isolated()->table('leadhub_tags')->where('name', 'Unbranded')->value('slug'))
        ->not->toBe('vip-0');

    foreach ([
        'leadhub_contacts',
        'leadhub_tags',
        'leadhub_events',
        'leadhub_form_mappings',
        'leadhub_pipelines',
        'leadhub_segments',
    ] as $table) {
        $brandId = collect(Schema::connection(MigrationPathTestCase::CONNECTION)->getColumns($table))
            ->firstWhere('name', 'brand_id');

        expect($brandId['nullable'] ?? true)->toBeFalse("{$table}.brand_id is still nullable");
    }
});

it('will not rewrite an address to make room for the constraint', function (): void {
    $this->migratePath($this->releasedMigrations('v1.4.0'));

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    $probe = LeadhubDataFixture::duplicateProbe(0);

    // A brand-less second record of the same person. The 1.4.0 unique accepts
    // it without complaint — which is the hole: NULL is not constrained, so the
    // index that reads as a dedupe guarantee does not apply to this row at all.
    $duplicateId = $this->writeDuplicateContact($probe, ['brand_id' => null]);

    expect($this->isolated()->table('leadhub_contacts')->where('email_normalized', $probe)->count())
        ->toBe(2, 'a nullable brand_id let the contact dedupe be bypassed entirely');

    expect(fn () => $this->migratePath($this->currentMigrations()))
        ->toThrow(RuntimeException::class, $probe);

    // Not renamed, not deleted, not merged.
    expect($this->isolated()->table('leadhub_contacts')->where('id', $duplicateId)->value('email_normalized'))
        ->toBe($probe);
});

it('reports a half-migrated install through the brand integrity command', function (): void {
    $this->migratePath($this->releasedMigrations('v1.3.0'));

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    $probe = LeadhubDataFixture::duplicateProbe(0);
    $this->writeDuplicateContact($probe);

    expect(fn () => $this->migratePath($this->releasedMigrations('v1.10.0')))->toThrow(QueryException::class);

    $countBefore = $this->isolated()->table('leadhub_contacts')->count();

    $this->artisan('leadhub:brand-integrity', ['--database' => MigrationPathTestCase::CONNECTION])
        ->expectsOutputToContain('no index protecting')
        ->expectsOutputToContain($probe)
        ->assertExitCode(1);

    // Reporting is all it does. Nothing was repaired and nothing was removed.
    expect($this->isolated()->table('leadhub_contacts')->count())->toBe($countBefore);
});

it('rebuilds the brand uniques once the duplicates are gone', function (): void {
    $this->migratePath($this->releasedMigrations('v1.3.0'));

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    $probe = LeadhubDataFixture::duplicateProbe(0);
    $duplicateId = $this->writeDuplicateContact($probe);

    expect(fn () => $this->migratePath($this->releasedMigrations('v1.10.0')))->toThrow(QueryException::class);

    $this->artisan('leadhub:brand-integrity', [
        '--database' => MigrationPathTestCase::CONNECTION,
        '--repair' => true,
    ])->assertExitCode(1);

    expect($this->duplicateContactIsAccepted($probe))
        ->toBeTrue('--repair must refuse while duplicates exist rather than build an index that cannot hold');

    // The operator resolves it by hand.
    $this->isolated()->table('leadhub_contacts')->where('id', $duplicateId)->delete();

    $this->artisan('leadhub:brand-integrity', [
        '--database' => MigrationPathTestCase::CONNECTION,
        '--repair' => true,
    ])->assertExitCode(0);

    expect($this->duplicateContactIsAccepted($probe))->toBeFalse();

    // And the outstanding migrations run afterwards without complaint.
    $this->migratePath($this->currentMigrations());

    expect($this->duplicateContactIsAccepted($probe))->toBeFalse();
});

it('confirms the guarantee on a healthy install', function (): void {
    $this->migratePath($this->currentMigrations());

    (new LeadhubDataFixture($this->isolated()))->seed(0);

    $this->artisan('leadhub:brand-integrity', ['--database' => MigrationPathTestCase::CONNECTION])
        ->expectsOutputToContain('unique inside its brand')
        ->assertExitCode(0);
});
