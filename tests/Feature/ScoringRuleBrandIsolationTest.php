<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\ScoringRule;
use Goldnead\Leadhub\Services\ScoringService;
use Statamic\Facades\User;

/**
 * Per-brand rules, seen from the wrong brand.
 *
 * Two halves, and the second is the one that matters:
 *
 * 1. **Visibility.** A rule of brand A must not appear in brand B's screen and
 *    must not be reachable by id. This is the ordinary leak, and it is loud —
 *    somebody sees a row that says another tenant's name.
 * 2. **Arithmetic.** A rule of brand A must not COMPUTE in brand B. This one is
 *    silent: nothing appears anywhere, a contact simply gets the wrong number
 *    of points, the number moves segment membership, and segment membership
 *    decides who receives mail. There is no screen on which that shows up as
 *    an error, which is exactly why it is asserted here rather than looked at.
 *
 * The brand is switched through BrandContext before each request, which is what
 * brand-context's CP middleware does on a real request — the middleware group
 * is not applied to addon CP routes under orchestra/testbench, so driving this
 * via `?brand=<handle>` would silently test the default brand and every
 * assertion below would pass for the wrong reason.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Scoring rules live in a table; the flat driver has none.');
    }

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    config()->set('leadhub.features.scoring', true);

    $this->brandA = Brand::create(['handle' => 'score-a', 'name' => 'Score A']);
    $this->brandB = Brand::create(['handle' => 'score-b', 'name' => 'Score B']);

    $this->user = User::make()->email('scoring-brands@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    $this->ruleA = BrandContext::runFor($this->brandA, fn () => ScoringRule::create([
        'event_type' => 'purchase.completed',
        'points' => 50,
        'label' => 'Brand A purchase',
    ]));
});

it('does not list brand A rules in brand B', function (): void {
    BrandContext::setCurrent($this->brandB);

    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.scoring.index'));

    $response->assertStatus(200);
    $props = json_decode($response->getContent(), true)['props'];

    expect($props['rules'])->toBe([])
        // And the screen says why the list is empty, instead of leaving it to
        // read as "nothing is scored here".
        ->and($props['usingConfigFallback'])->toBeTrue();
});

it('does not let brand B reach a brand A rule by id', function (): void {
    BrandContext::setCurrent($this->brandB);

    $this->patch(cp_route('leadhub.scoring.update', $this->ruleA->id), ['points' => 1])
        ->assertStatus(404);
    $this->delete(cp_route('leadhub.scoring.destroy', $this->ruleA->id))
        ->assertStatus(404);

    expect(ScoringRule::query()->forBrand($this->brandA)->first()->points)->toBe(50);
});

it('does not let a brand A rule compute in brand B', function (): void {
    // Brand A: the rule decides.
    $inA = BrandContext::runFor($this->brandA, fn () => app(ScoringService::class)->pointsFor('purchase.completed'));

    // Brand B: has no rules at all, so the config table decides — and the
    // config table says 10 for this type, not brand A's 50.
    $inB = BrandContext::runFor($this->brandB, fn () => app(ScoringService::class)->pointsFor('purchase.completed'));

    // Indexed by hand, not via config('…events.purchase.completed'): the key
    // contains a dot, so dot notation would silently miss it and hand back the
    // default — the assertion would then compare the value against itself.
    $fromConfig = (int) ((array) config('leadhub.scoring.events'))['purchase.completed'];

    expect($inA)->toBe(50)
        ->and($fromConfig)->toBe(10)
        ->and($inB)->toBe($fromConfig)
        ->and($inB)->not->toBe($inA);
});

it('awards each brand its own points for the same activity', function (): void {
    // The whole feature in one assertion: the same activity type, on comparable
    // contacts, produces different scores in different tenants.
    BrandContext::runFor($this->brandB, fn () => ScoringRule::create([
        'event_type' => 'purchase.completed',
        'points' => 3,
    ]));

    $scoreA = BrandContext::runFor($this->brandA, function () {
        $contact = Contact::create(['email' => 'a-buyer@example.com']);
        app(ScoringService::class)->award($contact, 'purchase.completed');

        return (int) $contact->fresh()->engagement_score;
    });

    $scoreB = BrandContext::runFor($this->brandB, function () {
        $contact = Contact::create(['email' => 'b-buyer@example.com']);
        app(ScoringService::class)->award($contact, 'purchase.completed');

        return (int) $contact->fresh()->engagement_score;
    });

    expect($scoreA)->toBe(50)
        ->and($scoreB)->toBe(3);
});

it('does not let a brand A catch-all rule set brand B\'s baseline', function (): void {
    // The catch-all is the rule with the widest blast radius: it applies to
    // every activity type nobody configured. A leaking catch-all would rescore
    // an entire tenant.
    BrandContext::runFor($this->brandA, fn () => ScoringRule::create([
        'event_type' => ScoringRule::CATCH_ALL,
        'points' => 99,
    ]));

    $inB = BrandContext::runFor($this->brandB, fn () => app(ScoringService::class)->pointsFor('nothing.configured'));

    expect($inB)->toBe((int) config('leadhub.scoring.default', 1))
        ->and($inB)->not->toBe(99);
});

it('creates a rule into the brand that is current', function (): void {
    BrandContext::setCurrent($this->brandB);

    $this->post(cp_route('leadhub.scoring.store'), ['event_type' => 'webinar.attended', 'points' => 4])
        ->assertRedirect();

    $created = ScoringRule::query()->acrossBrands()->where('event_type', 'webinar.attended')->firstOrFail();

    expect($created->brand_id)->toBe($this->brandB->id);
});

it('lets both brands hold a rule for the same activity type', function (): void {
    // The unique is (brand_id, event_type). Without the brand column in it, the
    // second brand could never write its own rule for a type the first already
    // has — which would make "per brand" a claim rather than a fact.
    BrandContext::setCurrent($this->brandB);

    $this->post(cp_route('leadhub.scoring.store'), ['event_type' => 'purchase.completed', 'points' => 3])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ScoringRule::query()->acrossBrands()->where('event_type', 'purchase.completed')->count())->toBe(2);
});
