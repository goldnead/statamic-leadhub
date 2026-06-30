<?php

use Goldnead\Leadhub\Events\LeadHubOpportunityCreated;
use Goldnead\Leadhub\Events\LeadHubOpportunityStageChanged;
use Goldnead\Leadhub\Events\LeadHubOpportunityWon;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\StageTransition;

beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Pipelines target the eloquent driver.');
    }
});

function salesPipeline(): array
{
    return LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Qualified'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
        ['name' => 'Lost', 'is_terminal' => true, 'terminal_outcome' => 'lost'],
    ]);
}

it('creates a pipeline with ordered stages', function () {
    $pipeline = salesPipeline();

    expect($pipeline['slug'])->toBe('sales')
        ->and($pipeline['stages'])->toHaveCount(4)
        ->and($pipeline['stages'][0]['slug'])->toBe('new')
        ->and($pipeline['stages'][2]['is_terminal'])->toBeTrue();
});

it('creates an opportunity in the first stage by default', function () {
    Illuminate\Support\Facades\Event::fake([LeadHubOpportunityCreated::class]);

    salesPipeline();
    $contact = LeadHub::create(['email' => 'deal@example.com']);

    $opp = LeadHub::upsertOpportunity($contact['id'], 'sales', ['value_estimate' => 5000, 'confidence' => 30]);

    expect($opp['status'])->toBe('open')
        ->and((float) $opp['value_estimate'])->toBe(5000.0);

    Illuminate\Support\Facades\Event::assertDispatched(LeadHubOpportunityCreated::class);
    expect(Event::query()->where('type', Event::TYPE_OPPORTUNITY_CREATED)->exists())->toBeTrue();
});

it('moves an opportunity through stages and records transitions', function () {
    Illuminate\Support\Facades\Event::fake([LeadHubOpportunityStageChanged::class]);

    salesPipeline();
    $contact = LeadHub::create(['email' => 'move@example.com']);
    $opp = LeadHub::upsertOpportunity($contact['id'], 'sales');

    $moved = LeadHub::moveStage($opp['id'], 'qualified', 'Looks promising');

    expect($moved['status'])->toBe('open');
    expect(StageTransition::query()->where('opportunity_id', $opp['id'])->count())->toBe(1);
    Illuminate\Support\Facades\Event::assertDispatched(LeadHubOpportunityStageChanged::class);
    expect(Event::query()->where('type', Event::TYPE_OPPORTUNITY_STAGE_CHANGED)->exists())->toBeTrue();
});

it('closes an opportunity as won when reaching a terminal won stage', function () {
    Illuminate\Support\Facades\Event::fake([LeadHubOpportunityWon::class]);

    salesPipeline();
    $contact = LeadHub::create(['email' => 'win@example.com']);
    $opp = LeadHub::upsertOpportunity($contact['id'], 'sales');

    $won = LeadHub::moveStage($opp['id'], 'won');

    expect($won['status'])->toBe('closed')
        ->and($won['outcome'])->toBe('won');

    $model = Opportunity::find($opp['id']);
    expect($model->won_at)->not->toBeNull()
        ->and($model->closed_at)->not->toBeNull();

    Illuminate\Support\Facades\Event::assertDispatched(LeadHubOpportunityWon::class);
});

it('upserts the same opportunity for a repeated source signal', function () {
    salesPipeline();
    $contact = LeadHub::create(['email' => 'src@example.com']);

    $a = LeadHub::upsertOpportunity($contact['id'], 'sales', [
        'source_type' => 'App\\Models\\Order', 'source_id' => 7, 'value_estimate' => 100,
    ]);
    $b = LeadHub::upsertOpportunity($contact['id'], 'sales', [
        'source_type' => 'App\\Models\\Order', 'source_id' => 7, 'value_estimate' => 250,
    ]);

    expect($b['id'])->toBe($a['id'])
        ->and((float) $b['value_estimate'])->toBe(250.0);
    expect(Opportunity::query()->count())->toBe(1);
});
