<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\RevenueEntry;
use Goldnead\Leadhub\Services\ContactMergeService;
use Goldnead\Leadhub\Services\RevenueService;
use Goldnead\Leadhub\Support\SegmentEvaluator;
use Illuminate\Support\Facades\Log;

/**
 * What a contact is worth.
 *
 * LeadHub knew what a person did and never what they paid. The point of the
 * ledger is that a contributed amount can arrive twice — a webhook redelivered,
 * a job retried — and must count once, and that the totals a segment compares
 * against can never drift away from the rows they summarise.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The revenue ledger targets the eloquent driver.');
    }
});

it('records an amount and caches the total on the contact', function (): void {
    $contact = Contact::factory()->create(['email' => 'kaeuferin@example.com']);

    app(RevenueService::class)->record($contact, 'payments:payment:1', 1900, 'EUR', now(), 'statamic-payments');

    $contact->refresh();

    expect($contact->revenue_cent)->toBe(1900)
        ->and($contact->purchase_count)->toBe(1)
        ->and($contact->revenue_currency)->toBe('EUR')
        ->and($contact->first_purchase_at)->not->toBeNull()
        ->and($contact->netRevenueCent())->toBe(1900);
});

/**
 * The whole reason this is a ledger and not a counter.
 *
 * An incremented total cannot be made idempotent: the second delivery adds
 * again and no later reader can tell. Here the database decides.
 */
it('counts the same reference once, however often it arrives', function (): void {
    $contact = Contact::factory()->create(['email' => 'zweimal@example.com']);
    $service = app(RevenueService::class);

    $service->record($contact, 'payments:payment:7', 5000, 'EUR');
    $service->record($contact, 'payments:payment:7', 5000, 'EUR');
    $service->record($contact, 'payments:payment:7', 5000, 'EUR');

    expect(RevenueEntry::withoutGlobalScopes()->count())->toBe(1)
        ->and($contact->refresh()->revenue_cent)->toBe(5000)
        ->and($contact->purchase_count)->toBe(1);
});

it('adds up several purchases and keeps the first and last dates', function (): void {
    $contact = Contact::factory()->create(['email' => 'stamm@example.com']);
    $service = app(RevenueService::class);

    $service->record($contact, 'payments:payment:1', 1000, 'EUR', now()->subMonths(6));
    $service->record($contact, 'payments:payment:2', 2500, 'EUR', now()->subMonth());
    $service->record($contact, 'payments:payment:3', 500, 'EUR', now());

    $contact->refresh();

    expect($contact->revenue_cent)->toBe(4000)
        ->and($contact->purchase_count)->toBe(3)
        ->and($contact->first_purchase_at->diffInMonths(now()))->toBeGreaterThanOrEqual(5)
        ->and($contact->last_purchase_at->isToday())->toBeTrue();
});

/**
 * A refund takes the running total, not one movement — so a redelivery is free.
 */
it('takes the running refund total and stays right when it is repeated', function (): void {
    $contact = Contact::factory()->create(['email' => 'zurueck@example.com']);
    $service = app(RevenueService::class);

    $service->record($contact, 'payments:payment:9', 10000, 'EUR');

    $service->refund('payments:payment:9', 2500);
    $service->refund('payments:payment:9', 2500);

    $contact->refresh();

    expect($contact->revenue_refunded_cent)->toBe(2500)
        ->and($contact->netRevenueCent())->toBe(7500)
        // A refund is not an un-purchase. The person did buy.
        ->and($contact->purchase_count)->toBe(1);
});

/**
 * Deliberately not clamped. A chargeback can cost more than the sale, and a
 * ledger that refuses to say so disagrees with the bank statement in silence.
 */
it('allows more to go back than came in, and never reports negative net', function (): void {
    $contact = Contact::factory()->create(['email' => 'rueckbuchung@example.com']);
    $service = app(RevenueService::class);

    $service->record($contact, 'payments:payment:11', 1000, 'EUR');
    $service->refund('payments:payment:11', 1300);

    $contact->refresh();

    expect($contact->revenue_refunded_cent)->toBe(1300)
        ->and($contact->netRevenueCent())->toBe(0);
});

it('says so when a refund arrives for a sale it never saw', function (): void {
    expect(app(RevenueService::class)->refund('payments:payment:404', 100))->toBeNull();
});

