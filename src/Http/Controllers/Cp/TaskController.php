<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\TaskService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.tasks', false), 404);

        $filter = $request->string('filter')->toString() ?: 'open';

        $query = Task::query()->with('contact');

        match ($filter) {
            'today' => $query->dueToday(),
            'overdue' => $query->overdue(),
            'done' => $query->where('status', Task::STATUS_DONE),
            default => $query->open(),
        };

        $tasks = $query->orderByRaw('due_at is null, due_at asc')
            ->paginate(25)
            ->through(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_at' => $task->due_at?->format('Y-m-d H:i'),
                'is_overdue' => $task->isOverdue(),
                'assignee_id' => $task->assignee_id,
                'contact_name' => $task->contact?->displayName(),
                'contact_url' => $task->contact ? cp_route('leadhub.contacts.show', $task->contact->id) : null,
                'complete_url' => cp_route('leadhub.tasks.complete', $task->id),
            ]);

        return Inertia::render('leadhub::Tasks/Index', [
            'tasks' => $tasks,
            'filter' => $filter,
            'columns' => [
                ['label' => __('Title'), 'field' => 'title'],
                ['label' => __('Contact'), 'field' => 'contact_name'],
                ['label' => __('Priority'), 'field' => 'priority'],
                ['label' => __('Due'), 'field' => 'due_at'],
            ],
            'canManage' => $this->userCan($request, 'edit leadhub contacts'),
        ]);
    }

    public function complete(Request $request, int|string $task)
    {
        $this->authorizeOrFail($request, 'edit leadhub contacts');
        abort_unless(config('leadhub.features.tasks', false), 404);

        $model = Task::query()->findOrFail($task);
        app(TaskService::class)->complete($model, (string) ($request->user()?->id() ?? '') ?: null);

        return back()->with('success', __('leadhub::tasks.completed'));
    }
}
