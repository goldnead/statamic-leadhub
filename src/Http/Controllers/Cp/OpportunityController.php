<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Http\Requests\StoreOpportunityRequest;
use Goldnead\Leadhub\Http\Requests\UpdateOpportunityRequest;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;
use Goldnead\Leadhub\Models\StageTransition;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\OpportunityService;
use Goldnead\Leadhub\Services\StageTransitionService;
use Goldnead\Leadhub\Support\ContactPicker;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/**
 * Create, edit and delete opportunities.
 *
 * Its own controller on purpose: PipelineController is already the board, the
 * management screen, stage editing and the move endpoint. Adding a fourth
 * concern there would have made the largest file in the addon larger still.
 */
class OpportunityController extends Controller
{
    public function __construct(
        protected UserDirectory $users,
        protected ContactPicker $contacts,
    ) {}

    public function create(Request $request)
    {
        $this->guard($request);

        $pipelines = Pipeline::query()->active()->with('stages')->orderBy('sort_order')->get();
        abort_if($pipelines->isEmpty(), 404);

        $requestedPipeline = $request->string('pipeline')->toString();
        $current = $pipelines->firstWhere(fn ($p) => (string) $p->id === $requestedPipeline) ?? $pipelines->first();
        $contactId = $request->string('contact')->toString() ?: '';

        return Inertia::render('leadhub::Pipelines/OpportunityCreate', [
            'opportunity' => [
                'contact_id' => $contactId,
                'company_id' => '',
                'pipeline_id' => (string) $current->id,
                // The board passes the column it was clicked in; otherwise the
                // pipeline's first stage.
                'stage_id' => (string) ($request->string('stage')->toString()
                    ?: ($current->defaultStage()?->id ?? '')),
                'title' => '',
                'value_estimate' => '',
                'confidence' => 0,
                'owner_id' => $this->userId($request),
            ],
            'pipelines' => $this->pipelinePayload($pipelines),
            'companyOptions' => $this->companyOptions(),
            'contactOptions' => $this->contacts->options(null, $contactId ?: null),
            'contactSearchUrl' => cp_route('leadhub.contacts.options'),
            'assignableUsers' => $this->users->assignable(),
            'storeUrl' => cp_route('leadhub.pipelines.opportunities.store'),
            'cancelUrl' => cp_route('leadhub.pipelines.board.show', $current->id),
        ]);
    }

    /**
     * Created through Services\OpportunityService so the timeline entry and
     * LeadHubOpportunityCreated fire on the CP path exactly like on the
     * facade path, and so creating directly into a terminal stage closes the
     * deal instead of leaving an open opportunity in a "Won" column.
     */
    public function store(StoreOpportunityRequest $request)
    {
        $this->guard($request);

        $validated = $request->validated();

        $contact = Contact::query()->findOrFail($validated['contact_id']);
        $pipeline = Pipeline::query()->findOrFail($validated['pipeline_id']);

        $opportunity = app(OpportunityService::class)->create($contact, $pipeline, [
            'stage_id' => $validated['stage_id'] ?? null,
            'company_id' => $validated['company_id'] ?? null,
            'title' => filled($validated['title'] ?? null) ? $validated['title'] : null,
            'value_estimate' => $validated['value_estimate'] ?? null,
            'confidence' => (int) ($validated['confidence'] ?? 0),
            'owner_id' => $validated['owner_id'] ?? null,
        ]);

        return redirect(cp_route('leadhub.pipelines.board.show', $opportunity->pipeline_id))
            ->with('success', __('leadhub::pipelines.opportunity_created'));
    }

