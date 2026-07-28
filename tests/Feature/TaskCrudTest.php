<?php

use Goldnead\Leadhub\Events\LeadHubTaskCreated;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event as TimelineEvent;
use Goldnead\Leadhub\Models\Task;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\User;

/**
 * Tasks can be created, edited and deleted from the Control Panel, and the due
 * date survives the CP date picker.
 *
 * Against the real routes throughout. The date-picker payload has its own test
 * because it is the failure this addon has now hit twice (follow-ups in v1.4,
 * and the reason `Support\DateValueNormalizer` exists at all).
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    $this->user = User::make()->email('task-crud@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.tasks', true);

    $this->contact = Contact::create(['email' => 'task-contact@example.com', 'first_name' => 'Tessa']);
});

it('renders the create form', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.tasks.create'));

    $response->assertStatus(200);
    expect(json_decode($response->getContent(), true)['component'])->toBe('leadhub::Tasks/Create');
});

it('creates a task from the CP and fires the event plus the timeline entry', function (): void {
    Event::fake([LeadHubTaskCreated::class]);

    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Call back about the quote',
        'contact_id' => $this->contact->id,
        'priority' => 'high',
        'due_at' => '2026-08-01 09:30:00',
    ])->assertRedirect(cp_route('leadhub.tasks.index'));

    $task = Task::query()->first();

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Call back about the quote')
        ->and($task->priority)->toBe('high')
        ->and($task->contact_id)->toBe($this->contact->id)
        ->and($task->due_at->format('Y-m-d H:i'))->toBe('2026-08-01 09:30');

    Event::assertDispatched(LeadHubTaskCreated::class);

    // Routed through TaskService, so the contact's timeline records it — the
    // thing a controller writing Task::create() directly would skip.
    expect(TimelineEvent::query()->where('type', TimelineEvent::TYPE_TASK_CREATED)->count())->toBe(1);
});

it('accepts the DateValue object the CP date picker posts', function (): void {
    // This is verbatim what a reka-ui <DatePicker> serializes into a request.
    // Laravel's `date` rule rejects it outright; NormalizesDatePickerValues is
    // what turns it into something the rule accepts.
    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Picker task',
        'due_at' => [
            'calendar' => ['identifier' => 'gregory'],
            'era' => 'AD',
            'year' => 2026,
            'month' => 8,
            'day' => 14,
            'hour' => 16,
            'minute' => 45,
        ],
    ])->assertSessionHasNoErrors();

    expect(Task::query()->first()->due_at->format('Y-m-d H:i'))->toBe('2026-08-14 16:45');
});

it('refuses a task without a title', function (): void {
    $this->post(cp_route('leadhub.tasks.store'), ['title' => ''])
        ->assertSessionHasErrors('title');

    expect(Task::query()->count())->toBe(0);
});

it('refuses a task pointing at a contact that does not exist', function (): void {
    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Orphan',
        'contact_id' => 999999,
    ])->assertSessionHasErrors('contact_id');

    expect(Task::query()->count())->toBe(0);
});

it('edits a task from the CP', function (): void {
    $task = Task::create([
        'title' => 'Old title',
        'contact_id' => $this->contact->id,
        'status' => Task::STATUS_OPEN,
        'priority' => Task::PRIORITY_NORMAL,
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'title' => 'New title',
        'priority' => 'low',
        'due_at' => '2026-09-02 08:00:00',
    ])->assertRedirect();

    $task->refresh();

    expect($task->title)->toBe('New title')
        ->and($task->priority)->toBe('low')
        ->and($task->due_at->format('Y-m-d H:i'))->toBe('2026-09-02 08:00');
});

it('clears the due date when the picker is emptied', function (): void {
    $task = Task::create([
        'title' => 'Dated',
        'due_at' => now(),
        'status' => Task::STATUS_OPEN,
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'title' => 'Dated',
        'due_at' => null,
    ])->assertSessionHasNoErrors();

    expect($task->refresh()->due_at)->toBeNull();
});

it('deletes a task', function (): void {
    $task = Task::create(['title' => 'Gone soon', 'status' => Task::STATUS_OPEN]);

    $this->delete(cp_route('leadhub.tasks.destroy', $task->id))->assertRedirect();

    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse();
});

it('404s every write route when the tasks feature is off', function (): void {
    $task = Task::create(['title' => 'Hidden', 'status' => Task::STATUS_OPEN]);
    config()->set('leadhub.features.tasks', false);

    $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.tasks.create'))->assertStatus(404);
    $this->post(cp_route('leadhub.tasks.store'), ['title' => 'X'])->assertStatus(404);
    $this->patch(cp_route('leadhub.tasks.update', $task->id), ['title' => 'X'])->assertStatus(404);
    $this->delete(cp_route('leadhub.tasks.destroy', $task->id))->assertStatus(404);
});

it('refuses the write routes without the manage permission', function (): void {
    $plain = User::make()->email('task-nobody@example.com');
    $plain->save();
    $this->actingAs($plain);

    $task = Task::create(['title' => 'Guarded', 'status' => Task::STATUS_OPEN]);

    $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.tasks.create'))->assertStatus(403);
    $this->post(cp_route('leadhub.tasks.store'), ['title' => 'X'])->assertStatus(403);
    $this->delete(cp_route('leadhub.tasks.destroy', $task->id))->assertStatus(403);

    expect(Task::query()->count())->toBe(1);
});
