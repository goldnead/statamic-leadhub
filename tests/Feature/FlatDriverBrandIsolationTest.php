<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Illuminate\Support\Facades\File;

/** Every contact visible in the current context, by email. */
function visibleEmails(): array
{
    return collect(app(ContactRepository::class)->paginate([], perPage: 100)->items())
        ->pluck('email')
        ->sort()
        ->values()
        ->all();
}

/**
 * Hard brand isolation for the flat-file driver.
 *
 * Until 1.11 there was none: `FileStore` was a singleton on one path and
 * nothing under `Repositories/FlatFile` read or wrote a brand, so every brand
 * read every brand's contacts. The eloquent driver scoped correctly, which made
 * this the sharpest kind of inconsistency — the same install isolated or did
 * not depending on a config value that reads like a storage preference.
 *
 * Brands now live in the path. These tests assert the three properties that
 * matters: a read never crosses a brand, the pre-brand layout still belongs to
 * the default brand, and no current brand means no rows rather than all of them.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') !== 'flat') {
        test()->markTestSkipped('Flat-driver specific.');
    }

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
});

it('does not let one brand read another brand\'s contacts', function (): void {
    $a = Brand::create(['handle' => 'iso-a', 'name' => 'Iso A']);
    $b = Brand::create(['handle' => 'iso-b', 'name' => 'Iso B']);

    BrandContext::runFor($a, fn () => LeadHub::create(['email' => 'only-a@example.com']));
    BrandContext::runFor($b, fn () => LeadHub::create(['email' => 'only-b@example.com']));

    $emailsIn = fn (Brand $brand) => BrandContext::runFor($brand, fn () => visibleEmails());

    expect($emailsIn($a))->toBe(['only-a@example.com'])
        ->and($emailsIn($b))->toBe(['only-b@example.com']);
});

it('puts each brand in its own directory', function (): void {
    $a = Brand::create(['handle' => 'dir-a', 'name' => 'Dir A']);
    $b = Brand::create(['handle' => 'dir-b', 'name' => 'Dir B']);

    BrandContext::runFor($a, fn () => LeadHub::create(['email' => 'a@example.com']));
    BrandContext::runFor($b, fn () => LeadHub::create(['email' => 'b@example.com']));

    $root = rtrim(config('leadhub.storage.flat.path'), '/');

    // Structural isolation: it is visible in `ls`, not hidden in a key.
    expect(File::glob($root.'/dir-a/contacts/*.yaml'))->toHaveCount(1)
        ->and(File::glob($root.'/dir-b/contacts/*.yaml'))->toHaveCount(1);
});

it('reads nothing when multi-brand is on and no brand is current', function (): void {
    $a = Brand::create(['handle' => 'closed-a', 'name' => 'Closed A']);
    BrandContext::runFor($a, fn () => LeadHub::create(['email' => 'hidden@example.com']));

    app('brand-context')->forget();

    // Fail closed, exactly like the eloquent driver's global scope. A console
    // run must not see every brand just because it has no session.
    expect(visibleEmails())->toBe([]);
});

it('treats the pre-brand layout as the default brand, and only that brand', function (): void {
    $root = rtrim(config('leadhub.storage.flat.path'), '/');
    // A file written before brands existed: directly under the root.
    $uuid = '11111111-1111-4111-8111-111111111111';
    File::ensureDirectoryExists($root.'/contacts');
    File::put($root.'/contacts/'.$uuid.'.yaml', implode("\n", [
        'uuid: '.$uuid,
        'email: legacy@example.com',
        'email_normalized: legacy@example.com',
        'status: new',
    ])."\n");

    $other = Brand::create(['handle' => 'newcomer', 'name' => 'Newcomer']);

    $default = BrandContext::default();

    $emailsIn = fn ($brand) => BrandContext::runFor($brand, fn () => visibleEmails());

    // The default brand inherits it — an install that flips the flag must not
    // open to an empty contact list.
    expect($emailsIn($default))->toContain('legacy@example.com')
        // And no other brand ever does. Those files predate brands; they belong
        // to the one every existing row was backfilled onto.
        ->and($emailsIn($other))->not->toContain('legacy@example.com');
});

it('keeps the pre-brand layout untouched on a single-brand install', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    LeadHub::create(['email' => 'single@example.com']);

    $root = rtrim(config('leadhub.storage.flat.path'), '/');

    // No directory appears. An install that never enables multi-brand never
    // sees this feature at all.
    expect(File::glob($root.'/contacts/*.yaml'))->toHaveCount(1);
});
