<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Events\LeadHubTaskCompleted;
use Goldnead\Leadhub\Events\LeadHubTaskCreated;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Full task management: multiple open tasks per contact, with priority,
 * assignee and due date. Complements the single "next action" Follow-up.
 * Eloquent driver only.
 */
class TaskService
{
    public function __construct(protected TimelineService $timeline)
    {
    }

    public function create(array $attributes, ?Contact $contact = null): Task
    {
        if ($contact) {
            $attributes['contact_id'] = $contact->id;
        }

        $attributes['status'] = $attributes['status'] ?? Task::STATUS_OPEN;
        $attributes['priority'] = $attributes['priority'] ?? Task::PRIORITY_NORMAL;

        if (isset($attributes['due_at']) && ! $attributes['due_at'] instanceof \DateTimeInterface) {
            $attributes['due_at'] = Carbon::parse($attributes['due_at']);
        }

        $task = Task::query()->create($attributes);

        if ($contact) {
            $this->timeline->recordSource(
                $contact,
                Event::TYPE_TASK_CREATED,
                __('leadhub::timeline.task_created', ['title' => $task->title]),
                ['task_id' => $task->id, 'due_at' => optional($task->due_at)->toIso8601String()],
            );
        }

        event(new LeadHubTaskCreated($task));

        return $task;
    }

    public function complete(Task $task, ?string $completedBy = null): Task
    {
        if ($task->isCompleted()) {
            return $task;
        }

        $task->status = Task::STATUS_DONE;
        $task->completed_at = now();
        $task->completed_by = $completedBy;
        $task->save();

        if ($task->contact) {
            $this->timeline->recordSource(
                $task->contact,
                Event::TYPE_TASK_COMPLETED,
                __('leadhub::timeline.task_completed', ['title' => $task->title]),
                ['task_id' => $task->id],
            );
        }

        event(new LeadHubTaskCompleted($task));

        return $task;
    }

    public function dueToday(?string $assigneeId = null, ?int $limit = null): Collection
    {
        $query = Task::query()->dueToday();

        if ($assigneeId) {
            $query->forAssignee($assigneeId);
        }

        return $query->orderBy('due_at')->limit($limit ?? 100)->get();
    }

    public function overdue(?string $assigneeId = null, ?int $limit = null): Collection
    {
        $query = Task::query()->overdue();

        if ($assigneeId) {
            $query->forAssignee($assigneeId);
        }

        return $query->orderBy('due_at')->limit($limit ?? 100)->get();
    }
}
