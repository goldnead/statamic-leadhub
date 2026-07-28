<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Integrations\Notifications\NotificationsBridge;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Support\Facades\Log;

/**
 * Tells the person who was just handed a task.
 *
 * Through v1.9.0 a reassignment wrote a timeline entry and fired
 * `LeadHubTaskAssigned`, so the history existed and an outside system could
 * subscribe to it — but the colleague holding the task found out by opening the
 * task list. This is the missing half.
 *
 * Deliberately not another Laravel mail notification beside
 * `Services\LeadHubNotifier`. That class is LeadHub's own second invention of
 * the pattern (which is what justified extracting
 * `goldnead/statamic-notifications` in the first place); a third one for tasks
 * would give the recipient two inboxes, no preferences and no digest.
 */
class TaskAssignmentNotifier
{
    public function __construct(protected UserDirectory $users)
    {
    }

    /**
     * Whether this hand-over is worth telling anybody about.
     *
     * Separate from the delivery below on purpose: this is the part with the
     * decisions in it, and it has to be assertable in an install that does not
     * have the notifications addon — which is every install running the default
     * test suite.
     */
    public function shouldNotify(?string $assigneeId, ?string $actorId): bool
    {
        if (! config('leadhub.notifications.on_task_assignment', true)) {
            return false;
        }

        // Unassigning is not an assignment. Nobody to tell.
        if (blank($assigneeId)) {
            return false;
        }

        // Handing work to yourself is not news, and a notification for it is
        // the kind of noise that makes people switch the whole type off — which
        // then costs them the notifications that were worth having. Contact
        // assignment currently does notify on self-assignment; that is the
        // behaviour this deliberately does not copy.
        if ($actorId !== null && (string) $actorId === (string) $assigneeId) {
            return false;
        }

        return true;
    }

    /**
     * @param  string|null  $assigneeId  who now holds the task
     * @param  string|null  $actorId     who did the assigning, when known
     */
    public function assigned(Task $task, ?string $assigneeId, ?string $actorId = null): void
    {
        if (! $this->shouldNotify($assigneeId, $actorId)) {
            return;
        }

        if (! NotificationsBridge::available()) {
            return;
        }

        $recipient = $this->users->find($assigneeId);

        if (! $recipient) {
            return;
        }

        try {
            app('notifications')->notify($recipient, NotificationsBridge::TASK_ASSIGNED, [
                'actor' => $actorId ? $this->users->find($actorId) : null,
                'subject' => $task,
                'message' => __('leadhub::tasks.notifications.assigned_message', [
                    'title' => (string) $task->title,
                ]),
                'link' => cp_route('leadhub.tasks.edit', $task->id),
                // Scoped to the moment, not to the task: a task that travels
                // A → B → A has to reach A twice. `updated_at` is what makes a
                // double-submitted form one notification and a genuine second
                // handover two.
                'dedupe_key' => 'leadhub-task-assigned:'.$task->id.':'.$assigneeId
                    .':'.($task->updated_at?->getTimestamp() ?? 0),
            ]);
        } catch (\Throwable $e) {
            // A notification failure must never roll back the assignment that
            // caused it — the same rule LeadHubNotifier follows for mail.
            Log::warning('[LeadHub] Task assignment notification failed', [
                'task_id' => $task->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
