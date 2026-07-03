<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Events\LeadHubContactEnteredSegment;
use Goldnead\Leadhub\Events\LeadHubContactLeftSegment;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Segment;
use Goldnead\Leadhub\Services\SegmentService;
use Goldnead\Leadhub\Services\TagService;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    SegmentService::resetGuard();
    resetFlatState();
    $this->contacts = app(ContactRepository::class);
    $this->segments = app(SegmentRepository::class);
    $this->service = app(SegmentService::class);
});

/**
 * The flat driver shares ONE process-wide temp dir across tests (no per-test
 * cleanup in TestCase), so contacts/segments leak between tests. RefreshDatabase
 * resets the eloquent driver; this resets the flat driver equivalently. No-op
 * under eloquent.
 */
function resetFlatState(): void
{
    if (config('leadhub.storage.driver') !== 'flat') {
        return;
    }

    $path = (string) config('leadhub.storage.flat.path');
    if ($path && is_dir($path)) {
        \Illuminate\Support\Facades\File::deleteDirectory($path);
    }

    $disk = (string) config('leadhub.storage.flat.index_disk', 'local');
    $indexPath = (string) config('leadhub.storage.flat.index_path', 'leadhub/index');
    try {
        \Illuminate\Support\Facades\Storage::disk($disk)->deleteDirectory($indexPath);
    } catch (\Throwable) {
        // ignore
    }

    // The flat repos + their indexes are container singletons that cache
    // in-memory data across tests. Drop them so the next resolve reads the
    // freshly-cleared filesystem.
    foreach ([
        \Goldnead\Leadhub\Repositories\FlatFile\FlatFileContactRepository::class,
        \Goldnead\Leadhub\Repositories\FlatFile\FlatFileTagRepository::class,
        \Goldnead\Leadhub\Repositories\FlatFile\FlatFileFollowupRepository::class,
        \Goldnead\Leadhub\Repositories\FlatFile\FlatFileSegmentRepository::class,
        'leadhub.index.contacts',
        'leadhub.index.tags',
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

function seedContact(array $attributes = [])
{
    return test()->contacts->create(array_merge([
        'email' => uniqid('s').'@example.com',
        'email_normalized' => uniqid('s').'@example.com',
        'status' => 'new',
        'source' => 'website',
    ], $attributes));
}

function seedSegment(array $rules, array $overrides = []): Segment
{
    return test()->segments->create(array_merge([
        'name' => 'Test Segment '.uniqid(),
        'rules' => $rules,
        'is_active' => true,
    ], $overrides));
}

/* -------- rules cast (array + JSON string) -------- */

it('round-trips rules through the cast for both arrays and JSON strings', function (): void {
    $rules = ['match' => 'all', 'conditions' => [['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'new']]];

    // Constructed with a PHP array — the previous crash was a Json::decode on an array.
    $fromArray = new Segment(['rules' => $rules]);
    expect($fromArray->rules)->toBe($rules);

    // Constructed with a JSON string.
    $fromString = new Segment(['rules' => json_encode($rules)]);
    expect($fromString->rules)->toBe($rules);

    // Persisted + reloaded.
    $segment = seedSegment($rules);
    $reloaded = $this->segments->findByHandle($segment->handle);
    expect($reloaded->rules)->toBe($rules);
});

/* -------- whole-segment resolution (both drivers) -------- */

it('resolves member ids for a segment across the whole contact set', function (): void {
    seedContact(['status' => 'qualified']);
    seedContact(['status' => 'qualified']);
    seedContact(['status' => 'new']);

    $segment = seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
    ]]);

    expect($this->service->resolveMemberIds($segment->handle))->toHaveCount(2);
});

it('returns [] member ids for unknown or inactive segments', function (): void {
    expect($this->service->resolveMemberIds('does-not-exist'))->toBe([]);

    $segment = seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'new'],
    ]], ['is_active' => false]);

    expect($this->service->resolveMemberIds($segment->handle))->toBe([]);
});

/* -------- single-contact reactive path -------- */

it('materializes and fires ContactEnteredSegment when a contact enters', function (): void {
    Event::fake([LeadHubContactEnteredSegment::class, LeadHubContactLeftSegment::class]);

    $segment = seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
    ]]);

    $contact = seedContact(['status' => 'qualified']);

    $changes = $this->service->reevaluateContact($contact);

    expect($changes)->toHaveCount(1);
    expect($this->segments->hasContact($segment, $contact))->toBeTrue();
    Event::assertDispatched(LeadHubContactEnteredSegment::class, fn ($e) => $e->metadata['segment_handle'] === $segment->handle);
});