    /**
     * The deal's own screen: what it is, where it stands, and what happened.
     *
     * Until now nothing in the CP pointed at a single deal. Every link — the
     * contact screen's opportunity list, the board's own cards — went to the
     * *board*, which answers "what is in this column" and not "why did this one
     * move". The stage history has been written since the pipelines module
     * shipped (`leadhub_stage_transitions`, one row per move, with the note that
     * says why) and nothing has ever read it. This is the reader.
     *
     * Reading is `view leadhub`, like the board; every action offered on the
     * page is `manage leadhub opportunities`, like creating and deleting one.
     * The permissions travel as props so the page never draws a button that
     * would answer 403.
     */
    public function show(Request $request, int|string $opportunity)
    {
        $this->readGuard($request);

        $model = Opportunity::query()
            ->with(['pipeline.stages', 'contact', 'company'])
            ->findOrFail($opportunity);

        $canManage = $this->userCan($request, 'manage leadhub opportunities');
        $pipeline = $model->pipeline;
        $stages = $pipeline ? $pipeline->stages : new EloquentCollection;
        $currentStage = $stages->firstWhere('id', $model->stage_id);

        return Inertia::render('leadhub::Pipelines/OpportunityShow', [
            'opportunity' => [
                'id' => (string) $model->id,
                'title' => $this->displayTitle($model),
                'status' => $model->status,
                'outcome' => $model->outcome,
                'is_open' => $model->isOpen(),
                'contact_name' => $model->contact?->displayName(),
                'contact_url' => $model->contact
                    ? cp_route('leadhub.contacts.show', $model->contact->id)
                    : null,
                'company_name' => config('leadhub.features.companies', false)
                    ? $model->company?->displayName()
                    : null,
                'company_url' => config('leadhub.features.companies', false) && $model->company
                    ? cp_route('leadhub.companies.show', $model->company->id)
                    : null,
                'pipeline_name' => $model->pipeline?->name,
                'stage_name' => $currentStage ? $currentStage->name : __('leadhub::pipelines.stage_removed'),
                'stage_id' => (string) $model->stage_id,
                'stage_is_terminal' => $currentStage !== null && $currentStage->is_terminal,
                'value_estimate' => $model->value_estimate !== null ? (float) $model->value_estimate : null,
                'confidence' => (int) $model->confidence,
                'owner_name' => $model->owner_id ? $this->users->label((string) $model->owner_id) : null,
                'created_at' => $model->created_at?->format('Y-m-d H:i'),
                'last_activity_at' => $model->last_activity_at?->format('Y-m-d H:i'),
                'closed_at' => $model->closed_at?->format('Y-m-d H:i'),
                // Deliberately only where the status agrees with them. See the
                // note on StageTransitionService: before v2.4.0 a reopened deal
                // kept the won/lost stamp of the close it had come back from,
                // and this screen is where that first becomes visible. The
                // service no longer leaves such a row behind and a migration
                // repaired the ones already stored — this guard is what keeps
                // the screen honest on an install that has not migrated yet.
                'won_at' => $model->outcome === 'won' ? $model->won_at?->format('Y-m-d H:i') : null,
                'lost_at' => $model->outcome === 'lost' ? $model->lost_at?->format('Y-m-d H:i') : null,
            ],
            'stages' => $stages->map(fn (Stage $stage) => [
                'value' => (string) $stage->id,
                'label' => $stage->name,
            ])->values()->all(),
            'history' => $this->history($model),
            'tasks' => $this->taskPanel($model),
            'tasksEnabled' => (bool) config('leadhub.features.tasks', false),
            'canManageTasks' => $this->userCan($request, 'manage leadhub tasks'),
            'canManage' => $canManage,
            'createTaskUrl' => $this->createTaskUrl($model),
            'editUrl' => $canManage ? cp_route('leadhub.pipelines.opportunities.edit', $model->id) : null,
            'deleteUrl' => $canManage ? cp_route('leadhub.pipelines.opportunities.destroy', $model->id) : null,
            // The one write path for a stage change, shared with the board's
            // drag & drop, and the only one that records the note.
            'moveUrl' => $canManage ? cp_route('leadhub.pipelines.move', $model->id) : null,
            'boardUrl' => cp_route('leadhub.pipelines.board.show', $model->pipeline_id),
        ]);
    }

    public function edit(Request $request, int|string $opportunity)
    {
        $this->guard($request);

        $model = Opportunity::query()->with(['pipeline.stages', 'contact'])->findOrFail($opportunity);

        return Inertia::render('leadhub::Pipelines/OpportunityEdit', [
            'opportunity' => [
                'id' => (string) $model->id,
                'contact_id' => (string) ($model->contact_id ?? ''),
                'contact_name' => $model->contact?->displayName(),
                'company_id' => (string) ($model->company_id ?? ''),
                'pipeline_id' => (string) $model->pipeline_id,
                'pipeline_name' => $model->pipeline?->name,
                'stage_id' => (string) $model->stage_id,
                'title' => $model->title,
                'value_estimate' => $model->value_estimate !== null ? (string) $model->value_estimate : '',
                'confidence' => (int) $model->confidence,
                'owner_id' => (string) ($model->owner_id ?? ''),
                'status' => $model->status,
                'outcome' => $model->outcome,
            ],
            'stages' => $model->pipeline->stages->map(fn ($stage) => [
                'value' => (string) $stage->id,
                'label' => $stage->name,
            ])->values()->all(),
            'companyOptions' => $this->companyOptions(),
            'assignableUsers' => $this->users->assignable(),
            'tasks' => $this->taskPanel($model),
            'tasksEnabled' => (bool) config('leadhub.features.tasks', false),
            'canManageTasks' => $this->userCan($request, 'manage leadhub tasks'),
            'createTaskUrl' => $this->createTaskUrl($model),
            'updateUrl' => cp_route('leadhub.pipelines.opportunities.update', $model->id),
            'deleteUrl' => cp_route('leadhub.pipelines.opportunities.destroy', $model->id),
            // Back to the deal, not to the board. Cancelling an edit means
            // "leave it as it was", and where it was is its own screen.
            'cancelUrl' => cp_route('leadhub.pipelines.opportunities.show', $model->id),
        ]);
    }

