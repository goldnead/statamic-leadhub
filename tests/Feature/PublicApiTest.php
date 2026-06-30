<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Events\LeadHubSourceIngested;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Support\SourceEvent;
use Illuminate\Support\Facades\Event as EventFacade;

// The public LeadHubManager API and the ingestion pipeline are part of the CRM
// core, which (per docs/crm-core-evolution-plan.md) targets the eloquent
// driver. The flat-file driver remains the lightweight form-capture path.
beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Public API + ingestion target the eloquent driver.');
    }
});

it('exposes configured statuses through the facade', function () {
    config()->set('leadhub.statuses', ['new' => 'New', 'won' => 'Won']);

    expect(LeadHub::statuses())->toBe(['new' => 'New', 'won' => 'Won']);
});

it('creates a contact and finds it by email', function () {
    $result = LeadHub::create([
        'email' => 'Jane@Example.com',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'tags' => ['vip'],
    ]);

    expect($result['email'])->toBe('Jane@Example.com')
        ->and($result['full_name'])->toBe('Jane Doe')
        ->and($result['tags'])->toContain('vip');

    $found = LeadHub::findByEmail('jane@example.com');
    expect($found)->not->toBeNull()
        ->and($found['id'])->toBe($result['id']);
});

it('upserts an existing contact by email on create', function () {
    $first = LeadHub::create(['email' => 'bob@example.com', 'first_name' => 'Bob']);
    $second = LeadHub::create(['email' => 'bob@example.com', 'company' => 'Acme']);

    expect($second['id'])->toBe($first['id'])
        ->and($second['company'])->toBe('Acme');
});

it('changes status through the workflow and fires an event', function () {
    EventFacade::fake([LeadHubStatusChanged::class]);

    $contact = LeadHub::create(['email' => 'sam@example.com']);
    $updated = LeadHub::changeStatus($contact['id'], 'qualified');

    expect($updated['status'])->toBe('qualified');
    EventFacade::assertDispatched(LeadHubStatusChanged::class);

    expect(app(EventRepository::class)->countOfType($contact['id'], 'status_changed'))->toBe(1);
});

it('adds tags, notes and follow-ups through the facade', function () {
    $contact = LeadHub::create(['email' => 'lee@example.com']);

    LeadHub::addTag($contact['id'], 'lead');
    LeadHub::addNote($contact['id'], 'Called the lead.');
    $withFollowup = LeadHub::createFollowUp($contact['id'], ['due_in_days' => 3, 'note' => 'Follow up']);

    expect($withFollowup['tags'])->toContain('lead')
        ->and($withFollowup)->toHaveKey('followup_id');

    $model = app(ContactRepository::class)->find($contact['id']);
    expect(app(FollowupRepository::class)->activeForOne($model))->not->toBeNull();
});

it('ingests a generic source event into the timeline', function () {
    EventFacade::fake([LeadHubSourceIngested::class]);

    $event = LeadHub::ingest(new SourceEvent(
        email: 'buyer@example.com',
        type: 'purchase.completed',
        summary: 'Bought the course',
        sourceType: 'App\\Models\\Order',
        sourceId: 42,
        dedupeKey: 'order:42',
        payload: ['amount' => 199, 'secret' => 'sk_live_x'],
    ));

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->type)->toBe('purchase.completed')
        ->and($event->source_type)->toBe('App\\Models\\Order')
        ->and($event->dedupe_key)->toBe('order:42')
        // redaction is applied to ingested payloads too
        ->and($event->payload['secret'])->toBe('***REDACTED***');

    EventFacade::assertDispatched(LeadHubSourceIngested::class);
    expect(LeadHub::findByEmail('buyer@example.com'))->not->toBeNull();
});

it('is idempotent for repeated source events with the same dedupe key', function () {
    $payload = [
        'email' => 'buyer@example.com',
        'type' => 'purchase.completed',
        'dedupe_key' => 'order:99',
    ];

    $first = LeadHub::ingest($payload);
    $second = LeadHub::ingest($payload);

    expect($second->id)->toBe($first->id)
        ->and(app(EventRepository::class)->findByDedupeKey('order:99'))->not->toBeNull();
});

it('skips ingestion when no email is present', function () {
    $event = LeadHub::ingest(new SourceEvent(type: 'anonymous.hit'));

    expect($event)->toBeNull();
});
