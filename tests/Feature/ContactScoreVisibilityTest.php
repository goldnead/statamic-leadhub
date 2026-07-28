<?php

use Goldnead\Leadhub\Models\Contact;
use Statamic\Facades\User;

/**
 * The engagement score, on screens.
 *
 * Until v1.8.0 the number existed, moved, and appeared nowhere: not on the
 * contact, not in the list, not sortable, not filterable. The only place it
 * occurred in the whole Vue layer was as a selectable field in the segment rule
 * builder — one could filter on something that could not be seen anywhere.
 *
 * These tests go through the real CP routes and read the Inertia props, which
 * is the closest a PHP test gets to "it is on the screen".
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The score column and filter are eloquent-repository features.');
    }

    $this->user = User::make()->email('score-visibility@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.scoring', true);

    $this->cold = Contact::create(['email' => 'cold@example.com', 'engagement_score' => 0]);
    $this->warm = Contact::create(['email' => 'warm@example.com', 'engagement_score' => 12]);
    $this->hot = Contact::create(['email' => 'hot@example.com', 'engagement_score' => 40]);
});

function scoreIndexProps($test, array $query = []): array
{
    $url = cp_route('leadhub.contacts.index').($query ? '?'.http_build_query($query) : '');
    $response = $test->withHeaders(['X-Inertia' => 'true'])->get($url);

    $response->assertStatus(200);

    return json_decode($response->getContent(), true)['props'];
}

it('puts the score on the contact detail page', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.contacts.show', $this->warm->uuid));

    $response->assertStatus(200);
    $props = json_decode($response->getContent(), true)['props'];

    expect($props['contact']['engagement_score'])->toBe(12);
});

it('puts the score in the contact list, as a column and a value', function (): void {
    $props = scoreIndexProps($this);

    expect(collect($props['columns'])->pluck('field'))->toContain('engagement_score')
        ->and($props['scoringEnabled'])->toBeTrue();

    $rows = collect($props['contacts'])->keyBy('email');

    expect($rows['hot@example.com']['engagement_score'])->toBe(40)
        ->and($rows['warm@example.com']['engagement_score'])->toBe(12)
        ->and($rows['cold@example.com']['engagement_score'])->toBe(0);
});

it('hides the score entirely while the feature is off', function (): void {
    config()->set('leadhub.features.scoring', false);

    $props = scoreIndexProps($this);

    expect(collect($props['columns'])->pluck('field'))->not->toContain('engagement_score')
        ->and($props['scoringEnabled'])->toBeFalse()
        ->and($props['contacts'][0]['engagement_score'])->toBeNull();

    $show = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.contacts.show', $this->warm->uuid));

    expect(json_decode($show->getContent(), true)['props']['contact']['engagement_score'])->toBeNull();
});

it('filters the contact list by a score floor', function (): void {
    $props = scoreIndexProps($this, ['score_min' => 10]);

    expect(collect($props['contacts'])->pluck('email')->sort()->values()->all())
        ->toBe(['hot@example.com', 'warm@example.com']);
});

it('filters the contact list by a score ceiling', function (): void {
    $props = scoreIndexProps($this, ['score_max' => 12]);

    expect(collect($props['contacts'])->pluck('email')->sort()->values()->all())
        ->toBe(['cold@example.com', 'warm@example.com']);
});

it('keeps a floor of zero as a filter instead of discarding it', function (): void {
    // `! empty()` would throw away score_min=0, and 0 is the score every
    // contact starts at — the one value somebody filters on to find the leads
    // nothing has happened to yet.
    $props = scoreIndexProps($this, ['score_min' => 0, 'score_max' => 0]);

    expect(collect($props['contacts'])->pluck('email')->all())->toBe(['cold@example.com']);
});

it('sorts the contact list by score, server-side', function (): void {
    $props = scoreIndexProps($this, ['sort' => 'engagement_score', 'direction' => 'desc']);

    expect(collect($props['contacts'])->pluck('email')->all())
        ->toBe(['hot@example.com', 'warm@example.com', 'cold@example.com'])
        ->and($props['scoreSort'])->toBe('desc');

    $asc = scoreIndexProps($this, ['sort' => 'engagement_score', 'direction' => 'asc']);

    expect(collect($asc['contacts'])->pluck('email')->all())
        ->toBe(['cold@example.com', 'warm@example.com', 'hot@example.com']);
});

it('ignores the score filter while the feature is off', function (): void {
    // Otherwise an install that never enabled scoring could filter every
    // contact out of its own list on a column of zeros.
    config()->set('leadhub.features.scoring', false);

    $props = scoreIndexProps($this, ['score_min' => 10]);

    expect($props['contacts'])->toHaveCount(3);
});
