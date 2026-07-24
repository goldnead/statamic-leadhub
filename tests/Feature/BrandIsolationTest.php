<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentContactRepository;

/**
 * Hard brand isolation for LeadHub contacts.
 *
 * Proves that with multi-brand enabled a contact created under Brand A is
 * invisible to a Brand B context (and vice versa), and that the reworked
 * (brand_id, email_normalized) unique lets the same email live in both brands.
 */
beforeEach(function (): void {
    // Turn on hard isolation. Single-brand default is a no-op, so we must flip
    // the flag and clear any resolved current brand for a clean slate.
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);
    $this->repo = new EloquentContactRepository;
});

it('hides a contact created in brand A from a brand B context', function (): void {
    $contactA = BrandContext::runFor($this->brandA, fn () => $this->repo->create([
        'email' => 'shared@example.com',
        'first_name' => 'Alice',
    ]));

    $contactB = BrandContext::runFor($this->brandB, fn () => $this->repo->create([
        'email' => 'shared@example.com',
        'first_name' => 'Bob',
    ]));

    // brand_id was stamped from the active brand on create.
    expect($contactA->brand_id)->toBe($this->brandA->id);
    expect($contactB->brand_id)->toBe($this->brandB->id);
    expect($contactA->id)->not->toBe($contactB->id);

    // In Brand A's context only A's contact is reachable.
    BrandContext::setCurrent($this->brandA);
    expect($this->repo->find($contactA->id))->not->toBeNull();
    expect($this->repo->find($contactB->id))->toBeNull();
    expect($this->repo->findByEmailNormalized('shared@example.com')?->id)->toBe($contactA->id);

    // In Brand B's context only B's contact is reachable.
    BrandContext::setCurrent($this->brandB);
    expect($this->repo->find($contactB->id))->not->toBeNull();
    expect($this->repo->find($contactA->id))->toBeNull();
    expect($this->repo->findByEmailNormalized('shared@example.com')?->id)->toBe($contactB->id);
});

it('allows the same email to hold independent contacts per brand', function (): void {
    // The (brand_id, email_normalized) unique replaces the old global index, so
    // this must NOT throw a unique-constraint violation.
    BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'dup@example.com']));
    BrandContext::runFor($this->brandB, fn () => Contact::create(['email' => 'dup@example.com']));

    $total = BrandContext::withoutBrandScope(
        fn () => Contact::query()->where('email_normalized', 'dup@example.com')->count()
    );

    expect($total)->toBe(2);
});

it('fails closed: no current brand yields no contacts', function (): void {
    BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'a@example.com']));

    // Multi-brand on, but no current brand resolved -> fail closed.
    app('brand-context')->forget();

    expect(Contact::query()->count())->toBe(0);
});
