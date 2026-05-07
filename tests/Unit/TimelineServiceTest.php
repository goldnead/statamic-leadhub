<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\TimelineService;

beforeEach(function (): void {
    $this->service = new TimelineService();
    $this->contact = Contact::factory()->create();
});

it('creates a timeline event with the given type and summary', function (): void {
    $event = $this->service->record(
        $this->contact,
        Event::TYPE_NOTE_ADDED,
        'A note was added',
        ['preview' => 'Hi'],
    );

    expect($event->type)->toBe(Event::TYPE_NOTE_ADDED);
    expect($event->summary)->toBe('A note was added');
    expect($event->payload)->toBe(['preview' => 'Hi']);
    expect($event->contact_id)->toBe($this->contact->id);
});

it('stores actor_type and actor_id when provided', function (): void {
    $event = $this->service->record(
        $this->contact,
        Event::TYPE_NOTE_ADDED,
        actorType: 'user',
        actorId: 'user-123',
    );

    expect($event->actor_type)->toBe('user');
    expect($event->actor_id)->toBe('user-123');
});

it('falls back to system actor when no CP user is authenticated', function (): void {
    $event = $this->service->record($this->contact, Event::TYPE_CONTACT_CREATED);

    expect($event->actor_type)->toBe('system');
    expect($event->actor_id)->toBeNull();
});

it('redacts sensitive payload keys', function (): void {
    config(['leadhub.timeline_payload_redaction' => ['password', 'secret', 'token']]);

    $event = $this->service->record(
        $this->contact,
        Event::TYPE_SUBMISSION_RECEIVED,
        payload: [
            'email' => 'foo@example.com',
            'password' => 'topsecret',
            'access_token' => 'abc123',
            'nested' => ['secret_key' => 'shh', 'safe' => 'ok'],
        ],
    );

    expect($event->payload['password'])->toBe('***REDACTED***');
    expect($event->payload['access_token'])->toBe('***REDACTED***');
    expect($event->payload['nested']['secret_key'])->toBe('***REDACTED***');
    expect($event->payload['nested']['safe'])->toBe('ok');
    expect($event->payload['email'])->toBe('foo@example.com');
});

it('records a contact_created shortcut', function (): void {
    $event = $this->service->recordContactCreated($this->contact);

    expect($event->type)->toBe(Event::TYPE_CONTACT_CREATED);
});

it('records a status_changed event with from/to in payload', function (): void {
    $event = $this->service->recordStatusChanged($this->contact, 'new', 'qualified');

    expect($event->type)->toBe(Event::TYPE_STATUS_CHANGED);
    expect($event->payload['from'])->toBe('new');
    expect($event->payload['to'])->toBe('qualified');
});
