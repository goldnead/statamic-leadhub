<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Events\LeadHubTaskAssigned;
use Goldnead\Leadhub\Http\Requests\StoreTaskRequest;
use Goldnead\Leadhub\Http\Requests\UpdateTaskRequest;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\TaskService;
use Goldnead\Leadhub\Services\TimelineService;
use Goldnead\Leadhub\Support\ContactPicker;
use Goldnead\Leadhub\Support\OpportunityPicker;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

class TaskController extends Controller
{
    public function __construct(protected UserDirectory $users)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.tasks', false), 404);

        $filter = $request->string('filter')->toString() ?: 'open';

        // Assignee filter, mirroring what ContactController::index has offered
        // since v1.0: ?mine=1 for the current user, ?assignee_id=<id> for a
        // specific one, ?assignee_id=none for unassigned work.
        $mine = $request->boolean('mine');
        $assignee = $mine
            ? ($this->userId($request) ?: null)
            : ($request->string('assignee_id')->toString() ?: null);

        $query = Task::query()->with('contact');

        match ($filter) {
            'today' => $query->dueToday(),
            'overdue' => $query->overdue(),
            'done' => $query->where('status', Task::STATUS_DONE),
            default => $query->open(),
        };

        if ($assignee === 'none') {
            $query->whereNull('assignee_id');
        } elseif ($assignee !== null) {
            $query->forAssignee($assignee);
        }

        $page = $query->orderByRaw('due_at is null, due_at asc')
            ->paginate(25, ['*'], 'page', (int) $request->input('page', 1));

        $canManage = $this->userCan($request, 'manage leadhub tasks');
        $assignableUsers = $this->users->assignable();
        $assigneeLabels = collect($assignableUsers)->pluck('label', 'value')->all();

        $rows = collect($page->items())->map(fn (Task $task) => [
            'id' => (string) $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->format('Y-m-d H:i'),
            'is_overdue' => $task->isOverdue(),
            'assignee_id' => $task->assignee_id,
            // Fall back to UserDirectory::label() for an assignee who is no
            // longer in the assignable list (permission revoked, account
            // renamed). Showing a stale name beats showing a bare id.
            'assignee_name' => $task->assignee_id
                ? ($assigneeLabels[(string) $task->assignee_id] ?? $this->users->label($task->assignee_id))
                : null,
            'contact_name' => $task->contact?->displayName(),
            'contact_url' => $task->contact ? cp_route('leadhub.contacts.show', $task->contact->id) : null,
            'complete_url' => cp_route('leadhub.tasks.complete', $task->id),
            'edit_url' => cp_route('leadhub.tasks.edit', $task->id),
            'delete_url' => cp_route('leadhub.tasks.destroy', $task->id),
        ])->all();

