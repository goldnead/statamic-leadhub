<?php

/**
 * The migration that clears contradictory outcome stamps.
 *
 * `StageTransitionService` set `won_at`/`lost_at` and never cleared them, while
 * it did clear `status`, `outcome` and `closed_at` beside them. Nothing
 * rendered those columns, so nobody saw it — the deal screen does, and `won_at`
 * is the column a revenue report groups by.
 *
 * The migration's own claim is what is under test here, not just its effect: it
 * says nothing is lost because every stamp has a transition row behind it. That
 * is true only while the stage named by the transition still exists and still
 * declares the same outcome, and an empty stage is deletable. So the values are
 * parked before they are cleared, and this pins that they are.
 */

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The repair targets the eloquent driver.');
    }

    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
    ]);

    $pipeline = Pipeline::query()->firstOrFail();
    $contact = Contact::create(['email' => 'repair@example.com']);

    $this->deal = fn (array $attributes) => Opportunity::create(array_merge([
        'contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $pipeline->stages()->first()->id,
        'title' => 'Repair me',
    ], $attributes));

    // The migration has already run against this schema (RefreshDatabase
    // migrates to head), so it is invoked by hand on rows written afterwards.
    $this->repair = function (): void {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_08_15_000001_repair_leadhub_opportunity_outcome_stamps.php';
        (require $path)->up();
    };
});

it('clears a won stamp from a deal that was reopened', function (): void {
    $deal = ($this->deal)([
        'status' => Opportunity::STATUS_OPEN,
        'outcome' => null,
        'won_at' => now()->subMonth(),
    ]);

    ($this->repair)();

    expect($deal->refresh()->won_at)->toBeNull();
});

it('parks what it clears, because the claim that nothing is lost has a condition', function (): void {
    // Reading a won date back out of the history means joining the transition's
    // `to_stage_id` to the stage's `terminal_outcome`. That join has no foreign
    // key on purpose, an empty stage can be deleted, and the outcome is
    // editable — so for a reopened deal, whose former Won stage may well be
    // empty, the transition can stop answering the question.
    $stamp = now()->subMonth()->startOfSecond();

    $deal = ($this->deal)([
        'status' => Opportunity::STATUS_OPEN,
        'outcome' => null,
        'won_at' => $stamp,
    ]);

    ($this->repair)();

    $parked = $deal->refresh()->metadata_json['repaired_outcome_stamps'] ?? null;

    expect($parked)->not->toBeNull()
        ->and($parked['status'])->toBe('open');
    expect(Carbon::parse($parked['won_at'])->startOfSecond()->eq($stamp))->toBeTrue();
});

it('leaves a coherent deal alone', function (): void {
    $won = now()->subMonth();

    $deal = ($this->deal)([
        'status' => Opportunity::STATUS_CLOSED,
        'outcome' => 'won',
        'won_at' => $won,
        'closed_at' => $won,
    ]);

    ($this->repair)();

    expect($deal->refresh()->won_at)->not->toBeNull()
        ->and($deal->refresh()->metadata_json['repaired_outcome_stamps'] ?? null)->toBeNull();
});

it('runs twice without overwriting the first parking', function (): void {
    $deal = ($this->deal)([
        'status' => Opportunity::STATUS_OPEN,
        'outcome' => null,
        'won_at' => now()->subMonth(),
    ]);

    ($this->repair)();
    $first = $deal->refresh()->metadata_json['repaired_outcome_stamps'];

    // A second run finds a row with no stamps left, so there is nothing to
    // park — and must not replace the record of what was there.
    ($this->repair)();

    expect($deal->refresh()->metadata_json['repaired_outcome_stamps'])->toBe($first);
});

it('clears the stamp the outcome does not name, on a deal that went won then lost', function (): void {
    $deal = ($this->deal)([
        'status' => Opportunity::STATUS_CLOSED,
        'outcome' => 'lost',
        'won_at' => now()->subMonths(2),
        'lost_at' => now()->subMonth(),
        'closed_at' => now()->subMonth(),
    ]);

    ($this->repair)();

    expect($deal->refresh()->won_at)->toBeNull()
        ->and($deal->refresh()->lost_at)->not->toBeNull()
        ->and($deal->refresh()->metadata_json['repaired_outcome_stamps']['won_at'] ?? null)->not->toBeNull();
});
