<?php

use Goldnead\Leadhub\Integrations\Notifications\NotificationsBridge;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\TaskAssignmentNotifier;
use Statamic\Facades\User;

/**
 * The task-assignment notification, seen from an install that does NOT have
 * goldnead/statamic-notifications.
 *
 * Two things are asserted here and nowhere else: that the addon's absence is a
 * no-op rather than an exception, and that the controller hands the notifier
 * the right pair of ids. The delivery itself needs the real addon and lives in
 * tests/Integration/TaskAssignedNotificationLiveTest.php.
 */
class RecordingTaskAssignmentNotifier extends TaskAssignmentNotifier
{
    /** @var array<int, array{task: string, assignee: ?string, actor: ?string}> */
    public array $calls = [];

    public function assigned(Task $task, ?string $assigneeId, ?string $actorId = null): void
    {
        $this->calls[] = [
            'task' => (string) $task->id,
            'assignee' => $assigneeId,
            'actor' => $actorId,
        ];
    }
}

beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    config()->set('leadhub.features.tasks', true);

    $this->user = User::make()->email('notify-wiring-admin@example.com')->makeSuper();
    $this->user->save();
    $this->colleague = User::make()->email('notify-wiring-colleague@example.com')->makeSuper();
    $this->colleague->save();
    $this->actingAs($this->user);

    $this->contact = Contact::create(['email' => 'notify-wiring@example.com']);

    $this->spy = new RecordingTaskAssignmentNotifier(app(\Goldnead\Leadhub\Support\UserDirectory::class));
    app()->instance(TaskAssignmentNotifier::class, $this->spy);
});

// ------------------------------------------------------------ the decision

it('does not notify somebody who assigned the task to themselves', function (): void {
    expect((new TaskAssignmentNotifier(app(\Goldnead\Leadhub\Support\UserDirectory::class)))
        ->shouldNotify('user-1', 'user-1'))->toBeFalse();
});

it('notifies when the work goes to somebody else', function (): void {
    expect((new TaskAssignmentNotifier(app(\Goldnead\Leadhub\Support\UserDirectory::class)))
        ->shouldNotify('user-2', 'user-1'))->toBeTrue();
});

it('does not notify when the task is unassigned', function (): void {
    $notifier = new TaskAssignmentNotifier(app(\Goldnead\Leadhub\Support\UserDirectory::class));

    expect($notifier->shouldNotify(null, 'user-1'))->toBeFalse()
        ->and($notifier->shouldNotify('', 'user-1'))->toBeFalse();
});

it('can be switched off entirely', function (): void {
    config()->set('leadhub.notifications.on_task_assignment', false);

    expect((new TaskAssignmentNotifier(app(\Goldnead\Leadhub\Support\UserDirectory::class)))
        ->shouldNotify('user-2', 'user-1'))->toBeFalse();
});

// -------------------------------------------------------------- the wiring

it('tells the notifier who was given the task and by whom, on creation', function (): void {
    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Handed over at birth',
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->colleague->id(),
    ])->assertSessionHasNoErrors();

    expect($this->spy->calls)->toHaveCount(1)
        ->and($this->spy->calls[0]['assignee'])->toBe((string) $this->colleague->id())
        ->and($this->spy->calls[0]['actor'])->toBe((string) $this->user->id());
});

it('tells the notifier about a reassignment', function (): void {
    $task = Task::create([
        'title' => 'Handover',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->user->id(),
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'assignee_id' => (string) $this->colleague->id(),
    ])->assertSessionHasNoErrors();

    expect($this->spy->calls)->toHaveCount(1)
        ->and($this->spy->calls[0]['assignee'])->toBe((string) $this->colleague->id())
        ->and($this->spy->calls[0]['actor'])->toBe((string) $this->user->id());
});

it('says nothing to the notifier when the assignee did not change', function (): void {
    $task = Task::create([
        'title' => 'Only the title moves',
        'status' => Task::STATUS_OPEN,
        'assignee_id' => (string) $this->colleague->id(),
    ]);

    $this->patch(cp_route('leadhub.tasks.update', $task->id), [
        'title' => 'A new title',
        'assignee_id' => (string) $this->colleague->id(),
    ])->assertSessionHasNoErrors();

    expect($this->spy->calls)->toBe([]);
});

// ------------------------------------------------------ no-op when absent

it('reports unavailable when the notifications addon is not installed', function (): void {
    expect(NotificationsBridge::available())->toBeFalse();
});

it('boots as a no-op and lets an assignment through untouched when absent', function (): void {
    app()->forgetInstance(TaskAssignmentNotifier::class);

    (new NotificationsBridge())->boot();

    $this->post(cp_route('leadhub.tasks.store'), [
        'title' => 'Nobody to tell',
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->colleague->id(),
    ])->assertSessionHasNoErrors();

    expect(Task::query()->first()->assignee_id)->toBe((string) $this->colleague->id());
});

it('defers the type registration into an app->booted callback', function (): void {
    // Registering from the request that produces a notification would leave the
    // scheduler process without the type, where it falls back to the in_app
    // default and the digest skips the item without a word. A provider is the
    // only place that holds for web, queue and scheduler alike — and it has to
    // be deferred, because `notifications` may not be bound yet when LeadHub
    // boots beside it.
    $provider = app()->getProvider(\Goldnead\Leadhub\ServiceProvider::class);
    $source = file_get_contents((new ReflectionClass($provider))->getFileName());

    expect($source)->toContain('registerNotificationTypes')
        ->and($source)->toMatch('/registerNotificationTypes\(\): self.*app->booted/s');
});
