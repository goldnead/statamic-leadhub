<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Task;
use Statamic\Facades\User;

/**
 * The tasks on an opportunity, seen from the opportunity.
 *
 * Gap 11. v1.9.0 let a task point at a deal, and only the task form could show
 * it. From the deal's side the link existed exclusively as the reason
 * `OpportunityController::destroy()` refused — "this opportunity still has 3
 * tasks", naming records no screen would show you. The panel is what makes that
 * refusal checkable, so the count it renders has to be the same count the
 * refusal uses. That equality is the point of this file, not the markup.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The CRM-core modules are eloquent-only.');
    }

    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);

    $this->user = User::make()->email('opp-tasks@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    $this->contact = Contact::create(['email' => 'opp-tasks-contact@example.com']);

    LeadHub::createPipeline('Panel Sales', [['name' => 'New']]);
    $this->pipeline = Pipeline::query()->where('slug', 'panel-sales')->firstOrFail();

    $this->opportunity = Opportunity::create([
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
        'stage_id' => $this->pipeline->stages()->first()->id,
        'title' => 'Panel deal',
        'status' => Opportunity::STATUS_OPEN,
    ]);
});

function opportunityProps($test, $opportunity): array
{
    $response = $test->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.pipelines.opportunities.edit', $opportunity->id));

    $response->assertStatus(200);

    return json_decode($response->getContent(), true)['props'] ?? [];
}

it('lists the tasks that hang on the opportunity', function (): void {
    $task = Task::create([
        'title' => 'Send the quote',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'opportunity_id' => $this->opportunity->id,
        'assignee_id' => (string) $this->user->id(),
    ]);

    $props = opportunityProps($this, $this->opportunity);

    expect(collect($props['tasks'])->pluck('id')->all())->toBe([(string) $task->id])
        ->and($props['tasks'][0]['title'])->toBe('Send the quote')
        ->and($props['tasks'][0]['assignee_name'])->toBe($this->user->email())
        ->and($props['tasks'][0]['is_open'])->toBeTrue();
});

it('does not list the tasks of another opportunity', function (): void {
    $other = Opportunity::create([
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
        'stage_id' => $this->pipeline->stages()->first()->id,
        'title' => 'Someone else’s deal',
        'status' => Opportunity::STATUS_OPEN,
    ]);

    Task::create([
        'title' => 'Belongs elsewhere',
        'status' => Task::STATUS_OPEN,
        'opportunity_id' => $other->id,
    ]);
    Task::create(['title' => 'Belongs to no deal', 'status' => Task::STATUS_OPEN]);

    expect(opportunityProps($this, $this->opportunity)['tasks'])->toBe([]);
});

it('shows the same number of tasks the deletion refuses over', function (): void {
    // A completed task still blocks deletion — `destroy()` counts every row
    // with this opportunity_id. A panel filtered to open tasks would show an
    // empty list beside "this opportunity still has 1 task", which is the one
    // screen this panel exists to prevent.
    Task::create([
        'title' => 'Already done',
        'status' => Task::STATUS_DONE,
        'completed_at' => now(),
        'opportunity_id' => $this->opportunity->id,
    ]);

    $props = opportunityProps($this, $this->opportunity);

    expect($props['tasks'])->toHaveCount(1)
        ->and($props['tasks'][0]['is_open'])->toBeFalse();

    $this->delete(cp_route('leadhub.pipelines.opportunities.destroy', $this->opportunity->id))
        ->assertSessionHasErrors('opportunity');

    expect(Opportunity::query()->whereKey($this->opportunity->id)->exists())->toBeTrue();
});

it('sorts open work above completed work', function (): void {
    Task::create([
        'title' => 'Done first',
        'status' => Task::STATUS_DONE,
        'completed_at' => now(),
        'opportunity_id' => $this->opportunity->id,
    ]);
    Task::create([
        'title' => 'Still open',
        'status' => Task::STATUS_OPEN,
        'opportunity_id' => $this->opportunity->id,
    ]);

    expect(collect(opportunityProps($this, $this->opportunity)['tasks'])->pluck('title')->all())
        ->toBe(['Still open', 'Done first']);
});

it('offers a "new task" link carrying the deal and its contact', function (): void {
    $props = opportunityProps($this, $this->opportunity);

    expect($props['createTaskUrl'])
        ->toContain('opportunity='.$this->opportunity->id)
        ->toContain('contact='.$this->contact->id)
        ->and($props['canManageTasks'])->toBeTrue();
});

it('renders no panel when the tasks module is off', function (): void {
    Task::create([
        'title' => 'Invisible',
        'status' => Task::STATUS_OPEN,
        'opportunity_id' => $this->opportunity->id,
    ]);

    config()->set('leadhub.features.tasks', false);

    $props = opportunityProps($this, $this->opportunity);

    expect($props['tasksEnabled'])->toBeFalse()
        ->and($props['tasks'])->toBe([])
        // The route would 404 with the module off; a link to it would be worse
        // than no link.
        ->and($props['createTaskUrl'])->toBeNull();
});
