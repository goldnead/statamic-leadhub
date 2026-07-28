<?php

use Goldnead\Leadhub\Integrations\Notifications\NotificationsBridge;
use Goldnead\Leadhub\Integrations\Notifications\TaskDigestSource;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Tests\Integration\NotificationsTestCase;
use Statamic\Facades\User;

/**
 * Live LeadHub ↔ goldnead/statamic-notifications integration.
 *
 * Gap 10: through v1.9.0 a reassignment wrote a timeline entry and fired an
 * event, and the colleague holding the task found out by opening the task list.
 * These tests answer the only question that matters about that — does a
 * notification actually reach the person — which the no-op contract in
 * Feature/TaskAssignmentNotificationTest cannot, because the default suite runs
 * with the addon absent.
 *
 * Runs only when the optional peer addon is installed; see
 * scripts/test-notifications.sh.
 */
uses(NotificationsTestCase::class);

beforeEach(function (): void {
    if (! NotificationsBridge::available()) {
        $this->markTestSkipped('goldnead/statamic-notifications is not installed.');
    }

    if (config('leadhub.storage.driver') === 'flat') {
        $this->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    config()->set('leadhub.features.tasks', true);

    $this->user = User::make()->email('notify-admin@example.com')->makeSuper();
    $this->user->save();

    $this->colleague = User::make()->email('notify-colleague@example.com')->makeSuper();
    $this->colleague->save();

    $this->actingAs($this->user);

    $this->contact = Contact::create(['email' => 'notify-contact@example.com']);
});

/** Everything the notifications addon persisted for one recipient. */
function itemsFor($user)
{
    return \Goldnead\Notifications\Models\NotificationItem::query()
        ->where('user_id', (string) $user->id())
        ->get();
}

// ------------------------------------------------------------- registration

it('registers the task-assignment type from a service provider, not from the caller', function (): void {
    // The registry lives per process. A type registered where the notification
    // is produced is unknown to the scheduler, falls back to the in_app default
    // there and is silently dropped from the digest — so the assertion is not
    // "the type exists after a request", it is "the type exists on a booted
    // application that has served no request".
    $type = app('notifications')->types()->get(NotificationsBridge::TASK_ASSIGNED);

    expect($type->handle)->toBe(NotificationsBridge::TASK_ASSIGNED)
        ->and($type->defaultChannels)->toContain('mail')
        ->and($type->label)->not->toBeNull();
});

it('registers the open-task digest source under its own handle', function (): void {
    // `leadhub` is the bundled follow-up source. Reusing that handle would
    // replace it and the digest would lose the follow-ups it already had.
    expect(app('notifications')->sources()->has(NotificationsBridge::DIGEST_SOURCE))->toBeTrue()
        ->and(NotificationsBridge::DIGEST_SOURCE)->not->toBe('leadhub');
});

// --------------------------------------------------------------- delivery

it('notifies the colleague a task is created for', function (): void {
    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Please call them back',
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->colleague->id(),
    ])->assertSessionHasNoErrors();

    $items = itemsFor($this->colleague);

    expect($items)->toHaveCount(1)
        ->and($items->first()->type)->toBe(NotificationsBridge::TASK_ASSIGNED)
        ->and($items->first()->message)->toContain('Please call them back')
        ->and($items->first()->link)->toContain('leadhub/tasks');
});

it('notifies the colleague a task is handed over to', function (): void {
    $task = Task::create([
        'title' => 'Handover',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->user->id(),
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'assignee_id' => (string) $this->colleague->id(),
    ])->assertSessionHasNoErrors();

    expect(itemsFor($this->colleague))->toHaveCount(1)
        // The person who gave it away is not told they gave it away.
        ->and(itemsFor($this->user))->toHaveCount(0);
});

it('tells nobody when you assign a task to yourself', function (): void {
    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'My own problem',
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->user->id(),
    ])->assertSessionHasNoErrors();

    expect(itemsFor($this->user))->toHaveCount(0)
        ->and(\Goldnead\Notifications\Models\NotificationItem::query()->count())->toBe(0);
});

it('tells nobody when a task is unassigned', function (): void {
    $task = Task::create([
        'title' => 'Dropped',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->colleague->id(),
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), ['assignee_id' => null])
        ->assertSessionHasNoErrors();

    expect(\Goldnead\Notifications\Models\NotificationItem::query()->count())->toBe(0);
});

it('notifies again when a task comes back to somebody it already left', function (): void {
    // The dedupe key is scoped to the moment, not to the pair. A key of
    // "task + recipient" would swallow the second hand-over silently.
    $task = Task::create([
        'title' => 'Round trip',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->user->id(),
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'assignee_id' => (string) $this->colleague->id(),
    ]);

    $this->travel(2)->seconds();

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'assignee_id' => (string) $this->user->id(),
    ]);
    $this->travel(2)->seconds();
    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'assignee_id' => (string) $this->colleague->id(),
    ]);

    expect(itemsFor($this->colleague))->toHaveCount(2);
});

// ----------------------------------------------------------------- digest

it('contributes open tasks to the digest, which covered follow-ups only', function (): void {
    Task::create([
        'title' => 'Open and overdue',
        'status' => Task::STATUS_OPEN,
        'due_at' => now()->subDays(3),
        'assignee_id' => (string) $this->colleague->id(),
    ]);
    Task::create([
        'title' => 'Open, not due yet',
        'status' => Task::STATUS_OPEN,
        'due_at' => now()->addMonths(2),
        'assignee_id' => (string) $this->colleague->id(),
    ]);
    Task::create([
        'title' => 'Already done',
        'status' => Task::STATUS_DONE,
        'completed_at' => now(),
        'assignee_id' => (string) $this->colleague->id(),
    ]);
    Task::create([
        'title' => 'Somebody else’s',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->user->id(),
    ]);

    $identity = \Goldnead\IdentityContracts\Facades\IdentityContext::resolve($this->colleague);

    $contribution = app(TaskDigestSource::class)
        ->collect($identity, now()->subWeek(), now()->addDay());

    expect($contribution['open_tasks'])->toBe(2)
        ->and($contribution['overdue_tasks'])->toBe(1);
});

it('contributes nothing for somebody with no open tasks', function (): void {
    $identity = \Goldnead\IdentityContracts\Facades\IdentityContext::resolve($this->colleague);

    expect(app(TaskDigestSource::class)->collect($identity, now()->subWeek(), now()->addDay()))
        ->toBe([]);
});