    public function update(UpdateOpportunityRequest $request, int|string $opportunity)
    {
        $this->guard($request);

        $model = Opportunity::query()->findOrFail($opportunity);
        $validated = $request->validated();

        foreach (['title', 'value_estimate', 'confidence', 'owner_id', 'company_id'] as $field) {
            if (array_key_exists($field, $validated)) {
                $model->setAttribute($field, filled($validated[$field]) ? $validated[$field] : null);
            }
        }

        $model->last_activity_at = now();
        $model->save();

        // A stage change is a transition, not a field edit: it has to write
        // the stage-transition row, close or reopen the deal in a terminal
        // stage, and fire the won/lost events. Doing it by hand here would
        // reproduce StageTransitionService badly.
        if (array_key_exists('stage_id', $validated)
            && filled($validated['stage_id'])
            && (string) $validated['stage_id'] !== (string) $model->stage_id) {
            $stage = $model->pipeline->stages()->findOrFail($validated['stage_id']);
            app(StageTransitionService::class)->transition($model, $stage, null, $this->userId($request) ?: null);
        }

        return redirect(cp_route('leadhub.pipelines.opportunities.show', $model->id))
            ->with('success', __('leadhub::pipelines.opportunity_updated'));
    }

    /**
     * Refused while tasks still reference the opportunity — `opportunity_id`
     * on `leadhub_tasks` has no foreign key and no cascade, so deleting would
     * leave tasks pointing at a deal that is gone. Same rule as the pipeline
     * stages and the companies: refuse, and say what is in the way.
     */
    public function destroy(Request $request, int|string $opportunity)
    {
        $this->guard($request);

        $model = Opportunity::query()->findOrFail($opportunity);

        $tasks = Task::query()->where('opportunity_id', $model->id)->count();

        if ($tasks > 0) {
            return back()->withErrors([
                'opportunity' => __('leadhub::pipelines.opportunity_has_tasks', ['count' => $tasks]),
            ]);
        }

        $pipelineId = $model->pipeline_id;
        $model->delete();

        return redirect(cp_route('leadhub.pipelines.board.show', $pipelineId))
            ->with('success', __('leadhub::pipelines.opportunity_deleted'));
    }

    /**
     * The tasks hanging on this opportunity.
     *
     * v1.9.0 let a task point at a deal from the task form only, so the link
     * was visible from one end. From the deal's end the tasks existed solely as
     * the reason `destroy()` refused — a refusal naming a count that nothing on
     * screen let you check. This is that list.
     *
     * It shows **every** task, completed ones included, because that is what
     * the deletion rule counts. Filtering to open tasks here would produce the
     * one screen this panel exists to prevent: an empty list beside "this
     * opportunity still has 3 tasks".
     *
     * No new route and no new route parameter. The panel travels in the edit
     * payload and links to the task routes that already exist — the cheapest
     * way not to repeat v1.8.1, where a generic parameter name was eaten by a
     * sibling addon's application-wide `Route::bind()`.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function taskPanel(Opportunity $opportunity): array
    {
        if (! config('leadhub.features.tasks', false)) {
            return [];
        }

        return $opportunity->tasks()
            // Open work first, then by due date, undated last — the order the
            // task list itself uses.
            ->orderByRaw('case when status = ? then 0 else 1 end', [Task::STATUS_OPEN])
            ->orderByRaw('due_at is null, due_at asc')
            ->get()
            ->map(fn (Task $task) => [
                'id' => (string) $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority ?: Task::PRIORITY_NORMAL,
                'priority_label' => __('leadhub::tasks.priorities.'.($task->priority ?: Task::PRIORITY_NORMAL)),
                'due_at' => $task->due_at?->format('Y-m-d H:i'),
                'is_overdue' => $task->isOverdue(),
                'is_open' => $task->status === Task::STATUS_OPEN,
                'assignee_name' => $task->assignee_id ? $this->users->label($task->assignee_id) : null,
                'edit_url' => cp_route('leadhub.tasks.edit', $task->id),
                'complete_url' => cp_route('leadhub.tasks.complete', $task->id),
            ])
            ->values()
            ->all();
    }

    /**
     * "New task on this deal", pre-filled with the deal and its contact. Null
     * when the tasks module is off — the route would 404.
     */
    protected function createTaskUrl(Opportunity $opportunity): ?string
    {
        if (! config('leadhub.features.tasks', false)) {
            return null;
        }

        return cp_route('leadhub.tasks.create').'?'.http_build_query(array_filter([
            'opportunity' => (string) $opportunity->id,
            'contact' => (string) ($opportunity->contact_id ?? ''),
        ]));
    }

