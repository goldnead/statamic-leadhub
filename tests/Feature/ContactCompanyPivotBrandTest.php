<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\CompanyResolver;
use Illuminate\Support\Facades\DB;

/**
 * The denormalized brand_id on leadhub_contact_company.
 *
 * The brand migration justified the column as "query-time defense: every read
 * filters on brand_id instead of trusting a join not to be forgotten". Nothing
 * stamped it and nothing read it, so the defense did not exist.
 *
 * Keeping the column was the decision, because it is the only protection that
 * survives the paths where the models' own BrandScope is deliberately switched
 * off — cross-brand admin and reporting run inside
 * BrandContext::withoutBrandScope(), and console commands iterate brands. The
 * last test here is the one that fails if the pivot filter is removed.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The company pivot is eloquent-only.');
    }

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'pivot-a', 'name' => 'Pivot A']);
    $this->brandB = Brand::create(['handle' => 'pivot-b', 'name' => 'Pivot B']);
});

it('stamps the brand on the pivot row when a company is linked', function (): void {
    [$contact, $company] = BrandContext::runFor($this->brandA, fn () => [
        Contact::create(['email' => 'stamp@example.com']),
        Company::create(['name' => 'Alpha GmbH']),
    ]);

    BrandContext::runFor($this->brandA, fn () => app(CompanyResolver::class)->link($contact, $company));

    $row = DB::table('leadhub_contact_company')->where('contact_id', $contact->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->brand_id)->toBe($this->brandA->id);
});

it('stamps the brand when the relation is used directly', function (): void {
    [$contact, $company] = BrandContext::runFor($this->brandA, fn () => [
        Contact::create(['email' => 'direct@example.com']),
        Company::create(['name' => 'Direct GmbH']),
    ]);

    BrandContext::runFor($this->brandA, fn () => $contact->companies()->attach($company->id));

    expect(DB::table('leadhub_contact_company')->value('brand_id'))->toBe($this->brandA->id);
});

it('keeps the primary-company demotion inside the brand', function (): void {
    $contact = BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'primary@example.com']));
    $first = BrandContext::runFor($this->brandA, fn () => Company::create(['name' => 'First GmbH']));
    $second = BrandContext::runFor($this->brandA, fn () => Company::create(['name' => 'Second GmbH']));

    BrandContext::runFor($this->brandA, function () use ($contact, $first, $second) {
        app(CompanyResolver::class)->link($contact, $first, primary: true);
        app(CompanyResolver::class)->link($contact, $second, primary: true);
    });

    $primaries = DB::table('leadhub_contact_company')
        ->where('contact_id', $contact->id)
        ->where('is_primary', true)
        ->pluck('company_id')
        ->all();

    expect($primaries)->toBe([$second->id]);
});

it('never hands a contact a company from another brand, even with the model scope off', function (): void {
    $contact = BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'crossed@example.com']));
    $ownCompany = BrandContext::runFor($this->brandA, fn () => Company::create(['name' => 'Alpha GmbH']));
    $foreignCompany = BrandContext::runFor($this->brandB, fn () => Company::create(['name' => 'Beta GmbH']));

    BrandContext::runFor($this->brandA, fn () => app(CompanyResolver::class)->link($contact, $ownCompany));

    // A mis-scoped importer (or any pre-fix link) writing a cross-brand row.
    DB::table('leadhub_contact_company')->insert([
        'contact_id' => $contact->id,
        'company_id' => $foreignCompany->id,
        'brand_id' => $this->brandB->id,
        'is_primary' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Cross-brand admin/reporting deliberately turns the global scope off. With
    // it off, the Company model no longer filters anything — the pivot brand_id
    // is the only thing left standing between brand A and brand B's data.
    $names = BrandContext::withoutBrandScope(
        fn () => $contact->companies()->orderBy('name')->pluck('name')->all()
    );

    expect($names)->toBe(['Alpha GmbH']);
});

it('does not lose links whose pivot brand was backfilled from the contact', function (): void {
    $contact = BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'legacy@example.com']));
    $company = BrandContext::runFor($this->brandA, fn () => Company::create(['name' => 'Legacy GmbH']));

    // A row as it existed before the fix: no brand stamp at all.
    DB::table('leadhub_contact_company')->insert([
        'contact_id' => $contact->id,
        'company_id' => $company->id,
        'brand_id' => null,
        'is_primary' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(BrandContext::runFor($this->brandA, fn () => $contact->companies()->count()))->toBe(0);

    // The backfill migration is what makes such a row visible again.
    DB::statement(
        'UPDATE leadhub_contact_company SET brand_id = ('
        .'SELECT p.brand_id FROM leadhub_contacts p WHERE p.id = leadhub_contact_company.contact_id'
        .') WHERE brand_id IS NULL'
    );

    expect(BrandContext::runFor($this->brandA, fn () => $contact->companies()->count()))->toBe(1);
});
