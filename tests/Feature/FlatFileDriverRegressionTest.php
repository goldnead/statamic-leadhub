<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Leadhub\Support\PhoneNormalizer;

/**
 * The defects behind the flat-file driver's long-standing red tests.
 *
 * Those failures had been carried from release to release as "pre-existing",
 * proven unrelated to whatever was being built, and left alone. They were not
 * unrelated. Four of them were real defects in the driver, and one was a test
 * reaching past the repository contract into a table the driver does not use.
 *
 * These tests pin the driver-side four. They run only under
 * LEADHUB_DRIVER=flat, because under the eloquent driver they would assert
 * behaviour that comes from the database rather than from this code.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') !== 'flat') {
        test()->markTestSkipped('These are flat-file driver defects.');
    }

    $this->contacts = app(ContactRepository::class);
});

it('normalizes the email on create, so the dedupe lookup can find the contact', function (): void {
    // The CP store action passes no email_normalized; the eloquent driver
    // derives it in Contact::booted(). The flat driver wrote back exactly what
    // it was given, so findByEmailNormalized() — the lookup the whole
    // form-submission dedupe path runs through — returned null and every
    // repeat submission created a second contact.
    $contact = $this->contacts->create([
        'first_name' => 'Jane',
        'email' => 'Jane.Doe@Example.COM',
        'status' => 'new',
    ]);

    $found = $this->contacts->findByEmailNormalized(EmailNormalizer::normalize('Jane.Doe@Example.COM'));

    expect($found)->not->toBeNull()
        ->and((string) $found->uuid)->toBe((string) $contact->uuid);
});

it('normalizes the phone on create and indexes it for lookup', function (): void {
    // findByPhoneNormalized() queried a `by_phone_normalized` bucket that
    // IndexBuilder never built, so it returned null for every contact that
    // ever existed.
    $contact = $this->contacts->create([
        'first_name' => 'Phone',
        'phone' => '+49 151 12345678',
        'status' => 'new',
    ]);

    $found = $this->contacts->findByPhoneNormalized(PhoneNormalizer::normalize('+49 151 12345678'));

    expect($found)->not->toBeNull()
        ->and((string) $found->uuid)->toBe((string) $contact->uuid);
});

it('keeps the uuid intact as the model key instead of casting it to an integer', function (): void {
    // Contact is auto-incrementing under the eloquent driver, so Eloquent adds
    // an implicit `id => int` cast. The flat driver puts a uuid in `id`, and
    // (int) 'e3d35f29-…' is 0.
    $contact = $this->contacts->create(['email' => 'keys@example.com', 'status' => 'new']);

    expect($contact->id)->toBeString()
        ->and($contact->id)->toBe((string) $contact->uuid)
        ->and($contact->id)->not->toBe(0);
});

it('does not let two contacts share one timeline', function (): void {
    // The bug this pins is worse than a wrong id: the event log path is built
    // from $contact->id, so every contact whose uuid began with a hex letter
    // wrote into events/0.jsonl and read back everybody else's timeline. That
    // is roughly two contacts in five.
    $events = app(EventRepository::class);

    // The uuids are fixed rather than generated: whether two of them collided
    // depended on their first hex character, so a random pair reproduced the
    // bug about two times in five and this test would otherwise pass for the
    // wrong reason more often than it caught anything. Both of these cast to
    // integer 0.
    $a = $this->contacts->create([
        'uuid' => 'aaaaaaaa-1111-4111-8111-111111111111',
        'email' => 'a@example.com',
        'status' => 'new',
    ]);
    $b = $this->contacts->create([
        'uuid' => 'bbbbbbbb-2222-4222-8222-222222222222',
        'email' => 'b@example.com',
        'status' => 'new',
    ]);

    expect((int) $a->uuid)->toBe((int) $b->uuid); // the collision this pins

    $events->record($a, Event::TYPE_NOTE_ADDED, 'Only for A');

    expect($events->countOfType($a, Event::TYPE_NOTE_ADDED))->toBe(1)
        ->and($events->countOfType($b, Event::TYPE_NOTE_ADDED))->toBe(0);

    expect($events->forContact($b)->total())->toBe(0);
});

it('reads back an event payload instead of dying on it', function (): void {
    // Event::$casts declares payload => array. A database row hands the cast a
    // JSON string; the flat record arrived already decoded, so json_decode()
    // was handed an array — a TypeError, and a 500 on any contact detail page
    // showing a timeline entry that carried a payload.
    $events = app(EventRepository::class);
    $contact = $this->contacts->create(['email' => 'payload@example.com', 'status' => 'new']);

    $events->record($contact, Event::TYPE_STATUS_CHANGED, 'Status changed', ['from' => 'new', 'to' => 'qualified']);

    $stored = $events->forContact($contact)->items()[0];

    expect($stored->payload)->toBe(['from' => 'new', 'to' => 'qualified']);
});

it('stamps the contact identity when a follow-up creates the contact file', function (): void {
    // A follow-up written for a contact with no YAML yet produced a file
    // holding nothing but the follow-up. The index skips records without a
    // uuid, so that file was invisible to it and visible to every directory
    // scan — and the digest command then called find(null) and died on the
    // type declaration.
    $followups = app(FollowupRepository::class);
    $contact = $this->contacts->create(['email' => 'due@example.com', 'status' => 'new']);

    $followups->create($contact, now()->startOfDay()->addHours(9), 'Call back');

    $due = $followups->dueToday();

    expect($due)->toHaveCount(1)
        ->and($due->first()->contact_id)->not->toBeNull()
        ->and((string) $due->first()->contact_id)->toBe((string) $contact->uuid);
});
