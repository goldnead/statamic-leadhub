<?php

use Goldnead\Leadhub\Events\LeadHubTaskAssigned;
use Goldnead\Leadhub\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Task;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\User;

/**
 * Gap 6: reassigning a task left no trace at all.
 *
 * Contact assignment has written a timeline entry and notified since 1.0.
 * Task assignment changed a column and nothing else, so "who gave me this,
 * and when" had no answer in a module whose entire purpose is that it does.
 *
 * The two halves are tested separately on purpose: the timeline entry needs a
 * contact to hang on, the event does not, and a task without a contact is
 * ordinary. Collapsing the two would hide that.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    $this->user = User::make()->email('history-admin@example.com')->makeSuper();
    $this->user->save();

    $this->colleague = User::make()->email('history-colleague@example.com')->makeSuper();
    $this->colleague->save();

    $this->actingAs($this->user);

    config()->set('leadhub.features.tasks', true);

    $this->contact = Contact::create(['email' => 'history-contact@example.com']);
});

function reassign($test, Task $task, ?string $assignee): TestResponse
{
    return $test->patch(cp_route('leadhub.tasks.update', $task->id), [
        'title' => $task->title,
        'assignee_id' => $assignee,
    ]);
}

it('writes a timeline entry when a task changes hands', function (): void {
    $task = Task::create([
        'title' => 'Prepare the quote',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'assignee_id' => null,
    ]);

    reassign($this, $task, (string) $this->colleague->id())->assertRedirect();

    $entry = Event::where('type', Event::TYPE_TASK_ASSIGNED)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->contact_id)->toBe($this->contact->id)
        // The sentence is composed at write time and stored, so it keeps
        // naming who it named. Asserting on the stored text is the point.
        ->and($entry->summary)->toContain('Prepare the quote')
        ->and($entry->summary)->toContain($this->colleague->email())
        ->and($entry->payload['to_id'])->toBe((string) $this->colleague->id())
        ->and($entry->payload['from_id'])->toBeNull();
});

it('records the previous owner when work is handed on', function (): void {
    $task = Task::create([
        'title' => 'Chase the invoice',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->user->id(),
    ]);

    reassign($this, $task, (string) $this->colleague->id())->assertRedirect();

    $entry = Event::where('type', Event::TYPE_TASK_ASSIGNED)->first();

    expect($entry->payload['from_id'])->toBe((string) $this->user->id())
        ->and($entry->payload['to_id'])->toBe((string) $this->colleague->id())
        ->and($entry->payload['from'])->toBe($this->user->email());
});

it('records an entry when the owner is removed rather than replaced', function (): void {
    $task = Task::create([
        'title' => 'Unassign me',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->colleague->id(),
    ]);

    reassign($this, $task, null)->assertRedirect();

    $entry = Event::where('type', Event::TYPE_TASK_ASSIGNED)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->summary)->toContain('Unassign me')
        ->and($entry->payload['to_id'])->toBeNull();
});

it('writes nothing when the assignee is submitted unchanged', function (): void {
    $task = Task::create([
        'title' => 'Untouched',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'assignee_id' => (string) $this->colleague->id(),
    ]);

    reassign($this, $task, (string) $this->colleague->id())->assertRedirect();

    expect(Event::where('type', Event::TYPE_TASK_ASSIGNED)->count())->toBe(0);
});

it('fires LeadHubTaskAssigned with the ids, not the labels', function (): void {
    EventFacade::fake([LeadHubTaskAssigned::class]);

    $task = Task::create([
        'title' => 'Fire the event',
        'status' => Task::STATUS_OPEN,
        'contact_id' => $this->contact->id,
        'assignee_id' => null,
    ]);

    reassign($this, $task, (string) $this->colleague->id());

    EventFacade::assertDispatched(
        LeadHubTaskAssigned::class,
        fn (LeadHubTaskAssigned $e) => $e->task->is($task)
            && $e->from === null
            && $e->to === (string) $this->colleague->id(),
    );
});

it('still fires the event for a task that has no contact to write a timeline to', function (): void {
    EventFacade::fake([LeadHubTaskAssigned::class]);

    $task = Task::create([
        'title' => 'Contactless',
        'status' => Task::STATUS_OPEN,
        'contact_id' => null,
        'assignee_id' => null,
    ]);

    reassign($this, $task, (string) $this->colleague->id())->assertRedirect();

    EventFacade::assertDispatched(LeadHubTaskAssigned::class);
    expect(Event::where('type', Event::TYPE_TASK_ASSIGNED)->count())->toBe(0);
});

it('registers the assignment as a webhook trigger, because a new event type is public surface', function (): void {
    expect(WebhookManagerBridge::TRIGGERS)->toHaveKey(LeadHubTaskAssigned::class);

    [$handle, $label] = WebhookManagerBridge::TRIGGERS[LeadHubTaskAssigned::class];

    expect($handle)->toBe('leadhub.task.assigned')
        ->and($label)->toBeString()->not->toBeEmpty();
});

it('has the timeline summary in both locales', function (): void {
    foreach (['en', 'de'] as $locale) {
        $lines = require __DIR__."/../../resources/lang/{$locale}/timeline.php";

        expect($lines)->toHaveKey('task_assigned')
            ->and($lines)->toHaveKey('task_unassigned')
            ->and($lines['task_assigned'])->toContain(':owner')
            ->and($lines['task_assigned'])->toContain(':task');
    }
});