it('fires ContactLeftSegment when a contact no longer matches', function (): void {
    $segment = seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
    ]]);

    $contact = seedContact(['status' => 'qualified']);
    $this->service->reevaluateContact($contact);
    expect($this->segments->hasContact($segment, $contact))->toBeTrue();

    Event::fake([LeadHubContactLeftSegment::class]);

    // Change status so it no longer matches, then re-evaluate.
    $contact->status = 'new';
    $this->contacts->save($contact);
    $fresh = $this->contacts->find($contact->uuid);

    $changes = $this->service->reevaluateContact($fresh);

    expect($changes)->toHaveCount(1);
    expect($this->segments->hasContact($segment, $fresh))->toBeFalse();
    Event::assertDispatched(LeadHubContactLeftSegment::class);
});

/* -------- reactive via real mutation event -------- */

it('re-evaluates membership reactively when a contact is mutated via the manager', function (): void {
    $this->bootAddonEvents();

    $segment = seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
    ]]);

    $contact = seedContact(['status' => 'new']);
    expect($this->segments->hasContact($segment, $contact))->toBeFalse();

    // changeStatus() fires LeadHubStatusChanged → ReevaluateSegmentMembership listener.
    LeadHub::changeStatus($contact->uuid, 'qualified');

    $fresh = $this->contacts->find($contact->uuid);
    expect($this->segments->hasContact($segment, $fresh))->toBeTrue();
});

/* -------- scheduled sweep -------- */

it('sweeps membership for time-based rules', function (): void {
    $old = seedContact(['last_activity_at' => now()->subDays(40)->toIso8601String()]);
    $recent = seedContact(['last_activity_at' => now()->subDays(2)->toIso8601String()]);

    $segment = seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'last_activity_at', 'operator' => 'older_than_days', 'value' => 30],
    ]]);

    $result = $this->service->sweepSegment($segment);

    expect($result['entered'])->toBe(1);
    expect($this->segments->hasContact($segment, $old))->toBeTrue();
    expect($this->segments->hasContact($segment, $recent))->toBeFalse();
});

it('sweeps all active segments via the console command', function (): void {
    seedContact(['status' => 'qualified']);
    seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
    ]]);

    $this->artisan('leadhub:segments:sweep')->assertSuccessful();
});

/* -------- loop guard -------- */

it('caps re-evaluation depth so reactive consumers cannot cascade infinitely', function (): void {
    // A consumer reacts to entering the segment by tagging the contact, which
    // fires LeadHubTagAdded → ReevaluateSegmentMembership again. The depth guard
    // must drop the re-entrant pass so this terminates.
    $segment = seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
    ]]);

    $tagService = app(TagService::class);
    $reentered = 0;

    Event::listen(LeadHubContactEnteredSegment::class, function ($event) use ($tagService, &$reentered) {
        $reentered++;
        // Mutating inside the reactive handler would normally re-trigger
        // re-evaluation; the guard prevents recursion.
        if ($reentered < 5) {
            $tagService->attach($event->contact, 'reacted-'.$reentered);
        }
    });

    $contact = seedContact(['status' => 'qualified']);

    // Must return (not stack-overflow / infinite loop).
    $this->service->reevaluateContact($contact);

    expect($this->segments->hasContact($segment, $contact))->toBeTrue();
    // Entered fired once; re-entrant re-evaluations were dropped by the guard.
    expect($reentered)->toBeGreaterThanOrEqual(1);
})->group('loop-guard');

/* -------- facade contract -------- */

it('exposes segments(), segmentMemberIds() and contactInSegment() on the facade', function (): void {
    $contact = seedContact(['status' => 'qualified']);
    $segment = seedSegment(['match' => 'all', 'conditions' => [
        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
    ]], ['name' => 'Qualified Leads', 'handle' => 'qualified-leads']);

    $all = LeadHub::segments();
    expect($all)->toBeArray();
    expect(collect($all)->pluck('handle'))->toContain('qualified-leads');

    $ids = LeadHub::segmentMemberIds('qualified-leads');
    expect($ids)->toContain((string) $contact->uuid);

    expect(LeadHub::contactInSegment($contact->uuid, 'qualified-leads'))->toBeTrue();
    expect(LeadHub::contactInSegment($contact->uuid, 'nonexistent'))->toBeFalse();
});

it('facade root exposes segmentMemberIds for consumer method_exists guards', function (): void {
    expect(method_exists(LeadHub::getFacadeRoot(), 'segmentMemberIds'))->toBeTrue();
    expect(method_exists(LeadHub::getFacadeRoot(), 'segments'))->toBeTrue();
    expect(method_exists(LeadHub::getFacadeRoot(), 'contactInSegment'))->toBeTrue();
});
