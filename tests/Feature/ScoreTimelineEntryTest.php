<?php

use Goldnead\Leadhub\Events\LeadHubContactScoreChanged;
use Goldnead\Leadhub\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event as TimelineEvent;
use Goldnead\Leadhub\Models\ScoringRule;
use Goldnead\Leadhub\Services\ScoringService;
use Statamic\Facades\User;

/**
 * A score change gets a line in the contact's timeline.
 *
 * `LeadHubContactScoreChanged` has fired since v1.2 and nothing listened for
 * the purpose of recording it, so a contact's score had a value and no past.
 * The listener is registered in the provider's $listen, which testbench does
 * not boot on its own — hence bootAddonEvents() below. Asserting on the model
 * without it would test the listener class, not the wiring.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Score timeline entries are asserted against the eloquent event table.');
    }

    $this->user = User::make()->email('score-timeline@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.scoring', true);
    $this->bootAddonEvents();

    $this->contact = Contact::create(['email' => 'timeline-score@example.com']);
});

it('writes a timeline entry when the score changes', function (): void {
    app(ScoringService::class)->award($this->contact, 'purchase.completed');

    $entry = TimelineEvent::query()->where('type', TimelineEvent::TYPE_SCORE_CHANGED)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->contact_id)->toBe($this->contact->id)
        ->and($entry->payload['from'])->toBe(0)
        ->and($entry->payload['to'])->toBe(10)
        ->and($entry->payload['delta'])->toBe(10)
        // The reason is the activity type that awarded the points — without it
        // the entry says a number changed and not what changed it.
        ->and($entry->payload['reason'])->toBe('purchase.completed');
});

it('composes the summary at write time and stores it', function (): void {
    // v1.6.0's rule for every entry type: the sentence is written when it
    // happens and kept. A timeline rendered from live data would rewrite its
    // own past whenever a rule was edited or deleted.
    app(ScoringService::class)->award($this->contact, 'purchase.completed');

    $entry = TimelineEvent::query()->where('type', TimelineEvent::TYPE_SCORE_CHANGED)->firstOrFail();

    expect($entry->summary)->not->toBeNull()
        ->and($entry->summary)->toContain('0')
        ->and($entry->summary)->toContain('10')
        ->and($entry->summary)->toContain('+10');
});

it('writes no entry when the score does not actually move', function (): void {
    app(ScoringService::class)->set($this->contact, 0);

    expect(TimelineEvent::query()->where('type', TimelineEvent::TYPE_SCORE_CHANGED)->count())->toBe(0);
});

it('records a decrease too', function (): void {
    app(ScoringService::class)->set($this->contact, 20);
    app(ScoringService::class)->adjust($this->contact->fresh(), -5, 'manual correction');

    $entries = TimelineEvent::query()
        ->where('type', TimelineEvent::TYPE_SCORE_CHANGED)
        ->orderBy('id')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[1]->payload['delta'])->toBe(-5)
        ->and($entries[1]->summary)->toContain('-5');
});

it('can be switched off without losing the event', function (): void {
    config()->set('leadhub.scoring.timeline', false);

    app(ScoringService::class)->award($this->contact, 'purchase.completed');

    expect(TimelineEvent::query()->where('type', TimelineEvent::TYPE_SCORE_CHANGED)->count())->toBe(0)
        ->and($this->contact->fresh()->engagement_score)->toBe(10);
});

it('reflects a CP rule change in the entry that follows it', function (): void {
    // The chain end to end: a rule written through the CP decides the points,
    // the points move the score, the change lands in the timeline.
    $this->post(cp_route('leadhub.scoring.store'), ['event_type' => 'webinar.attended', 'points' => 7])
        ->assertRedirect();

    app(ScoringService::class)->award($this->contact, 'webinar.attended');

    $entry = TimelineEvent::query()->where('type', TimelineEvent::TYPE_SCORE_CHANGED)->firstOrFail();

    expect($entry->payload['delta'])->toBe(7)
        ->and($entry->payload['reason'])->toBe('webinar.attended');
});

it('registers the score change as a webhook trigger', function (): void {
    // A new event type is a public surface. Without the registration it is a
    // line in a timeline nobody polls; with it, "score crossed a threshold" is
    // usable from outside the addon.
    expect(WebhookManagerBridge::TRIGGERS)->toHaveKey(LeadHubContactScoreChanged::class)
        ->and(WebhookManagerBridge::TRIGGERS[LeadHubContactScoreChanged::class][0])->toBe('leadhub.score.changed');
});

it('builds a webhook payload that carries the contact and the numbers', function (): void {
    // LeadHubContactScoreChanged does not extend LeadHubEvent — it carries a
    // score-specific payload instead of the generic actor/metadata shape. The
    // trigger builder used to assume otherwise and would have produced a
    // webhook with no source reference and a body of nothing.
    if (! class_exists(\Goldnead\WebhookManager\Contracts\TriggerInterface::class)) {
        test()->markTestSkipped('webhook-manager is not installed in this test run.');
    }

    $trigger = new \Goldnead\Leadhub\Integrations\WebhookManager\LeadhubTrigger(
        'leadhub.score.changed',
        'LeadHub — engagement score changed',
    );

    $built = $trigger->build(new LeadHubContactScoreChanged($this->contact, 0, 10, 10, 'purchase.completed'));

    expect($built->sourceReference)->toBe((string) $this->contact->uuid)
        ->and($built->payload['metadata']['new_score'])->toBe(10)
        ->and($built->payload['metadata']['delta'])->toBe(10);
});
