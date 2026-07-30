<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Support\Facades\File;

/**
 * `leadhub:migrate-flat-brands`.
 *
 * Moving files is the one operation where a mistake is not recoverable by
 * re-running something, so the properties asserted here are the safety ones:
 * it only ever moves, it never overwrites, and a second run is a no-op.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') !== 'flat') {
        test()->markTestSkipped('Flat-driver specific.');
    }

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->root = rtrim(config('leadhub.storage.flat.path'), '/');
});

/** A contact file in the pre-brand layout. */
function legacyContact(string $root, string $uuid): void
{
    File::ensureDirectoryExists($root.'/contacts');
    File::put($root.'/contacts/'.$uuid.'.yaml', "uuid: {$uuid}\nemail: legacy@example.com\n");
}

it('moves the pre-brand layout into the default brand directory', function (): void {
    legacyContact($this->root, 'aaaa1111-1111-4111-8111-111111111111');
    File::put($this->root.'/tags.yaml', "- name: VIP\n  slug: vip\n");

    $default = BrandContext::default()->handle;

    $this->artisan('leadhub:migrate-flat-brands')->assertExitCode(0);

    expect(File::exists($this->root.'/'.$default.'/contacts/aaaa1111-1111-4111-8111-111111111111.yaml'))->toBeTrue()
        ->and(File::exists($this->root.'/'.$default.'/tags.yaml'))->toBeTrue()
        // Moved, not copied — two files for one contact is the failure mode
        // the store's write path works hard to avoid.
        ->and(File::exists($this->root.'/contacts/aaaa1111-1111-4111-8111-111111111111.yaml'))->toBeFalse()
        ->and(File::exists($this->root.'/tags.yaml'))->toBeFalse();
});

it('changes nothing on a dry run', function (): void {
    legacyContact($this->root, 'bbbb2222-2222-4222-8222-222222222222');

    $this->artisan('leadhub:migrate-flat-brands --dry-run')->assertExitCode(0);

    expect(File::exists($this->root.'/contacts/bbbb2222-2222-4222-8222-222222222222.yaml'))->toBeTrue();
});

it('is a no-op on a second run', function (): void {
    legacyContact($this->root, 'cccc3333-3333-4333-8333-333333333333');

    $this->artisan('leadhub:migrate-flat-brands')->assertExitCode(0);
    $this->artisan('leadhub:migrate-flat-brands')
        ->expectsOutputToContain('Nothing to move')
        ->assertExitCode(0);
});

it('never overwrites a file already in the brand directory', function (): void {
    $uuid = 'dddd4444-4444-4444-8444-444444444444';
    $default = BrandContext::default()->handle;

    legacyContact($this->root, $uuid);

    // A target that already exists means a finished migration or a genuine
    // conflict. Neither is resolved by clobbering.
    File::ensureDirectoryExists($this->root.'/'.$default.'/contacts');
    File::put($this->root.'/'.$default.'/contacts/'.$uuid.'.yaml', "uuid: {$uuid}\nemail: keep-me@example.com\n");

    $this->artisan('leadhub:migrate-flat-brands')->assertExitCode(0);

    expect(File::get($this->root.'/'.$default.'/contacts/'.$uuid.'.yaml'))
        ->toContain('keep-me@example.com');
});

it('can target a named brand', function (): void {
    legacyContact($this->root, 'eeee5555-5555-4555-8555-555555555555');
    Brand::create(['handle' => 'target', 'name' => 'Target']);

    $this->artisan('leadhub:migrate-flat-brands --brand=target')->assertExitCode(0);

    expect(File::exists($this->root.'/target/contacts/eeee5555-5555-4555-8555-555555555555.yaml'))->toBeTrue();
});

it('rejects an unknown brand', function (): void {
    $this->artisan('leadhub:migrate-flat-brands --brand=nope')
        ->expectsOutputToContain('No brand [nope]')
        ->assertExitCode(1);
});

it('does nothing on a single-brand install', function (): void {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    legacyContact($this->root, 'ffff6666-6666-4666-8666-666666666666');

    $this->artisan('leadhub:migrate-flat-brands')
        ->expectsOutputToContain('Single-brand install')
        ->assertExitCode(0);

    // The pre-brand layout IS the correct layout there.
    expect(File::exists($this->root.'/contacts/ffff6666-6666-4666-8666-666666666666.yaml'))->toBeTrue();
});
