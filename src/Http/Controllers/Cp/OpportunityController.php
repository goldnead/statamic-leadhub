<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Http\Requests\StoreOpportunityRequest;
use Goldnead\Leadhub\Http\Requests\UpdateOpportunityRequest;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\OpportunityService;
use Goldnead\Leadhub\Services\StageTransitionService;
use Goldnead\Leadhub\Support\ContactPicker;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Http\Request;
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
    ) {
    }

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
            'updateUrl' => cp_route('leadhub.pipelines.opportunities.update', $model->id),
            'deleteUrl' => cp_route('leadhub.pipelines.opportunities.destroy', $model->id),
            'cancelUrl' => cp_route('leadhub.pipelines.board.show', $model->pipeline_id),
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

        return redirect(cp_route('leadhub.pipelines.board.show', $model->pipeline_id))
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

    protected function guard(Request $request): void
    {
        $this->authorizeOrFail($request, 'manage leadhub opportunities');
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
