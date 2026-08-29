<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Support\SourceEvent;
use Illuminate\Support\Str;

/**
 * The other half of the payments bridge, tested from this side.
 *
 * `statamic-payments` names no class from this addon — it sends an array
 * through the facade, which is what keeps the coupling optional. The cost of
 * that is a seam no single suite can see across: its tests prove what it sends,
 * these prove what happens when that arrives. **The array below is copied
 * verbatim from `Goldnead\StatamicPayments\Integrations\LeadhubBridge`.** If
 * either side changes shape without the other, one of the two suites goes red.
 *
 * The failure this exists to catch is the quiet one. `SourceEvent::fromArray()`
 * ignores a key it does not know, so a campaign sent under the wrong key would
 * look exactly like success and be gone.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The revenue ledger targets the eloquent driver.');
    }
});

function purchaseFromPayments(array $overrides = []): array
{
    return array_merge([
        'email' => 'kaeuferin@example.com',
        'type' => 'payments.purchase_completed',
        'summary' => 'Gekauft: noten-paket für 19,00 €',
        'source_type' => 'payment',
        'source_id' => '41',
        'dedupe_key' => 'payments.purchase_completed:41',
        'occurred_at' => now(),
        'contact' => ['full_name' => 'Maria Schneider'],
        'attribution' => [
            'utm_source' => 'newsletter',
            'utm_campaign' => 'sommer-2026',
            'landing_page' => 'https://adriangoldner.com/kurse',
        ],
        'source' => 'statamic-payments',
        'payload' => [
            'detail' => [
                ['label' => 'Produkt', 'value' => 'noten-paket'],
                ['label' => 'Betrag', 'value' => '19,00 €'],
            ],
            'payment_id' => 41,
            'amount_cent' => 1900,
            'currency' => 'EUR',
        ],
    ], $overrides);
}

/**
 * A purchase may create a contact. A tracking pixel may not — which is why
 * `marketing` checks `findByEmail() === null` first and this does not.
 */
it('creates the buyer and keeps the campaign that produced them', function (): void {
    LeadHub::ingest(purchaseFromPayments());

    $contact = Contact::where('email_normalized', 'kaeuferin@example.com')->first();

    expect($contact)->not->toBeNull()
        ->and($contact->full_name)->toBe('Maria Schneider')
        ->and($contact->utm_campaign)->toBe('sommer-2026')
        ->and($contact->utm_source)->toBe('newsletter')
        ->and($contact->landing_page)->toBe('https://adriangoldner.com/kurse');
});

it('leaves a readable line on the timeline', function (): void {
    LeadHub::ingest(purchaseFromPayments());

    $event = Event::where('type', 'payments.purchase_completed')->first();

    expect($event)->not->toBeNull()
        ->and($event->summary)->toBe('Gekauft: noten-paket für 19,00 €')
        ->and($event->payload['detail'][0]['label'])->toBe('Produkt')
        ->and($event->source_type)->toBe('payment')
        ->and($event->source_id)->toBe('41');
});

/**
 * The dedupe key is the contract's idempotency, enforced by a unique index.
 * A webhook delivered twice must leave one line, not two.
 */
it('writes one entry however often the same purchase arrives', function (): void {
    LeadHub::ingest(purchaseFromPayments());
    LeadHub::ingest(purchaseFromPayments());
    LeadHub::ingest(purchaseFromPayments());

    expect(Event::where('type', 'payments.purchase_completed')->count())->toBe(1)
        ->and(Contact::count())->toBe(1);
});

/**
 * A repeat buyer stays attributed to the campaign that first found them.
 *
 * The resolver never overwrites a non-empty field, so the second purchase
 * contributes its story without rewriting where the customer came from — which
 * is the reading anybody means by "where did this customer come from".
 */
it('does not rewrite the attribution of somebody who already exists', function (): void {
    LeadHub::ingest(purchaseFromPayments());

    LeadHub::ingest(purchaseFromPayments([
        'source_id' => '42',
        'dedupe_key' => 'payments.purchase_completed:42',
        'attribution' => ['utm_campaign' => 'herbst-2026'],
    ]));

    $contact = Contact::where('email_normalized', 'kaeuferin@example.com')->first();

    expect($contact->utm_campaign)->toBe('sommer-2026')
        ->and(Event::where('type', 'payments.purchase_completed')->count())->toBe(2);
});

/**
 * The full round trip in the order the bridge does it: ingest resolves or
 * creates, then the amount lands on the contact that ingest just made.
 */
it('carries a first-time buyer from unknown to a lifetime total', function (): void {
    $event = purchaseFromPayments();

    LeadHub::ingest($event);

    $contact = LeadHub::recordRevenue(
        $event['email'],
        'payments:payment:41',
        1900,
        'EUR',
        now(),
        'statamic-payments',
        ['product' => 'noten-paket'],
    );

    expect($contact)->not->toBeNull()
        ->and($contact['revenue_cent'])->toBe(1900)
        ->and($contact['purchase_count'])->toBe(1)
        ->and($contact['revenue_currency'])->toBe('EUR');

    // And the refund the bridge would send afterwards, as a running total.
    $nach = LeadHub::refundRevenue('payments:payment:41', 400);

    expect($nach['revenue_refunded_cent'])->toBe(400)
        ->and($nach['net_revenue_cent'])->toBe(1500);
});

/**
 * A key this addon does not read is a value the sender lost.
 *
 * Asserted so the contract is explicit rather than incidental: everything the
 * bridge sends has to appear in `SourceEvent::fromArray()`, and a future key
 * that does not will fail here instead of vanishing in production.
 */
it('reads every key the bridge sends', function (): void {
    $gesendet = array_keys(purchaseFromPayments());

    // Derived, not listed. A second hand-kept list would go stale in the one
    // direction that matters: if this addon stopped reading a key, an
    // enumerated copy would stay green while the value vanished.
    $gelesen = collect((new ReflectionMethod(SourceEvent::class, '__construct'))->getParameters())
        ->map(fn (ReflectionParameter $p) => Str::snake($p->getName()))
        ->all();

    expect(array_diff($gesendet, $gelesen))->toBe([]);
});
