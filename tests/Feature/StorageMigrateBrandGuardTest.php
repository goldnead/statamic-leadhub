<?php

use Goldnead\BrandContext\Models\Brand;

/**
 * `leadhub:storage:migrate` under multi-brand.
 *
 * The eloquent driver is brand-scoped; the flat driver is not. `FileStore` is a
 * singleton on one path and nothing under `Repositories/FlatFile` reads or
 * writes a brand, so `content/leadhub/` is one undifferentiated set.
 *
 * Before this guard the command took no brand at all. A console run has no
 * session, so the multi-brand scope failed closed and it read nothing:
 *
 *   • 0 tag(s) to migrate
 *   • 0 contact(s) processed
 *   Migration complete.
 *
 * Exit code 0. On a driver migration that is worse than a crash — you switch
 * LEADHUB_DRIVER afterwards and the site comes up empty.
 *
 * The fix does not pretend the flat store can hold several brands. It refuses
 * the cases that would merge or guess, and runs the rest inside a named brand.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Migrating from the flat driver needs the eloquent side available.');
    }
});

it('runs without a brand option on a single-brand install', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    $this->artisan('leadhub:storage:migrate --from=eloquent --to=flat --dry-run')
        ->assertExitCode(0);
});

it('refuses to migrate several brands into the flat store', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    Brand::create(['handle' => 'mig-a', 'name' => 'Mig A']);
    Brand::create(['handle' => 'mig-b', 'name' => 'Mig B']);

    // Merging them is the failure this refuses: the files carry no brand, so
    // afterwards every brand would read every brand's contacts.
    $this->artisan('leadhub:storage:migrate --from=eloquent --to=flat')
        ->expectsOutputToContain('no per-brand layout')
        ->assertExitCode(1);
});

it('lets a named brand go to flat, which is what the refusal tells you to do', function (): void {
    // The first version of this guard rejected --to=flat outright while its own
    // error message said "migrate a single brand with --brand=<handle>". An
    // instruction the command then refuses is worse than no instruction.
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    Brand::create(['handle' => 'one-a', 'name' => 'One A']);
    Brand::create(['handle' => 'one-b', 'name' => 'One B']);

    $this->artisan('leadhub:storage:migrate --from=eloquent --to=flat --brand=one-a --dry-run')
        ->expectsOutputToContain('Brand: one-a')
        ->assertExitCode(0);
});

it('still refuses when only the direction is reversed and no brand is named', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    Brand::create(['handle' => 'rev-a', 'name' => 'Rev A']);
    Brand::create(['handle' => 'rev-b', 'name' => 'Rev B']);

    $this->artisan('leadhub:storage:migrate --from=flat --to=eloquent')
        ->expectsOutputToContain('--brand is required')
        ->assertExitCode(1);
});

it('accepts a named brand', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    Brand::create(['handle' => 'named-a', 'name' => 'Named A']);
    Brand::create(['handle' => 'named-b', 'name' => 'Named B']);

    $this->artisan('leadhub:storage:migrate --from=flat --to=eloquent --brand=named-a --dry-run')
        ->expectsOutputToContain('Brand: named-a')
        ->assertExitCode(0);
});

it('rejects an unknown brand rather than falling back to all of them', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    Brand::create(['handle' => 'known-a', 'name' => 'Known A']);
    Brand::create(['handle' => 'known-b', 'name' => 'Known B']);

    $this->artisan('leadhub:storage:migrate --from=flat --to=eloquent --brand=typo')
        ->expectsOutputToContain('No brand [typo]')
        ->assertExitCode(1);
});

it('offers a --brand option', function (): void {
    expect(app('Illuminate\Contracts\Console\Kernel')->all()['leadhub:storage:migrate']
        ->getDefinition()->hasOption('brand'))->toBeTrue();
});
