<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event as TimelineEvent;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;
use Goldnead\Leadhub\Models\StageTransition;
use Goldnead\Leadhub\Services\StageTransitionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The deal's own screen.
 *
 * What is worth testing here is not that the page renders. It is the four
 * shapes of deal that used to have nowhere to be seen and therefore nowhere to
 * go wrong: one that was never moved and so has no transition row at all, one
 * whose stage was deleted out from under its history, one belonging to another
 * brand, and one that went into a terminal stage and came back out. Plus the
 * two numbers the screen invents rather than reads — how long the deal sat in
 * each stage, and how many queries that costs.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    $this->user = User::make()->email('opp-show@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.pipelines', true);
    config()->set('leadhub.features.tasks', true);

    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Qualified'],
        ['name' => 'Proposal'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
    ]);

    $this->pipeline = Pipeline::query()->firstOrFail();
    $this->stageNew = $this->pipeline->stages()->where('slug', 'new')->firstOrFail();
    $this->stageQualified = $this->pipeline->stages()->where('slug', 'qualified')->firstOrFail();
    $this->stageProposal = $this->pipeline->stages()->where('slug', 'proposal')->firstOrFail();
    $this->stageWon = $this->pipeline->stages()->where('slug', 'won')->firstOrFail();

    $this->contact = Contact::create(['email' => 'deal@example.com', 'first_name' => 'Dora']);

    $this->deal = fn (array $attributes = []) => Opportunity::create(array_merge([
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
        'stage_id' => $this->stageNew->id,
        'title' => 'Website relaunch',
        'value_estimate' => '4200.00',
        'confidence' => 40,
        'status' => Opportunity::STATUS_OPEN,
    ], $attributes));

    $this->showProps = fn (Opportunity $opportunity) => json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('leadhub.pipelines.opportunities.show', $opportunity->id))
            ->assertStatus(200)
            ->getContent(),
        true,
    );
});

/** Move a deal through the one service that writes history. */
function moveDeal(Opportunity $opportunity, Stage $stage, ?string $note = null, ?string $actor = null): StageTransition
{
    return app(StageTransitionService::class)->transition($opportunity, $stage, $note, $actor);
}

it('renders the deal screen with its facts', function (): void {
    $deal = ($this->deal)();

    $payload = ($this->showProps)($deal);

    expect($payload['component'])->toBe('leadhub::Pipelines/OpportunityShow');

    $props = $payload['props'];

    expect($props['opportunity']['title'])->toBe('Website relaunch')
        ->and($props['opportunity']['stage_name'])->toBe('New')
        ->and((float) $props['opportunity']['value_estimate'])->toBe(4200.0)
        ->and($props['opportunity']['confidence'])->toBe(40)
        ->and($props['opportunity']['contact_name'])->toBe('Dora')
        // The contact is a link, which is the whole reason this screen sits
        // between the board and the person.
        ->and($props['opportunity']['contact_url'])->toBe(cp_route('leadhub.contacts.show', $this->contact->id))
        ->and($props['canManage'])->toBeTrue()
        ->and($props['moveUrl'])->toBe(cp_route('leadhub.pipelines.move', $deal->id));
});

// ------------------------------------------------------------------- History

it('gives a deal that was never moved a full first history entry, not a gap', function (): void {
    $deal = ($this->deal)();

    expect(StageTransition::query()->where('opportunity_id', $deal->id)->count())->toBe(0);

    $history = ($this->showProps)($deal)['props']['history'];

    expect($history)->toHaveCount(1);

    $entry = $history[0];

    expect($entry['is_start'])->toBeTrue()
        ->and($entry['is_current'])->toBeTrue()
        ->and($entry['to_stage_name'])->toBe('New')
        ->and($entry['from_stage_name'])->toBeNull()
        // The entry point is the row's own creation, not a dash.
        ->and($entry['occurred_at'])->toBe($deal->created_at->format('Y-m-d H:i'))
        ->and($entry['duration_seconds'])->not->toBeNull()
        ->and($entry['duration_label'])->not->toBeNull();
});

