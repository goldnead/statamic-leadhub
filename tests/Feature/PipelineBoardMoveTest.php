<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\StageTransition;
use Statamic\Facades\User;

/**
 * Covers the HTTP endpoint that the Kanban board's drag & drop uses to
 * persist a card's new stage: POST leadhub.pipelines.move.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Pipelines target the eloquent driver.');
    }

    $this->user = User::make()->email('board-move@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.pipelines', true);
});

it('persists a card move to a new stage via the board endpoint', function (): void {
    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Qualified'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
    ]);

    $contact = LeadHub::create(['email' => 'drag@example.com']);
    $opp = LeadHub::upsertOpportunity($contact['id'], 'sales');

    $model = Opportunity::findOrFail($opp['id']);
    $sourceStageId = $model->stage_id;
    $qualified = $model->pipeline->stages()->where('slug', 'qualified')->firstOrFail();

    expect($qualified->id)->not->toBe($sourceStageId);

    $this->post(cp_route('leadhub.pipelines.move', $opp['id']), [
        'stage_id' => $qualified->id,
    ])->assertRedirect();

    $model->refresh();

    expect($model->stage_id)->toBe($qualified->id)
        ->and(StageTransition::query()->where('opportunity_id', $opp['id'])->count())->toBe(1);
});

it('closes an opportunity as won when moved to a terminal won stage via the endpoint', function (): void {
    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
    ]);

    $contact = LeadHub::create(['email' => 'win-drag@example.com']);
    $opp = LeadHub::upsertOpportunity($contact['id'], 'sales');

    $model = Opportunity::findOrFail($opp['id']);
    $won = $model->pipeline->stages()->where('slug', 'won')->firstOrFail();

    $this->post(cp_route('leadhub.pipelines.move', $opp['id']), [
        'stage_id' => $won->id,
    ])->assertRedirect();

    $model->refresh();

    expect($model->stage_id)->toBe($won->id)
        ->and($model->status)->toBe(Opportunity::STATUS_CLOSED)
        ->and($model->won_at)->not->toBeNull();
});

it('rejects a move to a stage from another pipeline', function (): void {
    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Qualified'],
    ]);
    $other = LeadHub::createPipeline('Support', [
        ['name' => 'Open'],
        ['name' => 'Resolved'],
    ]);

    $contact = LeadHub::create(['email' => 'cross@example.com']);
    $opp = LeadHub::upsertOpportunity($contact['id'], 'sales');

    $foreignStageId = $other['stages'][1]['id'];

    $this->post(cp_route('leadhub.pipelines.move', $opp['id']), [
        'stage_id' => $foreignStageId,
    ])->assertNotFound();

    expect(StageTransition::query()->where('opportunity_id', $opp['id'])->count())->toBe(0);
});
