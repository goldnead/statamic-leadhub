<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;
use Goldnead\Leadhub\Models\Task;
use Statamic\Facades\User;

/**
 * Gap 8: `leadhub_tasks.opportunity_id` was a real column with a real relation
 * and a real delete lock built on it, and nothing in the Control Panel could
 * set it. The v1.7.0 QA run had to create the blocking task on the console to
 * photograph the opportunity delete refusal — a refusal a user cannot reach
 * through the interface is one they cannot resolve through it either.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    $this->user = User::make()->email('opp-task@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);

    $this->contact = Contact::create(['email' => 'opp-task-contact@example.com']);
    $this->other = Contact::create(['email' => 'opp-task-other@example.com']);

    $this->pipeline = Pipeline::create(['name' => 'Sales', 'slug' => 'sales']);
    $this->stage = Stage::create([
        'pipeline_id' => $this->pipeline->id,
        'name' => 'Qualifying',
        'slug' => 'qualifying',
        'sort_order' => 1,
    ]);

    $this->opportunity = Opportunity::create([
        'title' => 'Workshop for the choir',
        'contact_id' => $this->contact->id,
        'pipeline_id' => $this->pipeline->id,
        'stage_id' => $this->stage->id,
        'status' => Opportunity::STATUS_OPEN,
    ]);
});

function taskFormProps($response): array
{
    return json_decode($response->getContent(), true)['props'] ?? [];
}

it('offers the contact\'s open opportunities on the create form', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.tasks.create').'?contact='.$this->contact->id);

    $props = taskFormProps($response);

    expect(collect($props['opportunityOptions'])->pluck('value'))
        ->toContain((string) $this->opportunity->id);
    expect($props['pipelinesEnabled'])->toBeTrue();
});

it('attaches a task to an opportunity through the store route', function (): void {
    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Send the programme',
        'contact_id' => $this->contact->id,
        'opportunity_id' => $this->opportunity->id,
    ])->assertRedirect();

    $task = Task::where('title', 'Send the programme')->first();

    expect($task)->not->toBeNull()
        ->and((string) $task->opportunity_id)->toBe((string) $this->opportunity->id)
        ->and($task->opportunity->title)->toBe('Workshop for the choir');
});

it('attaches an existing task to an opportunity through the update route', function (): void {
    $task = Task::create([
        'title' => 'Already there',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'title' => 'Already there',
        'opportunity_id' => $this->opportunity->id,
    ])->assertRedirect();

    expect((string) $task->fresh()->opportunity_id)->toBe((string) $this->opportunity->id);
});

it('detaches when the picker is cleared', function (): void {
    $task = Task::create([
        'title' => 'Detach me',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'opportunity_id' => $this->opportunity->id,
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'title' => 'Detach me',
        'contact_id' => $this->contact->id,
        'opportunity_id' => '',
    ])->assertRedirect();

    expect($task->fresh()->opportunity_id)->toBeNull();
});

it('refuses an opportunity that belongs to a different contact', function (): void {
    $this->from(cp_route('leadhub.tasks.create'))
        ->post(cp_route('leadhub.tasks.store'), [
            'title' => 'Wrong deal',
            'contact_id' => $this->other->id,
            'opportunity_id' => $this->opportunity->id,
        ])
        ->assertSessionHasErrors('opportunity_id');

    expect(Task::where('title', 'Wrong deal')->exists())->toBeFalse();
});

it('refuses an opportunity id that does not resolve', function (): void {
    $this->from(cp_route('leadhub.tasks.create'))
        ->post(cp_route('leadhub.tasks.store'), [
            'title' => 'Ghost deal',
            'contact_id' => $this->contact->id,
            'opportunity_id' => 999999,
        ])
        ->assertSessionHasErrors('opportunity_id');
});

it('refuses an opportunity when no contact is selected, because the link would be unreachable', function (): void {
    $this->from(cp_route('leadhub.tasks.create'))
        ->post(cp_route('leadhub.tasks.store'), [
            'title' => 'Orphan link',
            'opportunity_id' => $this->opportunity->id,
        ])
        ->assertSessionHasErrors('opportunity_id');
});

it('serves the option feed scoped to the requested contact', function (): void {
    $response = $this->get(
        cp_route('leadhub.tasks.opportunityOptions').'?contact='.$this->contact->id
    );

    $response->assertOk();
    expect(collect($response->json('options'))->pluck('value'))
        ->toContain((string) $this->opportunity->id);

    // A different contact must not see it. The picker is the surface most
    // likely to leak a record across a boundary, because it is the one place
    // that lists things the user did not ask for by id.
    $foreign = $this->get(
        cp_route('leadhub.tasks.opportunityOptions').'?contact='.$this->other->id
    );

    expect(collect($foreign->json('options'))->pluck('value'))
        ->not->toContain((string) $this->opportunity->id);
});

it('returns no options at all when no contact is given', function (): void {
    $response = $this->get(cp_route('leadhub.tasks.opportunityOptions'));

    $response->assertOk();
    expect($response->json('options'))->toBe([]);
});

it('keeps a closed opportunity visible while a task still hangs on it', function (): void {
    $this->opportunity->update(['status' => Opportunity::STATUS_CLOSED]);

    $task = Task::create([
        'title' => 'On a closed deal',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'opportunity_id' => $this->opportunity->id,
    ]);

    $props = taskFormProps(
        $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.tasks.edit', $task->id))
    );

    // Otherwise saving the edit form would silently detach the task from a
    // deal the user never touched.
    expect(collect($props['opportunityOptions'])->pluck('value'))
        ->toContain((string) $this->opportunity->id);
    expect($props['task']['opportunity_id'])->toBe((string) $this->opportunity->id);
});

it('hides the picker entirely when pipelines are switched off', function (): void {
    config()->set('leadhub.features.pipelines', false);

    $props = taskFormProps(
        $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.tasks.create'))
    );

    expect($props['pipelinesEnabled'])->toBeFalse()
        ->and($props['opportunityOptions'])->toBe([]);

    $this->get(cp_route('leadhub.tasks.opportunityOptions'))->assertNotFound();
});
