<?php

use Goldnead\Leadhub\Events\LeadHubTaskCompleted;
use Goldnead\Leadhub\Events\LeadHubTaskCreated;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\TaskService;
use Illuminate\Support\Facades\Event as EventFacade;

beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Tasks target the eloquent driver.');
    }
});

it('creates a task bound to a contact and records the timeline', function () {
    EventFacade::fake([LeadHubTaskCreated::class]);

    $contact = LeadHub::create(['email' => 'task@example.com']);

    $task = LeadHub::createTask([
        'title' => 'Call back',
        'priority' => 'high',
        'due_at' => now()->addDay()->toDateTimeString(),
        'assignee_id' => 'user-1',
    ], $contact['id']);

    expect($task['title'])->toBe('Call back')
        ->and($task['priority'])->toBe('high')
        ->and($task['contact_id'])->toBe($contact['id']);

    EventFacade::assertDispatched(LeadHubTaskCreated::class);
    expect(Event::query()->where('type', Event::TYPE_TASK_CREATED)->where('contact_id', $contact['id'])->exists())->toBeTrue();
});

it('supports multiple open tasks per contact', function () {
    $contact = LeadHub::create(['email' => 'multi-task@example.com']);

    LeadHub::createTask(['title' => 'A'], $contact['id']);
    LeadHub::createTask(['title' => 'B'], $contact['id']);

    expect(Task::query()->where('contact_id', $contact['id'])->count())->toBe(2);
});

it('completes a task and fires the event', function () {
    EventFacade::fake([LeadHubTaskCompleted::class]);

    $contact = LeadHub::create(['email' => 'done@example.com']);
    $task = LeadHub::createTask(['title' => 'Finish'], $contact['id']);

    $completed = LeadHub::completeTask($task['id'], 'user-9');

    expect($completed['status'])->toBe(Task::STATUS_DONE)
        ->and($completed['completed_at'])->not->toBeNull();

    EventFacade::assertDispatched(LeadHubTaskCompleted::class);
    expect(Event::query()->where('type', Event::TYPE_TASK_COMPLETED)->exists())->toBeTrue();
});

it('lists due-today and overdue tasks by assignee', function () {
    $contact = LeadHub::create(['email' => 'due@example.com']);

    LeadHub::createTask(['title' => 'Today', 'due_at' => now()->toDateTimeString(), 'assignee_id' => 'me'], $contact['id']);
    LeadHub::createTask(['title' => 'Late', 'due_at' => now()->subDays(2)->toDateTimeString(), 'assignee_id' => 'me'], $contact['id']);
    LeadHub::createTask(['title' => 'Someone else', 'due_at' => now()->subDays(2)->toDateTimeString(), 'assignee_id' => 'other'], $contact['id']);

    $service = app(TaskService::class);

    expect($service->dueToday('me'))->toHaveCount(1)
        ->and($service->overdue('me'))->toHaveCount(1)
        ->and($service->overdue())->toHaveCount(2);
});
