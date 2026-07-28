<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Segment;
use Illuminate\Support\Facades\DB;

/**
 * The denormalized brand_id on leadhub_segment_contact.
 *
 * v1.5.0 made the column real for leadhub_contact_company and
 * leadhub_contact_tag, which are reached through Eloquent relations. Segment
 * membership is written and read by raw query-builder calls in
 * EloquentSegmentRepository, so there was no relation for that fix to hang on
 * and the column stayed what it had been: a comment in a migration.
 *
 * The point is the same as for the other two pivots. With the models' global
 * BrandScope on, nothing leaks. The column is what still holds when the scope is
 * deliberately off — cross-brand reporting inside
 * BrandContext::withoutBrandScope(), and console commands iterating brands. The
 * cross-brand test below is the one that fails the moment the pivot filter is
 * removed again.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The segment pivot is eloquent-only.');
    }

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'seg-a', 'name' => 'Segment A']);
    $this->brandB = Brand::create(['handle' => 'seg-b', 'name' => 'Segment B']);

    $this->segments = app(SegmentRepository::class);
});

it('stamps the brand on the membership row when a contact joins a segment', function (): void {
    [$segment, $contact] = BrandContext::runFor($this->brandA, fn () => [
        Segment::create(['name' => 'Newsletter', 'handle' => 'newsletter']),
        Contact::create(['email' => 'joins@example.com']),
    ]);

    BrandContext::runFor($this->brandA, fn () => $this->segments->addContact($segment, $contact));

    $row = DB::table('leadhub_segment_contact')->where('segment_id', $segment->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->brand_id)->toBe($this->brandA->id);
});

it('counts and lists only the memberships of its own brand', function (): void {
    $segment = BrandContext::runFor($this->brandA, fn () => Segment::create(['name' => 'Aktive', 'handle' => 'aktive']));
    $own = BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'own@example.com']));
    $foreign = BrandContext::runFor($this->brandB, fn () => Contact::create(['email' => 'foreign@example.com']));

    BrandContext::runFor($this->brandA, fn () => $this->segments->addContact($segment, $own));

    // A mis-scoped importer (or any pre-fix write) putting brand B's contact
    // into brand A's segment.
    DB::table('leadhub_segment_contact')->insert([
        'segment_id' => $segment->id,
        'contact_id' => $foreign->id,
        'brand_id' => $this->brandB->id,
        'entered_at' => now(),
    ]);

    expect(DB::table('leadhub_segment_contact')->count())->toBe(2);

    BrandContext::runFor($this->brandA, function () use ($segment, $own, $foreign) {
        expect($this->segments->membersCount($segment))->toBe(1)
            ->and($this->segments->memberIds($segment))->toBe([(string) $own->uuid])
            ->and($this->segments->hasContact($segment, $own))->toBeTrue()
            ->and($this->segments->hasContact($segment, $foreign))->toBeFalse();
    });
});

it('does not let one brand remove another brand\'s membership', function (): void {
    $segment = BrandContext::runFor($this->brandA, fn () => Segment::create(['name' => 'Kunden', 'handle' => 'kunden']));
    $foreign = BrandContext::runFor($this->brandB, fn () => Contact::create(['email' => 'keep@example.com']));

    DB::table('leadhub_segment_contact')->insert([
        'segment_id' => $segment->id,
        'contact_id' => $foreign->id,
        'brand_id' => $this->brandB->id,
        'entered_at' => now(),
    ]);

    BrandContext::runFor($this->brandA, fn () => $this->segments->removeContact($segment, $foreign));

    expect(DB::table('leadhub_segment_contact')->count())->toBe(1);
});

it('reports a contact\'s segment handles only for its own brand', function (): void {
    $contact = BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'handles@example.com']));
    $ownSegment = BrandContext::runFor($this->brandA, fn () => Segment::create(['name' => 'Eigen', 'handle' => 'eigen']));
    $foreignSegment = BrandContext::runFor($this->brandB, fn () => Segment::create(['name' => 'Fremd', 'handle' => 'fremd']));

    BrandContext::runFor($this->brandA, fn () => $this->segments->addContact($ownSegment, $contact));

    DB::table('leadhub_segment_contact')->insert([
        'segment_id' => $foreignSegment->id,
        'contact_id' => $contact->id,
        'brand_id' => $this->brandB->id,
        'entered_at' => now(),
    ]);

    $handles = BrandContext::runFor($this->brandA, fn () => $this->segments->handlesForContact($contact));

    expect($handles)->toBe(['eigen']);
});

it('never hands a segment a contact from another brand, even with the model scope off', function (): void {
    $segment = BrandContext::runFor($this->brandA, fn () => Segment::create(['name' => 'Report', 'handle' => 'report']));
    $own = BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'alpha@example.com']));
    $foreign = BrandContext::runFor($this->brandB, fn () => Contact::create(['email' => 'beta@example.com']));

    BrandContext::runFor($this->brandA, fn () => $this->segments->addContact($segment, $own));

    DB::table('leadhub_segment_contact')->insert([
        'segment_id' => $segment->id,
        'contact_id' => $foreign->id,
        'brand_id' => $this->brandB->id,
        'entered_at' => now(),
    ]);

    // Cross-brand admin and reporting turn the global scope off on purpose. With
    // it off the Contact model filters nothing — the pivot brand_id is the only
    // thing left standing between brand A's segment and brand B's people.
    $emails = BrandContext::withoutBrandScope(
        fn () => $segment->contacts()->orderBy('email')->pluck('email')->all()
    );

    expect($emails)->toBe(['alpha@example.com']);

    $count = BrandContext::withoutBrandScope(fn () => $this->segments->membersCount($segment));

    expect($count)->toBe(1);
});

it('does not lose memberships whose pivot brand was backfilled from the segment', function (): void {
    $segment = BrandContext::runFor($this->brandA, fn () => Segment::create(['name' => 'Alt', 'handle' => 'alt']));
    $contact = BrandContext::runFor($this->brandA, fn () => Contact::create(['email' => 'legacy@example.com']));

    // A row as every membership looked before this fix: written by the raw
    // insert, with no brand stamp at all.
    DB::table('leadhub_segment_contact')->insert([
        'segment_id' => $segment->id,
        'contact_id' => $contact->id,
        'brand_id' => null,
        'entered_at' => now(),
    ]);

    expect(BrandContext::runFor($this->brandA, fn () => $this->segments->membersCount($segment)))->toBe(0);

    // The backfill migration is what makes such a row visible again — the same
    // statement 2026_07_28_000001 runs.
    DB::statement(
        'UPDATE leadhub_segment_contact SET brand_id = ('
        .'SELECT s.brand_id FROM leadhub_segments s WHERE s.id = leadhub_segment_contact.segment_id'
        .') WHERE brand_id IS NULL'
    );

    expect(BrandContext::runFor($this->brandA, fn () => $this->segments->membersCount($segment)))->toBe(1)
        ->and(BrandContext::runFor($this->brandA, fn () => $this->segments->memberIds($segment)))->toBe([(string) $contact->uuid]);
});