        $columns = collect([
            Column::make('title')->label(__('Title'))->sortable(false),
            Column::make('contact_name')->label(__('Contact')),
            Column::make('assignee_name')->label(__('leadhub::tasks.assignee')),
            Column::make('priority')->label(__('Priority')),
            Column::make('due_at')->label(__('Due')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Tasks/Index', [
            'tasks' => $rows,
            'filter' => $filter,
            'columns' => $columns,
            'canManage' => $canManage,
            'canComplete' => $canManage || $this->userCan($request, 'edit leadhub contacts'),
            'assignableUsers' => $assignableUsers,
            'assigneeFilter' => $mine ? '' : ($request->string('assignee_id')->toString() ?: ''),
            'mine' => $mine,
            'currentUserId' => $this->userId($request),
            'createUrl' => $canManage ? cp_route('leadhub.tasks.create') : null,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub tasks');
        abort_unless(config('leadhub.features.tasks', false), 404);

        return Inertia::render('leadhub::Tasks/Create', [
            'task' => [
                'contact_id' => $request->string('contact')->toString() ?: '',
                'assignee_id' => $this->userId($request),
                'priority' => Task::PRIORITY_NORMAL,
                'opportunity_id' => $request->string('opportunity')->toString() ?: '',
            ],
            'assignableUsers' => $this->users->assignable(),
            'priorityOptions' => $this->priorityOptions(),
            'contactOptions' => $this->contactOptions($request->string('contact')->toString() ?: null),
            'contactSearchUrl' => cp_route('leadhub.contacts.options'),
            'pipelinesEnabled' => (bool) config('leadhub.features.pipelines', false),
            'opportunityOptions' => $this->opportunityOptionsFor(
                $request->string('contact')->toString() ?: null,
                $request->string('opportunity')->toString() ?: null,
            ),
            'opportunitySearchUrl' => cp_route('leadhub.tasks.opportunityOptions'),
            'storeUrl' => cp_route('leadhub.tasks.store'),
            'cancelUrl' => cp_route('leadhub.tasks.index'),
        ]);
    }

    /**
     * Created through Services\TaskService, not Task::create(), so the CP path
     * fires LeadHubTaskCreated and writes the timeline entry exactly like the
     * facade path. Skipping the service is the single most likely way for
     * CP-created records to become invisible to the webhook bridge.
     */
    public function store(StoreTaskRequest $request)
    {
        abort_unless(config('leadhub.features.tasks', false), 404);

        $validated = $request->validated();
        $contact = filled($validated['contact_id'] ?? null)
            ? Contact::query()->find($validated['contact_id'])
            : null;

        $attributes = collect($validated)
            ->except('contact_id')
            ->put('created_by', $this->userId($request) ?: null)
            ->all();

        // Blank means "no deal", not the string "". A foreign key column
        // takes null.
        if (array_key_exists('opportunity_id', $attributes) && blank($attributes['opportunity_id'])) {
            $attributes['opportunity_id'] = null;
        }

        app(TaskService::class)->create($attributes, $contact);

        return redirect(cp_route('leadhub.tasks.index'))
            ->with('success', __('leadhub::tasks.created'));
    }

    public function edit(Request $request, int|string $task)
    {
        $this->authorizeOrFail($request, 'manage leadhub tasks');
        abort_unless(config('leadhub.features.tasks', false), 404);

        $model = Task::query()->with('contact')->findOrFail($task);

        return Inertia::render('leadhub::Tasks/Edit', [
            'task' => [
                'id' => (string) $model->id,
                'title' => $model->title,
                'description' => $model->description,
                'contact_id' => (string) ($model->contact_id ?? ''),
                'priority' => $model->priority,
                'status' => $model->status,
                // ISO for the picker, human-readable for the summary line.
                'due_at' => $model->due_at?->format('Y-m-d H:i'),
                'assignee_id' => (string) ($model->assignee_id ?? ''),
                'opportunity_id' => (string) ($model->opportunity_id ?? ''),
            ],
            'assignableUsers' => $this->users->assignable(),
            'priorityOptions' => $this->priorityOptions(),
            'contactOptions' => $this->contactOptions((string) ($model->contact_id ?? '') ?: null),
            'contactSearchUrl' => cp_route('leadhub.contacts.options'),
            'pipelinesEnabled' => (bool) config('leadhub.features.pipelines', false),
            'opportunityOptions' => $this->opportunityOptionsFor(
                $model->contact_id,
                (string) ($model->opportunity_id ?? '') ?: null,
            ),
            'opportunitySearchUrl' => cp_route('leadhub.tasks.opportunityOptions'),
            'updateUrl' => cp_route('leadhub.tasks.update', $model->id),
            'cancelUrl' => cp_route('leadhub.tasks.index'),
        ]);
    }

    public function update(UpdateTaskRequest $request, int|string $task)
    {
        abort_unless(config('leadhub.features.tasks', false), 404);

        $model = Task::query()->findOrFail($task);
        $validated = $request->validated();

        // Captured before filling. The comparison has to happen on the ids,
        // not on the labels: two accounts can share a display name, and a
        // label is a rendering of the id rather than the thing itself.
        $oldAssignee = $model->assignee_id === null ? null : (string) $model->assignee_id;

        $model->fill($validated);

        if (array_key_exists('contact_id', $validated)) {
            $model->contact_id = filled($validated['contact_id']) ? $validated['contact_id'] : null;
        }

        if (array_key_exists('opportunity_id', $validated)) {
            $model->opportunity_id = filled($validated['opportunity_id']) ? $validated['opportunity_id'] : null;
        }

        $newAssignee = $model->assignee_id === null ? null : (string) $model->assignee_id;
        $assignmentChanged = $oldAssignee !== $newAssignee;

        $model->save();

        if ($assignmentChanged) {
            // The timeline entry needs a contact to hang on; a task without
            // one is still a real reassignment, so the event fires either way.
            // Silently dropping both would be the version of this feature that
            // looks built and is not.
            if ($model->contact_id && $contact = Contact::query()->find($model->contact_id)) {
                app(TimelineService::class)->recordTaskAssigned(
                    $contact,
                    (string) $model->title,
                    $oldAssignee ? $this->users->label($oldAssignee) : null,
                    $newAssignee ? $this->users->label($newAssignee) : null,
                    $oldAssignee,
                    $newAssignee,
                );
            }

            event(new LeadHubTaskAssigned($model, $oldAssignee, $newAssignee));
        }

        return redirect(cp_route('leadhub.tasks.index'))
            ->with('success', __('leadhub::tasks.updated'));
    }

    /**
     * Tasks delete outright: nothing references a task. This is the other half
     * of the deletion rule — it refuses where something hangs on the record,
     * and it must not refuse where nothing does.
     */
    public function destroy(Request $request, int|string $task)
    {
        $this->authorizeOrFail($request, 'manage leadhub tasks');
        abort_unless(config('leadhub.features.tasks', false), 404);

        Task::query()->findOrFail($task)->delete();

        return back()->with('success', __('leadhub::tasks.deleted'));
    }

    /**
     * Completing accepts either permission on purpose. `edit leadhub contacts`
     * is what this route has always required, and installs upgrading into
     * v1.7.0 do not yet hold the new `manage leadhub tasks` on any role —
     * narrowing it here would take "mark complete" away from everyone until an
     * administrator edits their roles.
     */
    public function complete(Request $request, int|string $task)
    {
        if (! $this->userCan($request, 'manage leadhub tasks')) {
            $this->authorizeOrFail($request, 'edit leadhub contacts');
        }

        abort_unless(config('leadhub.features.tasks', false), 404);

        $model = Task::query()->findOrFail($task);
        app(TaskService::class)->complete($model, $this->userId($request) ?: null);

        return back()->with('success', __('leadhub::tasks.completed'));
    }

    protected function priorityOptions(): array
    {
        return [
            ['value' => Task::PRIORITY_LOW, 'label' => __('leadhub::tasks.priorities.low')],
            ['value' => Task::PRIORITY_NORMAL, 'label' => __('leadhub::tasks.priorities.normal')],
            ['value' => Task::PRIORITY_HIGH, 'label' => __('leadhub::tasks.priorities.high')],
        ];
    }

    /**
     * A first page of contacts for the picker, plus the one already selected
     * so an edit form never shows an empty box for a contact that exists. The
     * picker searches the rest through leadhub.contacts.options.
     */
    protected function contactOptions(?string $selectedId = null): array
    {
        return app(ContactPicker::class)->options(null, $selectedId);
    }

    /**
     * Open opportunities of one contact, for the picker on the task form.
     *
     * The list has to be refetched whenever the contact selection changes,
     * which is why this is an endpoint rather than a payload baked into the
     * form: a task form that offered every deal in the install would be
     * offering the wrong ones.
     */
    public function opportunityOptions(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub tasks');
        abort_unless(config('leadhub.features.tasks', false), 404);
        abort_unless(config('leadhub.features.pipelines', false), 404);

        return response()->json([
            'options' => app(OpportunityPicker::class)->optionsForContact(
                $request->string('contact')->toString() ?: null,
                $request->string('selected')->toString() ?: null,
            ),
        ]);
    }

    protected function opportunityOptionsFor(mixed $contactId, ?string $selectedId = null): array
    {
        if (! config('leadhub.features.pipelines', false)) {
            return [];
        }

        return app(OpportunityPicker::class)->optionsForContact($contactId, $selectedId);
    }
}