    /**
     * The deal's stage history, newest first, with how long it sat in each.
     *
     * Built from `leadhub_stage_transitions` rather than from the contact
     * timeline, because the transition row is the more complete record: the
     * note that says *why* the deal moved is written there and nowhere else
     * (the timeline event carries the ids, not the note). The timeline is not
     * mixed in — for a stage change it holds the same fact with less of it, and
     * a deal's other contact events are about the person, not the deal.
     *
     * **A deal that was never moved still has a history.** It has no transition
     * row at all — `create()` writes none — so the entry point into the first
     * stage is `opportunities.created_at`. Left out, the most common deal on a
     * young install would show an empty panel, which reads as "nothing recorded"
     * instead of "created here, still here".
     *
     * Durations are the gap to the next entry, and for the newest entry the gap
     * to now. Clamped at zero: `created_at` and the first transition can be the
     * same second, and seeded data can have them the wrong way round.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function history(Opportunity $model): array
    {
        $transitions = $model->transitions()->get()
            // The relation sorts newest first. Durations need the other
            // direction, and `occurred_at` has second resolution — two moves in
            // the same second would otherwise order arbitrarily.
            ->sortBy([['occurred_at', 'asc'], ['id', 'asc']])
            ->values();

        $stageNames = $this->stageNames($model, $transitions);
        $actorLabels = $this->actorLabels($transitions);

        // The stage the deal started in: where the first recorded move came
        // *from*, or — for a deal that was never moved — where it still is.
        // `??`, not `?:`: the schema allows `from_stage_id` to be null, and a
        // null there means "unknown", not "zero".
        $startedIn = $transitions->isEmpty() ? null : $transitions->first()->from_stage_id;

        $entries = [[
            'key' => 'created',
            'is_start' => true,
            'from_stage_id' => null,
            'to_stage_id' => (int) ($startedIn ?? $model->stage_id),
            'at' => $model->created_at,
            'actor_id' => null,
            'note' => null,
        ]];

        foreach ($transitions as $transition) {
            $entries[] = [
                'key' => 'transition-'.$transition->id,
                'is_start' => false,
                'from_stage_id' => $transition->from_stage_id !== null ? (int) $transition->from_stage_id : null,
                'to_stage_id' => (int) $transition->to_stage_id,
                'at' => $transition->occurred_at,
                'actor_id' => $transition->actor_type === 'user' ? (string) $transition->actor_id : null,
                'note' => $transition->note,
            ];
        }

        $last = count($entries) - 1;
        $rows = [];

        // A closed deal's last stretch ends when it closed, not now. Left at
        // `now()` the top row reads "115 days" for a deal won in April and
        // grows by one every day — the same column, the same typography as the
        // real phase durations beneath it, and answering a different question.
        // The "still running" marker that would have told them apart is
        // deliberately hidden on a closed deal, so nothing was left to
        // distinguish them.
        $closedAt = $model->status === Opportunity::STATUS_CLOSED
            ? ($model->closed_at ?? $model->won_at ?? $model->lost_at)
            : null;

        foreach ($entries as $index => $entry) {
            $end = $entries[$index + 1]['at'] ?? $closedAt ?? now();
            $seconds = $entry['at'] && $end
                ? max(0, $end->getTimestamp() - $entry['at']->getTimestamp())
                : null;

            $rows[] = [
                'key' => $entry['key'],
                'is_start' => $entry['is_start'],
                // The last stretch, and whether it is still running. A closed
                // deal has a last stretch that ended.
                'is_current' => $index === $last,
                'is_running' => $index === $last && $closedAt === null,
                'from_stage_name' => $entry['from_stage_id'] !== null
                    ? ($stageNames[$entry['from_stage_id']] ?? __('leadhub::pipelines.stage_removed'))
                    : null,
                'to_stage_name' => $stageNames[$entry['to_stage_id']] ?? __('leadhub::pipelines.stage_removed'),
                'occurred_at' => $entry['at']?->format('Y-m-d H:i'),
                'actor_label' => $entry['actor_id'] !== null
                    ? ($actorLabels[$entry['actor_id']] ?? __('leadhub::pipelines.actor_unknown'))
                    : ($entry['is_start'] ? null : __('leadhub::pipelines.actor_system')),
                'note' => filled($entry['note']) ? $entry['note'] : null,
                'duration_seconds' => $seconds,
                'duration_label' => $seconds === null ? null : $this->durationLabel($seconds),
            ];
        }

        return array_reverse($rows);
    }

    /**
     * Stage id => name for everything the history mentions.
     *
     * `from_stage_id` and `to_stage_id` are raw integers with no foreign key,
     * so a stage that was emptied and then deleted leaves rows pointing at
     * nothing. The pipeline's own stages are already loaded and cost nothing;
     * anything the history names beyond them is fetched in **one** query, not
     * one per row. Whatever is still missing after that is genuinely gone and
     * the caller labels it as such.
     *
     * @param  Collection<int, StageTransition>  $transitions
     * @return array<int, string>
     */
    protected function stageNames(Opportunity $model, $transitions): array
    {
        $names = [];

        foreach ($model->pipeline?->stages ?: [] as $stage) {
            $names[(int) $stage->id] = (string) $stage->name;
        }

        $missing = $transitions
            ->flatMap(fn ($transition) => [$transition->from_stage_id, $transition->to_stage_id])
            ->push($model->stage_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => array_key_exists($id, $names))
            ->values();

        if ($missing->isNotEmpty()) {
            foreach (Stage::query()->whereKey($missing->all())->get() as $stage) {
                $names[(int) $stage->id] = (string) $stage->name;
            }
        }

        return $names;
    }

