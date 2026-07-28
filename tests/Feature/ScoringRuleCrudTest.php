<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\ScoringRule;
use Goldnead\Leadhub\Services\ScoringService;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\User;

/**
 * The scoring rule screen, against the real routes.
 *
 * The point of the expensive variant chosen in decision L2 is not that the
 * rules are visible — it is that changing one in the CP changes what the
 * scoring engine computes. So every test here that creates a rule through the
 * HTTP route also asks ScoringService what a contact would now be awarded. A
 * test that only checked the row exists would pass for a screen wired to
 * nothing.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Scoring rules live in a table; the flat driver has none.');
    }

    $this->user = User::make()->email('scoring-crud@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.scoring', true);
});

it('renders the rule screen', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.scoring.index'));

    $response->assertStatus(200);
    expect(json_decode($response->getContent(), true)['component'])->toBe('leadhub::Scoring/Index');
});

it('404s while the scoring feature is off', function (): void {
    config()->set('leadhub.features.scoring', false);

    $this->get(cp_route('leadhub.scoring.index'))->assertStatus(404);
});

it('creates a rule from the CP and the engine computes with it', function (): void {
    // Before: nothing in the table, so the config table decides. `webinar.attended`
    // is not in it, so it scores the config default.
    expect(app(ScoringService::class)->pointsFor('webinar.attended'))->toBe(1);

    $this->post(cp_route('leadhub.scoring.store'), [
        'event_type' => 'webinar.attended',
        'points' => 7,
        'label' => 'Attended a webinar',
    ])->assertRedirect();

    expect(ScoringRule::query()->where('event_type', 'webinar.attended')->exists())->toBeTrue();

    // After: the engine reads the rule, not the config default.
    expect(app(ScoringService::class)->pointsFor('webinar.attended'))->toBe(7);
});

it('awards the new rule to a contact', function (): void {
    $this->post(cp_route('leadhub.scoring.store'), [
        'event_type' => 'webinar.attended',
        'points' => 7,
    ])->assertRedirect();

    $contact = Contact::create(['email' => 'scored@example.com']);

    expect(app(ScoringService::class)->award($contact, 'webinar.attended'))->toBe(7)
        ->and($contact->fresh()->engagement_score)->toBe(7);
});

it('edits the points of an existing rule', function (): void {
    $rule = ScoringRule::create(['event_type' => 'purchase.completed', 'points' => 10]);

    $this->patch(cp_route('leadhub.scoring.update', $rule->id), ['points' => 25])
        ->assertRedirect();

    expect($rule->fresh()->points)->toBe(25)
        ->and(app(ScoringService::class)->pointsFor('purchase.completed'))->toBe(25);
});

it('treats a disabled rule as an absent one', function (): void {
    ScoringRule::create(['event_type' => ScoringRule::CATCH_ALL, 'points' => 2]);
    $rule = ScoringRule::create(['event_type' => 'purchase.completed', 'points' => 10]);

    expect(app(ScoringService::class)->pointsFor('purchase.completed'))->toBe(10);

    $this->patch(cp_route('leadhub.scoring.update', $rule->id), ['enabled' => false])
        ->assertRedirect();

    // Falls through to the catch-all, not to zero. "Off" means "as if it were
    // never written"; awarding zero would be a silent third behaviour.
    expect(app(ScoringService::class)->pointsFor('purchase.completed'))->toBe(2);
});

it('uses the catch-all rule for types without one', function (): void {
    ScoringRule::create(['event_type' => ScoringRule::CATCH_ALL, 'points' => 4]);

    expect(app(ScoringService::class)->pointsFor('something.nobody.configured'))->toBe(4);
});

it('deletes a rule outright, because nothing hangs on one', function (): void {
    $rule = ScoringRule::create(['event_type' => 'purchase.completed', 'points' => 10]);
    ScoringRule::create(['event_type' => ScoringRule::CATCH_ALL, 'points' => 1]);

    $contact = Contact::create(['email' => 'already-scored@example.com']);
    app(ScoringService::class)->award($contact, 'purchase.completed');
    expect($contact->fresh()->engagement_score)->toBe(10);

    $this->delete(cp_route('leadhub.scoring.destroy', $rule->id))->assertRedirect();

    expect(ScoringRule::query()->find($rule->id))->toBeNull()
        // The house rule (L1) refuses a delete while something hangs on the
        // record. Nothing references a scoring rule: the points already awarded
        // are a running total on the contact, not a sum recomputed from rules.
        // So the delete succeeds AND the contact keeps its score.
        ->and($contact->fresh()->engagement_score)->toBe(10);
});

it('rejects a duplicate event type with a visible field error', function (): void {
    ScoringRule::create(['event_type' => 'purchase.completed', 'points' => 10]);

    $this->from(cp_route('leadhub.scoring.index'))
        ->post(cp_route('leadhub.scoring.store'), ['event_type' => 'purchase.completed', 'points' => 3])
        ->assertRedirect(cp_route('leadhub.scoring.index'))
        ->assertSessionHasErrors('event_type');

    expect(ScoringRule::query()->where('event_type', 'purchase.completed')->count())->toBe(1);
});

it('rejects an event type that is not a handle', function (): void {
    $this->from(cp_route('leadhub.scoring.index'))
        ->post(cp_route('leadhub.scoring.store'), ['event_type' => 'not a handle!', 'points' => 3])
        ->assertSessionHasErrors('event_type');

    expect(ScoringRule::query()->count())->toBe(0);
});

it('rejects points that are not a number', function (): void {
    $this->from(cp_route('leadhub.scoring.index'))
        ->post(cp_route('leadhub.scoring.store'), ['event_type' => 'webinar.attended', 'points' => 'lots'])
        ->assertSessionHasErrors('points');
});

it('refuses the write routes without the manage permission', function (): void {
    $plain = User::make()->email('scoring-nobody@example.com');
    $plain->save();

    $rule = ScoringRule::create(['event_type' => 'purchase.completed', 'points' => 10]);

    $this->actingAs($plain);

    $this->post(cp_route('leadhub.scoring.store'), ['event_type' => 'webinar.attended', 'points' => 3])
        ->assertStatus(403);
    $this->patch(cp_route('leadhub.scoring.update', $rule->id), ['points' => 99])->assertStatus(403);
    $this->delete(cp_route('leadhub.scoring.destroy', $rule->id))->assertStatus(403);

    expect(ScoringRule::query()->count())->toBe(1)
        ->and($rule->fresh()->points)->toBe(10);
});

/**
 * A sibling addon's route-model binding must not swallow these routes.
 *
 * `Route::bind()` is application-wide, not per addon. goldnead/statamic-webhook-manager
 * binds the parameter name `rule` to its own Rule repository in its provider,
 * and that binding applies to every route in every package that uses the same
 * parameter name. v1.8.0 shipped these two routes as `/scoring/{rule}`, and on
 * any install with both addons the webhook manager resolved them against its own
 * repository, found nothing, and returned 404 — a Hub where the delete button
 * did nothing, while the addon's own suite was green because the sibling addon
 * is not installed here.
 *
 * The binding below is exactly what that provider registers. It makes the
 * collision reproducible without the addon, and it fails on a parameter named
 * `rule` no matter how the controller is written.
 */
it('survives a sibling addon binding the parameter name "rule"', function (): void {
    Route::bind('rule', function () {
        abort(404);
    });

    $target = ScoringRule::create(['event_type' => 'purchase.completed', 'points' => 10]);

    $this->patch(cp_route('leadhub.scoring.update', $target->id), ['points' => 12])
        ->assertRedirect();
    expect($target->fresh()->points)->toBe(12);

    $this->delete(cp_route('leadhub.scoring.destroy', $target->id))
        ->assertRedirect();
    expect(ScoringRule::query()->find($target->id))->toBeNull();
});
