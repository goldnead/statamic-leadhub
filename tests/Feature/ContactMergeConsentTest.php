<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\ContactMergeService;

/**
 * A merge must never make an opted-out person mailable again, and must never
 * produce a consent that one of the two records did not have. Whoever said
 * "no" under either address said "no" to the person.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Merging targets the eloquent driver.');
    }
});

it('carries an opt-out from the loser over to the winner', function (): void {
    $verlierer = Contact::factory()->create(['email' => 'alt@example.com', 'do_not_contact' => true]);
    $gewinner = Contact::factory()->create(['email' => 'neu@example.com', 'do_not_contact' => false]);

    $merged = app(ContactMergeService::class)->merge($verlierer, $gewinner);

    expect((bool) $merged->do_not_contact)->toBeTrue();
});

it('keeps the winner opted out when only the winner had opted out', function (): void {
    $verlierer = Contact::factory()->create(['email' => 'alt2@example.com', 'do_not_contact' => false]);
    $gewinner = Contact::factory()->create(['email' => 'neu2@example.com', 'do_not_contact' => true]);

    $merged = app(ContactMergeService::class)->merge($verlierer, $gewinner);

    expect((bool) $merged->do_not_contact)->toBeTrue();
});

it('drops consent when only one of the two had consented', function (): void {
    $verlierer = Contact::factory()->create(['email' => 'ohne@example.com', 'consent' => false, 'consent_at' => null]);
    $gewinner = Contact::factory()->create(['email' => 'mit@example.com', 'consent' => true, 'consent_at' => now()->subDay()]);

    $merged = app(ContactMergeService::class)->merge($verlierer, $gewinner);

    expect((bool) $merged->consent)->toBeFalse()
        ->and($merged->consent_at)->toBeNull();
});

it('does not take over consent from the loser', function (): void {
    $verlierer = Contact::factory()->create(['email' => 'mit2@example.com', 'consent' => true, 'consent_at' => now()->subDay()]);
    $gewinner = Contact::factory()->create(['email' => 'ohne2@example.com', 'consent' => false, 'consent_at' => null]);

    $merged = app(ContactMergeService::class)->merge($verlierer, $gewinner);

    expect((bool) $merged->consent)->toBeFalse();
});

it('keeps consent when both had consented', function (): void {
    $verlierer = Contact::factory()->create(['email' => 'ja1@example.com', 'consent' => true, 'consent_at' => now()->subDays(2)]);
    $gewinner = Contact::factory()->create(['email' => 'ja2@example.com', 'consent' => true, 'consent_at' => now()->subDay()]);

    $merged = app(ContactMergeService::class)->merge($verlierer, $gewinner);

    expect((bool) $merged->consent)->toBeTrue()
        ->and($merged->consent_at)->not->toBeNull();
});

it('writes what the merge did to consent and opt-out into the timeline', function (): void {
    $verlierer = Contact::factory()->create(['email' => 'stopp@example.com', 'do_not_contact' => true, 'consent' => false]);
    $gewinner = Contact::factory()->create(['email' => 'offen@example.com', 'do_not_contact' => false, 'consent' => true, 'consent_at' => now()]);

    app(ContactMergeService::class)->merge($verlierer, $gewinner);

    $event = Event::query()
        ->where('contact_id', $gewinner->id)
        ->where('type', Event::TYPE_CONTACTS_MERGED)
        ->latest('id')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->payload['do_not_contact_inherited'] ?? null)->toBeTrue()
        ->and($event->payload['consent_withdrawn'] ?? null)->toBeTrue();
});