it('opens the deal whose stage was deleted out from under its history', function (): void {
    $deal = ($this->deal)();

    moveDeal($deal, $this->stageQualified, 'Erstgespräch war gut.');

    // The stage the deal came out of is now empty, so it can be deleted — and
    // `from_stage_id` has no foreign key holding it back.
    $this->stageNew->delete();

    $props = ($this->showProps)($deal->refresh())['props'];

    $latest = $props['history'][0];

    expect($props['history'])->toHaveCount(2)
        ->and($latest['to_stage_name'])->toBe('Qualified')
        ->and($latest['from_stage_name'])->toBe(__('leadhub::pipelines.stage_removed'))
        ->and($latest['note'])->toBe('Erstgespräch war gut.')
        // The creation entry named the same gone stage.
        ->and($props['history'][1]['to_stage_name'])->toBe(__('leadhub::pipelines.stage_removed'));
});

it('measures how long the deal sat in each stage, with the newest still running', function (): void {
    $this->travelTo(Carbon::parse('2026-01-01 09:00:00'));
    $deal = ($this->deal)();

    $this->travelTo(Carbon::parse('2026-01-03 09:00:00'));   // 2 days in New
    moveDeal($deal, $this->stageQualified);

    $this->travelTo(Carbon::parse('2026-01-03 12:00:00'));   // 3 hours in Qualified
    moveDeal($deal, $this->stageProposal);

    $this->travelTo(Carbon::parse('2026-01-04 12:00:00'));   // 24 hours in Proposal
    moveDeal($deal, $this->stageQualified);

    $this->travelTo(Carbon::parse('2026-01-05 12:00:00'));   // 24 hours and counting

    $history = ($this->showProps)($deal->refresh())['props']['history'];

    // Three moves, four entries: the stage it was created in counts.
    expect($history)->toHaveCount(4)
        ->and(array_column($history, 'duration_seconds'))->toBe([86400, 86400, 10800, 172800])
        ->and($history[0]['is_current'])->toBeTrue()
        // A day and a bit reads as hours on purpose: the days label starts at
        // two, so no locale ever has to render ":count days" with count 1.
        ->and($history[0]['duration_label'])->toBe(__('leadhub::pipelines.duration_hours', ['count' => 24]))
        ->and($history[2]['duration_label'])->toBe(__('leadhub::pipelines.duration_hours', ['count' => 3]))
        ->and($history[3]['is_start'])->toBeTrue()
        ->and($history[3]['duration_label'])->toBe(__('leadhub::pipelines.duration_days', ['count' => 2]));
});

it('costs the same number of queries with 3 moves as with 30', function (): void {
    // One listener for the whole test. Registering it inside the closure below
    // would leave the first one attached for the second measurement and count
    // every query twice.
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $count = function (int $moves) use (&$queries): int {
        $deal = ($this->deal)();
        $stages = [$this->stageQualified, $this->stageProposal, $this->stageNew];

        for ($i = 0; $i < $moves; $i++) {
            moveDeal($deal, $stages[$i % 3], 'Runde '.$i, (string) $this->user->id());
        }

        $deal->refresh();
        $queries = 0;

        $props = ($this->showProps)($deal)['props'];
        $measured = $queries;

        expect($props['history'])->toHaveCount($moves + 1);

        return $measured;
    };

    $small = $count(3);
    $large = $count(30);

    expect($large)->toBe($small)
        // A floor under the assertion: if the page ever stopped querying at
        // all, "equal" would be true for the wrong reason.
        ->and($small)->toBeGreaterThan(0);
});

// -------------------------------------------------------- Changing the stage

it('records exactly one transition and one timeline entry for a stage change from the deal screen', function (): void {
    $deal = ($this->deal)();

    $this->post(cp_route('leadhub.pipelines.move', $deal->id), [
        'stage_id' => $this->stageQualified->id,
        'note' => 'Budget bestätigt, geht weiter.',
    ])->assertRedirect();

    $transitions = StageTransition::query()->where('opportunity_id', $deal->id)->get();

    expect($transitions)->toHaveCount(1)
        ->and($transitions->first()->note)->toBe('Budget bestätigt, geht weiter.')
        ->and($transitions->first()->to_stage_id)->toBe($this->stageQualified->id)
        ->and($deal->refresh()->stage_id)->toBe($this->stageQualified->id);

    expect(TimelineEvent::query()
        ->where('contact_id', $this->contact->id)
        ->where('type', TimelineEvent::TYPE_OPPORTUNITY_STAGE_CHANGED)
        ->count())->toBe(1);

    // And the note is on the screen, which is the only place it is ever
    // readable — the timeline event does not carry it.
    expect(($this->showProps)($deal)['props']['history'][0]['note'])
        ->toBe('Budget bestätigt, geht weiter.');
});