it('refuses a negative amount, an empty reference and a bad currency', function (): void {
    $contact = Contact::factory()->create(['email' => 'falsch@example.com']);
    $service = app(RevenueService::class);

    expect(fn () => $service->record($contact, 'ref', -1, 'EUR'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->record($contact, '', 100, 'EUR'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->record($contact, 'ref', 100, 'Euro'))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * The cache is a cache. Anything that wrote rows directly, or a half-deployed
 * bridge, is repaired by recomputing — never by trusting the stored number.
 */
it('rebuilds the totals from the ledger on demand', function (): void {
    $contact = Contact::factory()->create(['email' => 'reparatur@example.com']);
    app(RevenueService::class)->record($contact, 'payments:payment:21', 3000, 'EUR');

    // Somebody, or something, put the cache out of step.
    DB::table('leadhub_contacts')->where('id', $contact->getKey())->update(['revenue_cent' => 999999]);

    app(RevenueService::class)->recalculate($contact->refresh());

    expect($contact->refresh()->revenue_cent)->toBe(3000);
});

it('never attaches one contact\'s reference to another', function (): void {
    $eine = Contact::factory()->create(['email' => 'eine@example.com']);
    $andere = Contact::factory()->create(['email' => 'andere@example.com']);
    $service = app(RevenueService::class);

    $service->record($eine, 'payments:payment:31', 1000, 'EUR');

    expect($service->record($andere, 'payments:payment:31', 1000, 'EUR'))->toBeNull()
        ->and($andere->refresh()->revenue_cent)->toBe(0);
});

// -- The facade, which is what a sibling addon actually touches --------------

it('records revenue through the facade for a contact that exists', function (): void {
    Contact::factory()->create(['email' => 'ueber@example.com']);

    $contact = LeadHub::recordRevenue('Ueber@Example.com', 'payments:payment:41', 4200, 'EUR');

    expect($contact)->not->toBeNull()
        ->and($contact['revenue_cent'])->toBe(4200)
        ->and($contact['net_revenue_cent'])->toBe(4200)
        ->and($contact['purchase_count'])->toBe(1);
});

/**
 * It never creates one. A mis-addressed webhook must not populate a CRM with
 * strangers; a contributor that legitimately creates on purchase calls
 * `ingest()` first, which resolves or creates.
 */
it('refuses to invent a contact just because money arrived', function (): void {
    expect(LeadHub::recordRevenue('niemand@example.com', 'payments:payment:51', 100, 'EUR'))->toBeNull();
});

it('exposes the ledger behind the totals', function (): void {
    $contact = Contact::factory()->create(['email' => 'liste@example.com']);
    app(RevenueService::class)->record($contact, 'payments:payment:61', 1500, 'EUR', now(), 'statamic-payments');

    $zeilen = LeadHub::revenueFor($contact->getKey());

    expect($zeilen)->toHaveCount(1)
        ->and($zeilen[0]['reference'])->toBe('payments:payment:61')
        ->and($zeilen[0]['net_cent'])->toBe(1500)
        ->and($zeilen[0]['source'])->toBe('statamic-payments');
});

/**
 * Totals are always present, zero when nobody contributed any.
 *
 * A caller that has to check whether the key exists ends up reading a missing
 * total as "paid nothing", which is the one reading that must never be a guess.
 */
it('always presents the totals, even for somebody who never paid', function (): void {
    $contact = Contact::factory()->create(['email' => 'nie@example.com']);

    expect(LeadHub::find($contact->getKey()))
        ->toHaveKey('revenue_cent', 0)
        ->toHaveKey('purchase_count', 0)
        ->toHaveKey('net_revenue_cent', 0);
});

// -- Segments ---------------------------------------------------------------

/**
 * The reason the totals are columns and not a custom field: a segment has to
 * compare a number the database can index, and a host must not have to define
 * a field by hand before a contributed total becomes usable.
 */
it('lets a segment ask who has paid more than an amount', function (): void {
    $viel = Contact::factory()->create(['email' => 'viel@example.com']);
    $wenig = Contact::factory()->create(['email' => 'wenig@example.com']);

    app(RevenueService::class)->record($viel, 'payments:payment:71', 20000, 'EUR');
    app(RevenueService::class)->record($wenig, 'payments:payment:72', 900, 'EUR');

    $regel = ['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'revenue_cent', 'operator' => 'gte', 'value' => 10000],
    ]];

    $evaluator = app(SegmentEvaluator::class);

    expect($evaluator->matches($viel->refresh(), $regel))->toBeTrue()
        ->and($evaluator->matches($wenig->refresh(), $regel))->toBeFalse();
});

it('offers the revenue columns to the segment builder', function (): void {
    expect(SegmentEvaluator::FIELDS)
        ->toContain('revenue_cent')
        ->toContain('purchase_count')
        ->toContain('last_purchase_at');
});

/**
 * The money follows the person, not the ambient brand.
 *
 * A payment webhook runs with no brand context. Left to `HasBrand`, the entry
 * would be stamped with the default brand — right on a single-brand site and
 * quietly wrong on every other, which is the shape of bug nobody finds by
 * looking at a screen.
 */
it('stamps the entry with the contact\'s brand, not the active one', function (): void {
    $contact = Contact::factory()->create(['email' => 'marke@example.com']);

    app(RevenueService::class)->record($contact, 'payments:payment:81', 700, 'EUR');

    $entry = RevenueEntry::withoutGlobalScopes()->where('reference', 'payments:payment:81')->first();

    expect($entry->brand_id)->toBe($contact->brand_id);
});

/**
 * A merge is the operation that makes lifetime value right. Left behind, the
 * ledger stays on a tombstoned contact and the winner's total is quietly short.
 */
it('carries the money over when two contacts are merged', function (): void {
    $verlierer = Contact::factory()->create(['email' => 'dublette@example.com']);
    $gewinner = Contact::factory()->create(['email' => 'echt@example.com']);
    $service = app(RevenueService::class);

    $service->record($verlierer, 'payments:payment:91', 3000, 'EUR');
    $service->record($gewinner, 'payments:payment:92', 1000, 'EUR');

    app(ContactMergeService::class)->merge($verlierer, $gewinner);

    expect($gewinner->refresh()->revenue_cent)->toBe(4000)
        ->and($gewinner->purchase_count)->toBe(2);
});

/**
 * Two currencies cannot be added up, and the cached total says nothing useful
 * once they are. Not refused — that would lose a sale over a reporting problem
 * — but never silent.
 */
it('says out loud when a second currency joins a total', function (): void {
    Log::spy();

    $contact = Contact::factory()->create(['email' => 'waehrung@example.com']);
    $service = app(RevenueService::class);

    $service->record($contact, 'payments:payment:101', 1000, 'EUR');
    $service->record($contact->refresh(), 'payments:payment:102', 1000, 'CHF');

    Log::shouldHaveReceived('warning')->atLeast()->once();
});
