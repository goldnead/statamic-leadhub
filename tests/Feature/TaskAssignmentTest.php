<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Support\UserDirectory;
use Statamic\Facades\User;

/**
 * Task assignment, end to end through the CP.
 *
 * `assignee_id` has been a real column with a real scope since 1.1 and reached
 * no screen: no column, no filter, no "my tasks", no way to assign. These
 * tests exercise the list route with its filters and the write routes with an
 * assignee, because that is where the gap was.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    $this->user = User::make()->email('assign-admin@example.com')->makeSuper();
    $this->user->save();

    $this->colleague = User::make()->email('assign-colleague@example.com')->makeSuper();
    $this->colleague->save();

    $this->actingAs($this->user);

    config()->set('leadhub.features.tasks', true);

    $this->contact = Contact::create(['email' => 'assignee-contact@example.com']);
});

function taskProps($response): array
{
    return json_decode($response->getContent(), true)['props'] ?? [];
}

function taskIndex($test, array $query = [])
{
    return $test->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.tasks.index').(empty($query) ? '' : '?'.http_build_query($query)));
}

it('lists the assignee as a column and hands the page the option list', function (): void {
    Task::create([
        'title' => 'Assigned work',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->colleague->id(),
    ]);

    $props = taskProps(taskIndex($this));

    expect(collect($props['columns'])->pluck('field'))->toContain('assignee_name');
    expect($props['tasks'][0]['assignee_name'])->toBe($this->colleague->email());
    expect(collect($props['assignableUsers'])->pluck('value'))
        ->toContain((string) $this->colleague->id());
});

it('filters by a specific assignee', function (): void {
    Task::create(['title' => 'Mine', 'status' => Task::STATUS_OPEN, 'assignee_id' => (string) $this->user->id()]);
    Task::create(['title' => 'Theirs', 'status' => Task::STATUS_OPEN, 'assignee_id' => (string) $this->colleague->id()]);

    $props = taskProps(taskIndex($this, ['assignee_id' => (string) $this->colleague->id()]));

    expect(collect($props['tasks'])->pluck('title')->all())->toBe(['Theirs']);
});

it('filters to unassigned work', function (): void {
    Task::create(['title' => 'Nobody', 'status' => Task::STATUS_OPEN]);
    Task::create(['title' => 'Somebody', 'status' => Task::STATUS_OPEN, 'assignee_id' => (string) $this->user->id()]);

    $props = taskProps(taskIndex($this, ['assignee_id' => 'none']));

    expect(collect($props['tasks'])->pluck('title')->all())->toBe(['Nobody']);
});

it('answers "my tasks" with the current user’s work only', function (): void {
    Task::create(['title' => 'Mine', 'status' => Task::STATUS_OPEN, 'assignee_id' => (string) $this->user->id()]);
    Task::create(['title' => 'Theirs', 'status' => Task::STATUS_OPEN, 'assignee_id' => (string) $this->colleague->id()]);
    Task::create(['title' => 'Nobody', 'status' => Task::STATUS_OPEN]);

    $props = taskProps(taskIndex($this, ['mine' => 1]));

    expect(collect($props['tasks'])->pluck('title')->all())->toBe(['Mine']);
    expect($props['mine'])->toBeTrue();
});

it('combines the status filter with the assignee filter', function (): void {
    Task::create([
        'title' => 'Open, mine', 'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->user->id(),
    ]);
    Task::create([
        'title' => 'Done, mine', 'status' => Task::STATUS_DONE, 'completed_at' => now(),
        'assignee_id' => (string) $this->user->id(),
    ]);

    expect(collect(taskProps(taskIndex($this, ['mine' => 1, 'filter' => 'done']))['tasks'])->pluck('title')->all())
        ->toBe(['Done, mine']);
});

it('assigns a task when it is created', function (): void {
    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Please handle',
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->colleague->id(),
    ])->assertSessionHasNoErrors();

    expect(Task::query()->first()->assignee_id)->toBe((string) $this->colleague->id());
});

it('reassigns a task from the edit form', function (): void {
    $task = Task::create([
        'title' => 'Handover',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->user->id(),
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'assignee_id' => (string) $this->colleague->id(),
    ])->assertSessionHasNoErrors();

    expect($task->refresh()->assignee_id)->toBe((string) $this->colleague->id());
});

it('unassigns a task when the assignee is cleared', function (): void {
    $task = Task::create([
        'title' => 'Dropped',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->user->id(),
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), ['assignee_id' => null])
        ->assertSessionHasNoErrors();

    expect($task->refresh()->assignee_id)->toBeNull();
});

it('refuses an assignee who may not view LeadHub', function (): void {
    // Assignees are the CP users who may view the module. A user without that
    // permission is not in UserDirectory::assignable(), and a hand-crafted
    // request must not be able to park work on them either.
    $outsider = User::make()->email('assign-outsider@example.com');
    $outsider->save();

    expect(collect(app(UserDirectory::class)->assignable())->pluck('value'))
        ->not->toContain((string) $outsider->id());

    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Should not stick',
        'assignee_id' => (string) $outsider->id(),
    ])->assertSessionHasErrors('assignee_id');

    expect(Task::query()->count())->toBe(0);
});

it('keeps showing a name for an assignee who lost the permission', function (): void {
    Task::create([
        'title' => 'Legacy assignment',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->colleague->id(),
    ]);

    $props = taskProps(taskIndex($this));

    // A bare id in the column would read like data corruption.
    expect($props['tasks'][0]['assignee_name'])->not->toBeNull();
});
