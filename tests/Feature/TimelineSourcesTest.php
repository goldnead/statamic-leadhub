<?php

use Goldnead\Leadhub\Integrations\Timeline\BookingSource;
use Goldnead\Leadhub\Integrations\Timeline\ConsentSource;
use Goldnead\Leadhub\Integrations\Timeline\EntitlementsSource;
use Goldnead\Leadhub\Integrations\Timeline\PaymentsSource;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\NeighbourStubs;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\StubBooking;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\StubBookingSource;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\StubConsentRecord;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\StubConsentSource;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\StubEntitlement;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\StubEntitlementsSource;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\StubPayment;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\StubPaymentsSource;

require_once __DIR__.'/../Fixtures/Timeline/NeighbourStubs.php';

/**
 * The four readers, against rows shaped like the siblings' tables.
 *
 * The matching rule is the thing most worth pinning: the demo data carries
 * `doppelt@` and `DOPPELT@` as two payment rows on purpose, and a grant whose
 * subject id survived in mixed case. Every reader matches on
 * `LOWER(TRIM(...))`, so both rows belong to the one contact.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The readers query Eloquent tables.');
    }

    NeighbourStubs::migrate();

    $this->contact = Contact::create(['email' => 'Doppelt@Beispiel.de']);
    $this->emails = ['doppelt@beispiel.de'];
});

it('is unavailable for every neighbour when none is installed', function (): void {
    expect((new PaymentsSource)->available())->toBeFalse()
        ->and((new EntitlementsSource)->available())->toBeFalse()
        ->and((new BookingSource)->available())->toBeFalse()
        ->and((new ConsentSource)->available())->toBeFalse();
});

it('lists purchases with their items, a refund as its own line, and counts only what was paid', function (): void {
    $paid = StubPayment::create([
        'provider_id' => 'tr_1', 'product' => 'kurs', 'amount_cent' => 29900, 'currency' => 'EUR',
        'status' => 'paid', 'email' => 'doppelt@beispiel.de', 'paid_at' => '2026-02-01 10:00:00',
        'created_at' => '2026-02-01 09:59:00',
    ]);
    $paid->items()->create(['product' => 'kurs', 'name' => 'Frühlingskurs', 'amount_cent' => 24900]);
    $paid->items()->create(['product' => 'buch', 'name' => 'Arbeitsbuch', 'amount_cent' => 2500, 'quantity' => 2]);

    StubPayment::create([
        'provider_id' => 'tr_2', 'product' => 'ticket', 'amount_cent' => 4900, 'currency' => 'CHF',
        'status' => 'paid', 'email' => 'DOPPELT@beispiel.de', 'paid_at' => '2026-03-01 10:00:00',
        'refunded_cent' => 4900, 'refunded_at' => '2026-03-05 10:00:00',
    ]);
    StubPayment::create(['provider_id' => 'tr_3', 'product' => 'x', 'amount_cent' => 100, 'status' => 'failed', 'email' => 'doppelt@beispiel.de']);
    StubPayment::create(['provider_id' => 'tr_4', 'product' => 'x', 'amount_cent' => 999, 'status' => 'paid', 'email' => 'jemand.anders@beispiel.de']);

    $source = new StubPaymentsSource;
    $entries = collect($source->entries($this->contact, $this->emails))->map->toArray()->keyBy('id');

    expect($entries->keys()->all())->toEqualCanonicalizing(['payment:1', 'payment:2', 'payment:2:refund', 'payment:3'])
        ->and($entries['payment:1']['kind'])->toBe('payment.paid')
        ->and($entries['payment:1']['summary'])->toContain('Frühlingskurs')
        ->and($entries['payment:1']['summary'])->toContain('2 × Arbeitsbuch')
        ->and($entries['payment:1']['amount']['cent'])->toBe(29900)
        ->and($entries['payment:1']['at'])->toStartWith('2026-02-01T10:00')
        ->and($entries['payment:2:refund']['kind'])->toBe('payment.refunded')
        ->and($entries['payment:2:refund']['amount'])->toMatchArray(['cent' => 4900, 'currency' => 'CHF'])
        ->and($entries['payment:2:refund']['at'])->toStartWith('2026-03-05')
        ->and($entries['payment:3']['kind'])->toBe('payment.failed')
        ->and($source->stats($this->contact, $this->emails))->toBe([
            'purchase_count' => 2,
            'lifetime_value' => ['CHF' => 4900, 'EUR' => 29900],
        ])
        ->and($source->supersedes())->toBe(['payments.']);
});

it('reads only the contact\'s brand, plus what a single-brand install wrote as brand 0', function (): void {
    // Payments' own model is not brand-scoped — the shop is one pot — but a
    // contact belongs to a brand, and the same address can buy from two.
    $own = (int) $this->contact->getAttribute('brand_id');
    expect($own)->toBeGreaterThan(0);

    StubPayment::create(['brand_id' => $own, 'provider_id' => 'tr_own', 'product' => 'a', 'amount_cent' => 1000, 'status' => 'paid', 'email' => 'doppelt@beispiel.de', 'paid_at' => '2026-02-01 10:00:00']);
    StubPayment::create(['brand_id' => $own + 7, 'provider_id' => 'tr_other', 'product' => 'a', 'amount_cent' => 2000, 'status' => 'paid', 'email' => 'doppelt@beispiel.de', 'paid_at' => '2026-02-02 10:00:00']);
    StubPayment::create(['brand_id' => 0, 'provider_id' => 'tr_legacy', 'product' => 'a', 'amount_cent' => 4000, 'status' => 'paid', 'email' => 'doppelt@beispiel.de', 'paid_at' => '2026-02-03 10:00:00']);

    $source = new StubPaymentsSource;
    $refs = collect($source->entries($this->contact, $this->emails))->map(fn ($e) => $e->toArray()['detail'][0]['value']);

    expect($refs->all())->toEqualCanonicalizing(['tr_own', 'tr_legacy'])
        ->and($source->stats($this->contact, $this->emails))->toBe(['purchase_count' => 2, 'lifetime_value' => ['EUR' => 5000]]);
});

it('finds grants by address and by the contact record, and counts the ones that open something', function (): void {
    StubEntitlement::create(['subject_type' => 'email', 'subject_id' => 'DOPPELT@Beispiel.de', 'product_slug' => 'kurs', 'source' => 'statamic-payments', 'starts_at' => '2026-02-01 10:00:00']);
    StubEntitlement::create(['subject_type' => $this->contact->getMorphClass(), 'subject_id' => (string) $this->contact->id, 'product_slug' => 'bonus', 'source' => 'manual', 'meta' => ['note' => 'Weil.', 'granted_by_label' => 'Mira'], 'starts_at' => '2026-02-02 10:00:00']);
    StubEntitlement::create(['subject_type' => 'email', 'subject_id' => 'doppelt@beispiel.de', 'product_slug' => 'alt', 'source' => 'manual', 'starts_at' => '2025-01-01 10:00:00', 'revoked_at' => '2025-06-01 10:00:00', 'revoked_reason' => 'Erstattet']);
    StubEntitlement::create(['subject_type' => 'email', 'subject_id' => 'doppelt@beispiel.de', 'product_slug' => 'abgelaufen', 'source' => 'manual', 'starts_at' => '2025-01-01 10:00:00', 'expires_at' => '2025-02-01 10:00:00']);
    StubEntitlement::create(['subject_type' => 'email', 'subject_id' => 'andere@beispiel.de', 'product_slug' => 'fremd']);

    $source = new StubEntitlementsSource;
    $entries = collect($source->entries($this->contact, $this->emails))->map->toArray()->keyBy('id');

    expect($entries->keys()->all())->toEqualCanonicalizing([
        'entitlement:1', 'entitlement:2', 'entitlement:3', 'entitlement:3:revoked', 'entitlement:4', 'entitlement:4:expired',
    ])
        ->and($entries['entitlement:1']['kind'])->toBe('entitlement.granted')
        ->and($entries['entitlement:1']['badge']['color'])->toBe('green')
        ->and($entries['entitlement:2']['summary'])->toContain('bonus')
        ->and(array_column($entries['entitlement:2']['detail'], 'value'))->toContain('Weil.')
        ->and(array_column($entries['entitlement:2']['detail'], 'value'))->toContain('Mira')
        ->and($entries['entitlement:3:revoked']['at'])->toStartWith('2025-06-01')
        ->and(array_column($entries['entitlement:3:revoked']['detail'], 'value'))->toBe(['Erstattet'])
        ->and($entries['entitlement:4:expired']['kind'])->toBe('entitlement.expired')
        ->and($source->stats($this->contact, $this->emails))->toBe(['active_access' => 2]);
});

it('dates a booking by the appointment and labels its status', function (): void {
    StubBooking::create(['endpoint' => 'erstgespraech', 'status' => 'booked', 'scheduled_at' => '2026-04-12 14:00:00', 'duration_minutes' => 30, 'email' => 'Doppelt@Beispiel.de ', 'meeting_url' => 'https://meet.example/x']);
    StubBooking::create(['endpoint' => 'erstgespraech', 'status' => 'cancelled', 'scheduled_at' => '2026-03-01 14:00:00', 'email' => 'doppelt@beispiel.de']);
    StubBooking::create(['endpoint' => 'erstgespraech', 'status' => 'booked', 'scheduled_at' => '2026-03-01 14:00:00', 'email' => 'andere@beispiel.de']);

    $entries = collect((new StubBookingSource)->entries($this->contact, $this->emails))->map->toArray();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['kind'])->toBe('booking.booked')
        ->and($entries[0]['at'])->toStartWith('2026-04-12T14:00')
        ->and($entries[0]['summary'])->toContain('erstgespraech')
        ->and($entries[0]['badge']['color'])->toBe('green')
        ->and(array_column($entries[0]['detail'], 'value'))->toContain('30 min')
        ->and($entries[1]['kind'])->toBe('booking.cancelled');
});

it('reads consent only through a consent_id the contact carries', function (): void {
    StubConsentRecord::create(['consent_id' => 'c-123', 'version' => 3, 'granted' => ['analytics', 'video'], 'how' => 'banner', 'site' => 'default', 'decided_at' => '2026-01-05 08:00:00']);
    StubConsentRecord::create(['consent_id' => 'c-123', 'version' => 3, 'granted' => [], 'how' => 'settings', 'decided_at' => '2026-01-06 08:00:00']);
    StubConsentRecord::create(['consent_id' => 'c-999', 'version' => 3, 'granted' => ['analytics'], 'how' => 'banner', 'decided_at' => '2026-01-07 08:00:00']);

    $source = new StubConsentSource;

    // No id on the contact: nothing, and correctly so.
    expect($source->entries($this->contact, $this->emails))->toBe([]);

    $this->contact->forceFill(['metadata_json' => ['consent_id' => 'c-123']])->save();
    $entries = collect($source->entries($this->contact->fresh(), $this->emails))->map->toArray();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['kind'])->toBe('consent.decided')
        ->and($entries[0]['at'])->toStartWith('2026-01-06')
        ->and($entries[0]['badge']['text'])->toBe('0')
        ->and($entries[1]['summary'])->toContain('analytics, video')
        ->and($entries[1]['badge']['color'])->toBe('green')
        ->and($entries[0]['url'])->toBeNull();
});
