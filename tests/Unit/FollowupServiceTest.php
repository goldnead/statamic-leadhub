<?php

use Goldnead\Leadhub\Events\LeadHubFollowupCompleted;
use Goldnead\Leadhub\Events\LeadHubFollowupSet;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Followup;
use Goldnead\Leadhub\Services\FollowupService;
use Goldnead\Leadhub\Services\TimelineService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->service = new FollowupService(new TimelineService());
});

it('creates a follow-up for a contact', function (): void {
    Event::fake();

    $contact = Contact::factory()->create();
    $followup = $this->service->set($contact, now()->addDays(3), 'Send proposal');

    expect($followup)->toBeInstanceOf(Followup::class);
    expect($followup->contact_id)->toBe($contact->id);
    expect($followup->note)->toBe('Send proposal');

    Event::assertDispatched(LeadHubFollowupSet::class);
});

it('replaces an existing active follow-up when setting a new one', function (): void {
    $contact = Contact::factory()->create();
    $first = $this->service->set($contact, now()->addDays(2));
    $second = $this->service->set($contact, now()->addDays(5));

    expect(Followup::query()->where('contact_id', $contact->id)->count())->toBe(1);
    expect(Followup::query()->find($first->id))->toBeNull();
    expect(Followup::query()->find($second->id))->not->toBeNull();
});

it('completes a follow-up and emits an event', function (): void {
    Event::fake();

    $contact = Contact::factory()->create();
    $followup = $this->service->set($contact, now()->addDays(2));
    $completed = $this->service->complete($followup, 'user-42');

    expect($completed->isCompleted())->toBeTrue();
    expect($completed->completed_by)->toBe('user-42');

    Event::assertDispatched(LeadHubFollowupCompleted::class);
});

it('returns due_today follow-ups for active contacts only', function (): void {
    $activeContact = Contact::factory()->create();
    $archivedContact = Contact::factory()->archived()->create();

    $this->service->set($activeContact, Carbon::today());
    $this->service->set($archivedContact, Carbon::today());

    $due = $this->service->dueToday()->get();

    expect($due->count())->toBe(1);
    expect($due->first()->contact_id)->toBe($activeContact->id);
});

it('returns overdue follow-ups', function (): void {
    $contact = Contact::factory()->create();
    $this->service->set($contact, Carbon::yesterday()->subDay());

    $overdue = $this->service->overdue()->get();

    expect($overdue->count())->toBe(1);
});
