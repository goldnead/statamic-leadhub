<?php

use Goldnead\Leadhub\Events\LeadHubContactScoreChanged;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\ScoringService;
use Illuminate\Support\Facades\Event as EventFacade;

// Scoring targets the eloquent driver (CRM core), same as the ingestion
// pipeline. Skip the flat-file matrix job.
beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Scoring targets the eloquent driver.');
    }

    config()->set('leadhub.features.scoring', true);
    config()->set('leadhub.scoring.events.email_link_clicked', 3);
});

function scoredContact(array $overrides = []): Contact
{
    return Contact::factory()->create(array_merge([
        'engagement_score' => 0,
    ], $overrides));
}

it('dispatches LeadHubContactScoreChanged with correct old/new/delta when an activity changes the score', function () {
    EventFacade::fake([LeadHubContactScoreChanged::class]);

    $contact = scoredContact();

    $new = app(ScoringService::class)->award($contact, 'email_link_clicked');

    expect($new)->toBe(3);

    EventFacade::assertDispatched(LeadHubContactScoreChanged::class, function ($event) use ($contact) {
        return $event->contact->is($contact)
            && $event->oldScore === 0
            && $event->newScore === 3
            && $event->delta === 3
            && $event->reason === 'email_link_clicked';
    });
});

it('adjustScore adds the delta, persists, returns the new score, and dispatches the event', function () {
    EventFacade::fake([LeadHubContactScoreChanged::class]);

    $contact = scoredContact(['engagement_score' => 10]);

    $new = LeadHub::adjustScore($contact->uuid, 5, 'manual');

    expect($new)->toBe(15);
    expect($contact->fresh()->engagement_score)->toBe(15);

    EventFacade::assertDispatched(LeadHubContactScoreChanged::class, function ($event) use ($contact) {
        return $event->contact->is($contact)
            && $event->oldScore === 10
            && $event->newScore === 15
            && $event->delta === 5
            && $event->reason === 'manual';
    });
});

it('does not dispatch the event on a no-op score change', function () {
    EventFacade::fake([LeadHubContactScoreChanged::class]);

    $contact = scoredContact(['engagement_score' => 7]);

    $new = LeadHub::adjustScore($contact->uuid, 0, 'noop');

    expect($new)->toBe(7);
    expect($contact->fresh()->engagement_score)->toBe(7);

    EventFacade::assertNotDispatched(LeadHubContactScoreChanged::class);
});

it('clamps the score at zero and returns null for an unknown contact', function () {
    $contact = scoredContact(['engagement_score' => 2]);

    // Clamp: -5 from 2 lands at 0, not -3.
    expect(LeadHub::adjustScore($contact->uuid, -5, 'penalty'))->toBe(0);
    expect($contact->fresh()->engagement_score)->toBe(0);

    // Unknown contact resolves to null.
    expect(LeadHub::adjustScore('non-existent-uuid', 5))->toBeNull();
});

it('exposes a stable toArray shape for automations', function () {
    $contact = scoredContact(['engagement_score' => 2, 'email' => 'score@example.com']);

    $event = new LeadHubContactScoreChanged($contact, 2, 5, 3, 'manual');

    expect($event->toArray())->toMatchArray([
        'contact_id' => $contact->id,
        'email' => 'score@example.com',
        'old_score' => 2,
        'new_score' => 5,
        'delta' => 3,
        'reason' => 'manual',
    ]);
});
