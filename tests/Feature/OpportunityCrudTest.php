<?php

use Goldnead\Leadhub\Events\LeadHubOpportunityCreated;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event as TimelineEvent;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\StageTransition;
use Goldnead\Leadhub\Models\Task;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\User;

/**
 * Opportunities can be created, edited and deleted from the Control Panel.
 *
 * Two behaviours are worth more than the CRUD itself and are tested for
 * explicitly: creating straight into a terminal stage has to close the deal
 * (otherwise the board grows open opportunities in its "Won" column), and
 * changing the stage from the edit form has to go through the transition
 * service, not be a field assignment.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    $this->user = User::make()->email('opp-crud@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.pipelines', true);
    config()->set('leadhub.features.companies', true);
    config()->set('leadhub.features.tasks', true);

    Goldnead\Leadhub\Facades\LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
    ]);

    $this->pipeline = Pipeline::query()->firstOrFail();
    $this->stageNew = $this->pipeline->stages()->where('slug', 'new')->firstOrFail();
    $this->stageWon = $this->pipeline->stages()->where('slug', 'won')->firstOrFail();
    $this->contact = Contact::create(['email' => 'opp@example.com', 'first_name' => 'Otto']);
});

it('renders the create form', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.pipelines.opportunities.create'));

    $response->assertStatus(200);
    expect(json_decode($response->getContent(), true)['component'])->toBe('leadhub::Pipelines/OpportunityCreate');
});

it('creates an opportunity from the CP', function (): void {
    Event::fake([LeadHubOpportunityCreated::class]);

    $this->post(cp_route('leadhub.pipelines.opportunities.store'), [
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
        'stage_id' => $this->stageNew->id,
        'title' => 'Website relaunch',
        'value_estimate' => '4200.50',
        'confidence' => 60,
    ])->assertRedirect();

    $opportunity = Opportunity::query()->first();

    expect($opportunity)->not->toBeNull()
        ->and($opportunity->title)->toBe('Website relaunch')
        ->and((float) $opportunity->value_estimate)->toBe(4200.50)
        ->and($opportunity->confidence)->toBe(60)
        ->and($opportunity->stage_id)->toBe($this->stageNew->id)
        ->and($opportunity->status)->toBe(Opportunity::STATUS_OPEN);

    Event::assertDispatched(LeadHubOpportunityCreated::class);
    expect(TimelineEvent::query()->where('type', TimelineEvent::TYPE_OPPORTUNITY_CREATED)->count())->toBe(1);
});

it('closes an opportunity created directly into a terminal stage', function (): void {
    $this->post(cp_route('leadhub.pipelines.opportunities.store'), [
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
        'stage_id' => $this->stageWon->id,
        'title' => 'Already won',
    ])->assertRedirect();

    $opportunity = Opportunity::query()->first();

    expect($opportunity->status)->toBe(Opportunity::STATUS_CLOSED)
        ->and($opportunity->outcome)->toBe('won');
});

it('refuses an opportunity without a contact', function (): void {
    $this->post(cp_route('leadhub.pipelines.opportunities.store'), [
        'pipeline_id' => $this->pipeline->id,
    ])->assertSessionHasErrors('contact_id');

    expect(Opportunity::query()->count())->toBe(0);
});

it('refuses a stage that belongs to a different pipeline', function (): void {
    Goldnead\Leadhub\Facades\LeadHub::createPipeline('Support', [['name' => 'Intake']]);
    $other = Pipeline::query()->where('slug', 'support')->firstOrFail();

    $this->post(cp_route('leadhub.pipelines.opportunities.store'), [
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
        'stage_id' => $other->stages()->first()->id,
    ])->assertSessionHasErrors('stage_id');

    expect(Opportunity::query()->count())->toBe(0);
});

it('refuses a confidence outside 0-100', function (): void {
    $this->post(cp_route('leadhub.pipelines.opportunities.store'), [
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
        'confidence' => 140,
    ])->assertSessionHasErrors('confidence');
});

it('edits an opportunity from the CP', function (): void {
    $opportunity = makeOpportunity($this);

    $this->patch(cp_route('leadhub.pipelines.opportunities.update', $opportunity->id), [
        'title' => 'Renamed',
        'value_estimate' => '999',
        'confidence' => 25,
    ])->assertRedirect();

    $opportunity->refresh();

    expect($opportunity->title)->toBe('Renamed')
        ->and((float) $opportunity->value_estimate)->toBe(999.0)
        ->and($opportunity->confidence)->toBe(25);
});

it('records a transition when the stage is changed from the edit form', function (): void {
    $opportunity = makeOpportunity($this);

    $this->patch(cp_route('leadhub.pipelines.opportunities.update', $opportunity->id), [
        'stage_id' => $this->stageWon->id,
    ])->assertRedirect();

    $opportunity->refresh();

    // Field assignment would have left status open and written no history.
    expect($opportunity->stage_id)->toBe($this->stageWon->id)
        ->and($opportunity->status)->toBe(Opportunity::STATUS_CLOSED)
        ->and($opportunity->outcome)->toBe('won')
        ->and(StageTransition::query()->where('opportunity_id', $opportunity->id)->count())->toBe(1);
});

it('deletes an opportunity that nothing hangs on', function (): void {
    $opportunity = makeOpportunity($this);

    $this->delete(cp_route('leadhub.pipelines.opportunities.destroy', $opportunity->id))->assertRedirect();

    expect(Opportunity::query()->whereKey($opportunity->id)->exists())->toBeFalse();
});

it('refuses to delete an opportunity that still has tasks', function (): void {
    $opportunity = makeOpportunity($this);

    Task::create([
        'title' => 'Prepare the quote',
        'opportunity_id' => $opportunity->id,
        'status' => Task::STATUS_OPEN,
    ]);

    $this->delete(cp_route('leadhub.pipelines.opportunities.destroy', $opportunity->id))
        ->assertSessionHasErrors('opportunity');

    expect(Opportunity::query()->whereKey($opportunity->id)->exists())->toBeTrue();
});

it('refuses a company that does not exist', function (): void {
    $opportunity = makeOpportunity($this);

    $this->patch(cp_route('leadhub.pipelines.opportunities.update', $opportunity->id), [
        'company_id' => 999999,
    ])->assertSessionHasErrors('company_id');
});

it('accepts a company that does exist', function (): void {
    $opportunity = makeOpportunity($this);
    $company = Company::create(['name' => 'Buyer GmbH']);

    $this->patch(cp_route('leadhub.pipelines.opportunities.update', $opportunity->id), [
        'company_id' => $company->id,
    ])->assertSessionHasNoErrors();

    expect($opportunity->refresh()->company_id)->toBe($company->id);
});

it('404s every write route when the pipelines feature is off', function (): void {
    $opportunity = makeOpportunity($this);
    config()->set('leadhub.features.pipelines', false);

    $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.pipelines.opportunities.create'))->assertStatus(404);
    $this->post(cp_route('leadhub.pipelines.opportunities.store'), [
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
    ])->assertStatus(404);
    $this->delete(cp_route('leadhub.pipelines.opportunities.destroy', $opportunity->id))->assertStatus(404);
});

it('refuses the write routes without the manage permission', function (): void {
    $opportunity = makeOpportunity($this);

    $plain = User::make()->email('opp-nobody@example.com');
    $plain->save();
    $this->actingAs($plain);

    $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.pipelines.opportunities.create'))->assertStatus(403);
    $this->post(cp_route('leadhub.pipelines.opportunities.store'), [
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
    ])->assertStatus(403);
    $this->delete(cp_route('leadhub.pipelines.opportunities.destroy', $opportunity->id))->assertStatus(403);

    expect(Opportunity::query()->count())->toBe(1);
});

function makeOpportunity($test): Opportunity
{
    return Opportunity::create([
        'contact_id' => $test->contact->id,
        'pipeline_id' => $test->pipeline->id,
        'stage_id' => $test->stageNew->id,
        'title' => 'Existing deal',
        'status' => Opportunity::STATUS_OPEN,
    ]);
}
