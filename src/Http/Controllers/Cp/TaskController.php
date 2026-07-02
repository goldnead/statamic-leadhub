<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\TaskService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

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

        $page = $query->orderByRaw('due_at is null, due_at asc')
            ->paginate(25, ['*'], 'page', (int) $request->input('page', 1));

        $rows = collect($page->items())->map(fn (Task $task) => [
            'id' => (string) $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->format('Y-m-d H:i'),
            'is_overdue' => $task->isOverdue(),
            'assignee_id' => $task->assignee_id,
            'contact_name' => $task->contact?->displayName(),
            'contact_url' => $task->contact ? cp_route('leadhub.contacts.show', $task->contact->id) : null,
            'complete_url' => cp_route('leadhub.tasks.complete', $task->id),
        ])->all();

        $columns = collect([
            Column::make('title')->label(__('Title'))->sortable(false),
            Column::make('contact_name')->label(__('Contact')),
            Column::make('priority')->label(__('Priority')),
            Column::make('due_at')->label(__('Due')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Tasks/Index', [
            'tasks' => $rows,
            'filter' => $filter,
            'columns' => $columns,
            'canManage' => $this->userCan($request, 'edit leadhub contacts'),
        ]);
    }

    public function complete(Request $request, int|string $task)
    {
        $this->authorizeOrFail($request, 'edit leadhub contacts');
        abort_unless(config('leadhub.features.tasks', false), 404);

        $model = Task::query()->findOrFail($task);
        app(TaskService::class)->complete($model, $this->userId($request) ?: null);

        return back()->with('success', __('leadhub::tasks.completed'));
    }
}