    /**
     * Actor id => display name, resolved once per distinct actor.
     *
     * `UserDirectory::label()` hits the user repository each time it is called,
     * so calling it per history row would cost one lookup per move. The number
     * of distinct people who ever touched a deal is bounded by the team, not by
     * the length of the history.
     *
     * @param  Collection<int, StageTransition>  $transitions
     * @return array<string, string|null>
     */
    protected function actorLabels($transitions): array
    {
        $labels = [];

        foreach ($transitions->where('actor_type', 'user')->pluck('actor_id')->filter()->unique() as $id) {
            $labels[(string) $id] = $this->users->label((string) $id);
        }

        return array_filter($labels, fn ($label) => $label !== null);
    }

    /**
     * How long a deal sat somewhere, in the coarsest unit that still says
     * something. "37 days" is the answer to this question; "37 days, 4 hours,
     * 12 minutes" is the same answer with the point buried.
     */
    protected function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return __('leadhub::pipelines.duration_under_minute');
        }

        if ($seconds < 3600) {
            return __('leadhub::pipelines.duration_minutes', ['count' => intdiv($seconds, 60)]);
        }

        if ($seconds < 172800) {
            return __('leadhub::pipelines.duration_hours', ['count' => intdiv($seconds, 3600)]);
        }

        return __('leadhub::pipelines.duration_days', ['count' => intdiv($seconds, 86400)]);
    }

    /**
     * The title as a human should read it. `title` is nullable in the schema
     * and only the service-created path fills the fallback in, so a row written
     * straight through the model can arrive here without one.
     */
    protected function displayTitle(Opportunity $model): string
    {
        if (filled($model->title)) {
            return (string) $model->title;
        }

        return trim(($model->contact?->displayName() ?: '').' — '.($model->pipeline?->name ?: ''), " —\t\n");
    }

    protected function guard(Request $request): void
    {
        $this->authorizeOrFail($request, 'manage leadhub opportunities');
        abort_unless(config('leadhub.features.pipelines', false), 404);
    }

    /**
     * Reading a deal is the same authority as reading the board it sits on:
     * `view leadhub`. Narrower than that would mean a user who can see the card
     * cannot open it; wider makes no sense for a screen that shows money.
     */
    protected function readGuard(Request $request): void
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.pipelines', false), 404);
    }

    protected function pipelinePayload($pipelines): array
    {
        return $pipelines->map(fn (Pipeline $pipeline) => [
            'value' => (string) $pipeline->id,
            'label' => $pipeline->name,
            'stages' => $pipeline->stages->map(fn ($stage) => [
                'value' => (string) $stage->id,
                'label' => $stage->name,
            ])->values()->all(),
        ])->values()->all();
    }

    protected function companyOptions(): array
    {
        if (! config('leadhub.features.companies', false)) {
            return [];
        }

        return Company::query()->orderBy('name')->limit(200)->get()
            ->map(fn (Company $company) => [
                'value' => (string) $company->id,
                'label' => $company->displayName(),
            ])->values()->all();
    }
}