it('leaves no contradictory state when a deal is closed and reopened', function (): void {
    $deal = ($this->deal)();

    moveDeal($deal, $this->stageWon);
    $deal->refresh();

    expect($deal->status)->toBe(Opportunity::STATUS_CLOSED)
        ->and($deal->outcome)->toBe('won')
        ->and($deal->won_at)->not->toBeNull();

    moveDeal($deal, $this->stageQualified);
    $deal->refresh();

    // Before v2.4.0 `won_at` survived the reopening, so an open deal carried a
    // win date — invisible until a screen showed both.
    expect($deal->status)->toBe(Opportunity::STATUS_OPEN)
        ->and($deal->outcome)->toBeNull()
        ->and($deal->closed_at)->toBeNull()
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lost_at)->toBeNull();

    $props = ($this->showProps)($deal)['props'];

    expect($props['opportunity']['won_at'])->toBeNull()
        ->and($props['opportunity']['lost_at'])->toBeNull()
        ->and($props['opportunity']['closed_at'])->toBeNull()
        ->and($props['opportunity']['is_open'])->toBeTrue()
        // The history keeps what the columns no longer claim.
        ->and($props['history'])->toHaveCount(3);
});

it('carries only the stamp its outcome names when a deal moves from won to lost', function (): void {
    LeadHub::createPipeline('Losses', [
        ['name' => 'Open'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
        ['name' => 'Lost', 'is_terminal' => true, 'terminal_outcome' => 'lost'],
    ]);

    $pipeline = Pipeline::query()->where('slug', 'losses')->firstOrFail();

    $deal = Opportunity::create([
        'contact_id' => $this->contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $pipeline->stages()->where('slug', 'open')->firstOrFail()->id,
        'title' => 'Turned around',
        'status' => Opportunity::STATUS_OPEN,
    ]);

    moveDeal($deal, $pipeline->stages()->where('slug', 'won')->firstOrFail());
    moveDeal($deal->refresh(), $pipeline->stages()->where('slug', 'lost')->firstOrFail());

    $deal->refresh();

    expect($deal->outcome)->toBe('lost')
        ->and($deal->lost_at)->not->toBeNull()
        ->and($deal->won_at)->toBeNull();

    $props = ($this->showProps)($deal)['props'];

    expect($props['opportunity']['won_at'])->toBeNull()
        ->and($props['opportunity']['lost_at'])->not->toBeNull();
});

// ------------------------------------------------------- Guards and gateways

it('404s the deal screen when the pipelines feature is off, rather than 403', function (): void {
    $deal = ($this->deal)();

    config()->set('leadhub.features.pipelines', false);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.pipelines.opportunities.show', $deal->id))
        ->assertStatus(404);
});

it('refuses the deal screen to a user with no LeadHub permission', function (): void {
    $deal = ($this->deal)();

    $plain = User::make()->email('opp-show-nobody@example.com');
    $plain->save();
    $this->actingAs($plain);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.pipelines.opportunities.show', $deal->id))
        ->assertStatus(403);
});

it('shows the deal to a reader but offers them no action', function (): void {
    $deal = ($this->deal)();

    Role::make('lh-reader')->permissions(['view leadhub'])->save();
    $reader = User::make()->email('opp-show-reader@example.com');
    $reader->assignRole('lh-reader');
    $reader->save();
    $this->actingAs($reader);

    $props = ($this->showProps)($deal)['props'];

    expect($props['canManage'])->toBeFalse()
        ->and($props['moveUrl'])->toBeNull()
        ->and($props['editUrl'])->toBeNull()
        ->and($props['deleteUrl'])->toBeNull()
        // Reading is still the whole page, not a stub.
        ->and($props['history'])->toHaveCount(1);
});

it('lets a deal manager move a stage, which the old contact-only guard refused', function (): void {
    $deal = ($this->deal)();

    Role::make('lh-deals')->permissions(['view leadhub', 'manage leadhub opportunities'])->save();
    $manager = User::make()->email('opp-show-manager@example.com');
    $manager->assignRole('lh-deals');
    $manager->save();
    $this->actingAs($manager);

    $this->post(cp_route('leadhub.pipelines.move', $deal->id), [
        'stage_id' => $this->stageQualified->id,
        'note' => 'Von der Detailseite.',
    ])->assertRedirect();

    expect($deal->refresh()->stage_id)->toBe($this->stageQualified->id);
});

it('still lets a contact editor move a stage, so no board loses drag and drop', function (): void {
    $deal = ($this->deal)();

    Role::make('lh-contacts')->permissions(['view leadhub', 'view leadhub contacts', 'edit leadhub contacts'])->save();
    $editor = User::make()->email('opp-show-editor@example.com');
    $editor->assignRole('lh-contacts');
    $editor->save();
    $this->actingAs($editor);

    $this->post(cp_route('leadhub.pipelines.move', $deal->id), [
        'stage_id' => $this->stageQualified->id,
    ])->assertRedirect();

    expect($deal->refresh()->stage_id)->toBe($this->stageQualified->id);
});

// -- The number the screen invents, on a deal that is over -----------------

it('stops the last stretch when the deal closed, instead of counting to today', function (): void {
    // The top row of a closed deal's history was measured against `now()`, so
    // a deal won in April read "115 days" and grew by one every day — in the
    // same column and the same typography as the real phase durations beneath
    // it, answering a different question. The "still running" marker that
    // would have told them apart is deliberately hidden on a closed deal, so
    // nothing was left to distinguish them.
    Carbon::setTestNow('2026-04-01 12:00:00');
    $deal = ($this->deal)();

    Carbon::setTestNow('2026-04-11 12:00:00');
    moveDeal($deal, $this->stageQualified);

    Carbon::setTestNow('2026-04-21 12:00:00');
    moveDeal($deal, $this->stageWon);

    // Four months pass, in which nothing happens to a deal that is over.
    Carbon::setTestNow('2026-08-15 12:00:00');

    $rows = ($this->showProps)($deal->refresh())['props']['history'];
    $newest = $rows[0];

    expect($newest['is_current'])->toBeTrue()
        ->and($newest['is_running'])->toBeFalse()
        ->and($newest['duration_seconds'])->toBe(0);

    Carbon::setTestNow();
});

it('keeps counting the last stretch while the deal is still open', function (): void {
    Carbon::setTestNow('2026-08-01 12:00:00');
    $deal = ($this->deal)();

    Carbon::setTestNow('2026-08-06 12:00:00');
    moveDeal($deal, $this->stageQualified);

    Carbon::setTestNow('2026-08-15 12:00:00');

    $newest = ($this->showProps)($deal->refresh())['props']['history'][0];

    expect($newest['is_running'])->toBeTrue()
        ->and($newest['duration_seconds'])->toBe(9 * 86400);

    Carbon::setTestNow();
});

// -- The move that is not a move ------------------------------------------

it('writes no history for a move to the stage the deal is already on', function (): void {
    // The screen stops it in the browser; a second tab or a plain POST does
    // not. A history entry saying a deal went from Qualified to Qualified is
    // exactly the kind of noise that made the table unreadable elsewhere.
    $deal = ($this->deal)();
    moveDeal($deal, $this->stageQualified);

    $before = StageTransition::query()->where('opportunity_id', $deal->id)->count();

    $this->post(cp_route('leadhub.pipelines.move', $deal->id), [
        'stage_id' => $this->stageQualified->id,
    ])->assertRedirect();

    expect(StageTransition::query()->where('opportunity_id', $deal->id)->count())->toBe($before)
        ->and($deal->refresh()->stage_id)->toBe($this->stageQualified->id);
});

it('refuses a note longer than the history can reasonably show', function (): void {
    $deal = ($this->deal)();

    $this->post(cp_route('leadhub.pipelines.move', $deal->id), [
        'stage_id' => $this->stageQualified->id,
        'note' => str_repeat('a', 2001),
    ])->assertSessionHasErrors('note');
});
