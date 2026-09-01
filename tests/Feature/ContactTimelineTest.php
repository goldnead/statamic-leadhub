<?php

use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Support\Timeline\ContactTimeline;
use Goldnead\Leadhub\Support\Timeline\TimelineEntry;
use Goldnead\Leadhub\Tests\Fixtures\Timeline\ScriptedSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;

require_once __DIR__.'/../Fixtures/Timeline/NeighbourStubs.php';

/**
 * One page per person: the merged timeline and the numbers above it.
 *
 * What is guaranteed here is the merge — order, the hiding of bridge events a
 * source makes redundant, the numbers, and that a source which throws leaves a
 * page behind rather than a 500. The four real readers have their own file
 * (TimelineSourcesTest); this one drives the builder with scripted sources so
 * the guarantees do not depend on any neighbour's schema.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The contact screen props target the eloquent driver.');
    }

    $user = User::make()->email('timeline@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    $this->contact = Contact::create([
        'email' => 'Reader@Example.com',
        'created_at' => Carbon::parse('2026-01-10 10:00:00'),
    ]);

    $this->timeline = app(ContactTimeline::class);

    $this->props = fn () => json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.contacts.show', $this->contact->uuid))
            ->assertStatus(200)
            ->getContent(),
        true,
    )['props'] ?? [];

    $this->entry = fn (string $id, string $at, array $extra = []) => new TimelineEntry(
        id: $id,
        source: $extra['source'] ?? 'scripted',
        kind: $extra['kind'] ?? 'scripted.thing',
        at: Carbon::parse($at),
        summary: $extra['summary'] ?? $id,
        url: $extra['url'] ?? null,
        badge: $extra['badge'] ?? null,
        amount: $extra['amount'] ?? null,
    );
});

it('reports every built-in neighbour as absent on an install without them', function (): void {
    $props = ($this->props)();

    $byKey = collect($props['timelineSources'])->keyBy('key');

    expect($byKey->keys()->all())->toBe(['leadhub', 'payments', 'entitlements', 'booking', 'consent'])
        ->and($byKey['leadhub']['available'])->toBeTrue()
        ->and($byKey['payments']['available'])->toBeFalse()
        ->and($byKey['entitlements']['available'])->toBeFalse()
        ->and($byKey['booking']['available'])->toBeFalse()
        ->and($byKey['consent']['available'])->toBeFalse()
        ->and($props['accessGrant'])->toBeNull();
});

it('shows the empty state for a person nothing has happened to', function (): void {
    $props = ($this->props)();

    expect($props['timeline'])->toBe([])
        ->and($props['timelineTotal'])->toBe(0)
        ->and($props['stats']['purchase_count'])->toBe(0)
        ->and($props['stats']['lifetime_value'])->toBe([])
        ->and($props['stats']['active_access'])->toBeNull()
        // A contact with no events still has a first contact: the day the
        // record was made.
        ->and($props['stats']['first_contact_at'])->toStartWith('2026-01-10');
});

it('merges a source with the contact\'s own events, newest first', function (): void {
    app(EventRepository::class)->record($this->contact, Event::TYPE_NOTE_ADDED, 'A note');
    Event::query()->where('contact_id', $this->contact->id)->update(['created_at' => '2026-02-01 09:00:00']);

    $this->timeline->register(new ScriptedSource('scripted', [
        ($this->entry)('older', '2026-01-15 09:00:00'),
        ($this->entry)('newer', '2026-03-01 09:00:00', ['url' => '/cp/somewhere', 'badge' => ['text' => 'Paid', 'color' => 'green']]),
    ]));

    $props = ($this->props)();
    $ids = array_column($props['timeline'], 'id');

    expect($ids)->toBe(['newer', 'leadhub:'.Event::query()->where('contact_id', $this->contact->id)->value('uuid'), 'older'])
        ->and($props['timeline'][0]['url'])->toBe('/cp/somewhere')
        ->and($props['timeline'][0]['badge'])->toBe(['text' => 'Paid', 'color' => 'green'])
        ->and($props['timeline'][1]['source'])->toBe('leadhub')
        ->and($props['timeline'][1]['kind'])->toBe('leadhub.note_added')
        ->and($props['timelineTotal'])->toBe(3);
});

it('hides the bridge events a source supersedes, and keeps them when the source is absent', function (): void {
    // Payments' bridge writes this type into leadhub_events for every purchase.
    app(EventRepository::class)->record($this->contact, 'payments.purchase_completed', 'Bought from the bridge');
    app(EventRepository::class)->record($this->contact, Event::TYPE_NOTE_ADDED, 'Unrelated note');

    $ledger = new ScriptedSource('ledger', [($this->entry)('ledger-1', '2026-01-20 09:00:00')], [], ['payments.']);

    $this->timeline->register($ledger);
    $kinds = array_column(($this->props)()['timeline'], 'kind');

    expect($kinds)->not->toContain('leadhub.payments.purchase_completed')
        ->and($kinds)->toContain('leadhub.note_added')
        ->and($kinds)->toContain('scripted.thing');

    // Same source, switched off in config: the bridge event is the only record
    // of the purchase again and must come back.
    config()->set('leadhub.timeline.sources.ledger', false);
    $kinds = array_column(($this->props)()['timeline'], 'kind');

    expect($kinds)->toContain('leadhub.payments.purchase_completed')
        ->and($kinds)->not->toContain('scripted.thing');
});

it('takes the headline numbers from the sources that know them', function (): void {
    $this->timeline->register(new ScriptedSource('shop', [
        ($this->entry)('p1', '2026-02-01 09:00:00', ['amount' => ['cent' => 2900, 'currency' => 'EUR']]),
        ($this->entry)('p2', '2026-02-05 09:00:00', ['amount' => ['cent' => 1500, 'currency' => 'CHF']]),
    ], ['purchase_count' => 2, 'lifetime_value' => ['EUR' => 2900, 'CHF' => 1500]]));
    $this->timeline->register(new ScriptedSource('access', [], ['active_access' => 3]));

    $stats = ($this->props)()['stats'];

    expect($stats['purchase_count'])->toBe(2)
        ->and(array_column($stats['lifetime_value'], 'cent', 'currency'))->toBe(['CHF' => 1500, 'EUR' => 2900])
        ->and($stats['lifetime_value'][0]['formatted'])->toContain('15')
        ->and($stats['active_access'])->toBe(3)
        // First contact is the earlier of the record's creation and the
        // oldest entry; last contact the newest entry.
        ->and($stats['first_contact_at'])->toStartWith('2026-01-10')
        ->and($stats['last_contact_at'])->toStartWith('2026-02-05');
});

it('falls back to the contact\'s own revenue ledger when no source counts purchases', function (): void {
    $this->contact->forceFill(['purchase_count' => 4, 'revenue_cent' => 39600, 'revenue_currency' => 'EUR'])->save();

    $stats = ($this->props)()['stats'];

    expect($stats['purchase_count'])->toBe(4)
        ->and(array_column($stats['lifetime_value'], 'cent', 'currency'))->toBe(['EUR' => 39600]);
});

it('renders the page when a source throws, and says so in the log', function (): void {
    Log::shouldReceive('warning')
        ->atLeast()->once()
        ->withArgs(fn (string $message) => str_contains($message, 'broken'));

    $this->timeline->register(new ScriptedSource('broken', throws: new RuntimeException('mid-upgrade')));
    $this->timeline->register(new ScriptedSource('fine', [($this->entry)('ok', '2026-01-20 09:00:00')]));

    $props = ($this->props)();
    $broken = collect($props['timelineSources'])->firstWhere('key', 'broken');

    // The page stands, and it says what is missing: a source that threw is
    // not "available" — its entries are not in the list — and is named as
    // failed, so the screen can say so instead of showing a green chip.
    expect(array_column($props['timeline'], 'id'))->toBe(['ok'])
        ->and($broken['available'])->toBeFalse()
        ->and($broken['failed'])->toBeTrue()
        ->and(collect($props['timelineSources'])->firstWhere('key', 'fine')['failed'])->toBeFalse()
        ->and($this->timeline->build($this->contact)['failed'])->toBe(['broken']);
});

it('registers a host source through the facade', function (): void {
    LeadHub::registerTimelineSource(new ScriptedSource('host', [($this->entry)('h1', '2026-01-20 09:00:00')]));

    expect(array_keys($this->timeline->sources()))->toContain('host')
        ->and(array_column(($this->props)()['timeline'], 'id'))->toBe(['h1']);
});

it('matches on the normalized address and the raw one, once each', function (): void {
    expect($this->timeline->emailsFor($this->contact))->toBe(['reader@example.com']);

    $contact = Contact::create(['email' => '  Mixed.Case@Example.com ']);

    expect($this->timeline->emailsFor($contact))->toBe(['mixed.case@example.com']);
});
