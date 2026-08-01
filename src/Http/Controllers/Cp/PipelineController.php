<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;
use Goldnead\Leadhub\Services\StageTransitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PipelineController extends Controller
{
    /**
     * How far back closed opportunities stay on the board.
     *
     * Filtering the board on open() alone made every won deal vanish the moment
     * it was won — indistinguishable from data loss, and it left the win column
     * summing to 0. Closed deals are therefore shown in their terminal stage
     * for a window, which the user can widen or switch off.
     */
    public const CLOSED_WINDOWS = [
        'none' => null,   // open only (the old behaviour, now opt-in)
        '30d' => 30,
        '90d' => 90,
        '365d' => 365,
        'all' => 0,       // every closed deal, no time limit
    ];

    public const DEFAULT_CLOSED_WINDOW = '30d';

    /**
     * Kanban board for a pipeline: stages as columns, opportunities as cards.
     * Defaults to the first active pipeline.
     */
    public function board(Request $request, int|string|null $pipeline = null)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.pipelines', false), 404);

        $pipelines = Pipeline::query()->active()->orderBy('sort_order')->get();

        // Feature enabled but no pipeline configured yet: render a native empty
        // state instead of a hard 404, so the user is guided to create one.
        if ($pipelines->isEmpty()) {
            return Inertia::render('leadhub::Pipelines/Board', [
                'pipeline' => null,
                'pipelines' => [],
                'columns' => [],
                'manageUrl' => cp_route('leadhub.pipelines.manage'),
                'canConfigure' => $this->userCan($request, 'manage leadhub settings'),
                'canManage' => $this->userCan($request, 'edit leadhub contacts'),
                'closedWindow' => self::DEFAULT_CLOSED_WINDOW,
                'closedWindowOptions' => $this->closedWindowOptions(),
                'newOpportunityUrl' => null,
                'canManageOpportunities' => $this->userCan($request, 'manage leadhub opportunities'),
                'totals' => ['open' => 0.0, 'closed' => 0.0, 'won' => 0.0, 'lost' => 0.0],
            ]);
        }

        $current = $pipeline
            ? $pipelines->firstWhere(fn ($p) => (string) $p->id === (string) $pipeline || $p->slug === $pipeline)
            : $pipelines->first();
        $current ??= $pipelines->first();
        $current->load('stages');

        $window = $request->string('closed')->toString();
        $window = array_key_exists($window, self::CLOSED_WINDOWS) ? $window : self::DEFAULT_CLOSED_WINDOW;

        $opportunities = $this->boardQuery($current, $window)
            ->with('contact')
            ->orderByDesc('last_activity_at')
            ->get();

        $columns = $current->stages->map(function (Stage $stage) use ($opportunities) {
            $inStage = $opportunities->where('stage_id', $stage->id);
            $open = $inStage->where('status', Opportunity::STATUS_OPEN);
            $closed = $inStage->where('status', Opportunity::STATUS_CLOSED);

            return [
                'id' => $stage->id,
                'name' => $stage->name,
                'slug' => $stage->slug,
                'is_terminal' => (bool) $stage->is_terminal,
                'terminal_outcome' => $stage->terminal_outcome,
                'cards' => $inStage->map(fn (Opportunity $opp) => [
                    'id' => $opp->id,
                    'title' => $opp->title,
                    'value_estimate' => $opp->value_estimate,
                    'confidence' => $opp->confidence,
                    'is_closed' => $opp->status === Opportunity::STATUS_CLOSED,
                    'outcome' => $opp->outcome,
                    'closed_at' => $opp->closed_at?->format('Y-m-d'),
                    'contact_name' => $opp->contact?->displayName(),
                    'contact_url' => $opp->contact ? cp_route('leadhub.contacts.show', $opp->contact->id) : null,
                    'move_url' => cp_route('leadhub.pipelines.move', $opp->id),
                    'edit_url' => cp_route('leadhub.pipelines.opportunities.edit', $opp->id),
                ])->values()->all(),
                // The stage total counts what is actually shown in the column,
                // closed deals included — that is the number the win column was
                // missing.
                'total_value' => (float) $inStage->sum('value_estimate'),
                'open_value' => (float) $open->sum('value_estimate'),
                'closed_value' => (float) $closed->sum('value_estimate'),
                'open_count' => $open->count(),
                'closed_count' => $closed->count(),
            ];
        })->values()->all();

        $closed = $opportunities->where('status', Opportunity::STATUS_CLOSED);

        return Inertia::render('leadhub::Pipelines/Board', [
            'pipeline' => ['id' => $current->id, 'name' => $current->name, 'slug' => $current->slug],
            'pipelines' => $pipelines->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'url' => cp_route('leadhub.pipelines.board.show', $p->id),
            ])->all(),
            'columns' => $columns,
            'manageUrl' => cp_route('leadhub.pipelines.manage'),
            'canConfigure' => $this->userCan($request, 'manage leadhub settings'),
            'canManage' => $this->userCan($request, 'edit leadhub contacts'),
            'closedWindow' => $window,
            'closedWindowOptions' => $this->closedWindowOptions(),
            // Entry point for creating an opportunity. Null (no button) when
            // the user may not manage them, so the board never offers an
            // action that would answer 403.
            'newOpportunityUrl' => $this->userCan($request, 'manage leadhub opportunities')
                ? cp_route('leadhub.pipelines.opportunities.create')
                : null,
            'canManageOpportunities' => $this->userCan($request, 'manage leadhub opportunities'),
            'totals' => [
                'open' => (float) $opportunities->where('status', Opportunity::STATUS_OPEN)->sum('value_estimate'),
                'closed' => (float) $closed->sum('value_estimate'),
                'won' => (float) $closed->where('outcome', 'won')->sum('value_estimate'),
                'lost' => (float) $closed->where('outcome', 'lost')->sum('value_estimate'),
            ],
        ]);
    }

    /**
     * Open opportunities always; closed ones only inside the chosen window.
     *
     * The pipeline is already brand-scoped by the model's global scope, and so
     * is Opportunity — widening the status filter does not widen brand access.
     */
    protected function boardQuery(Pipeline $pipeline, string $window): Builder
    {
        $query = Opportunity::query()->where('pipeline_id', $pipeline->id);

        if ($window === 'none') {
            return $query->open();
        }

        if ($window === 'all') {
            return $query;
        }

        $since = now()->subDays(self::CLOSED_WINDOWS[$window])->startOfDay();

        return $query->where(function (Builder $q) use ($since) {
            $q->open()->orWhere(function (Builder $closed) use ($since) {
                $closed->where('status', Opportunity::STATUS_CLOSED)
                    ->where(function (Builder $dates) use ($since) {
                        $dates->where('closed_at', '>=', $since)
                            ->orWhere('won_at', '>=', $since)
                            ->orWhere('lost_at', '>=', $since);
                    });
            });
        });
    }

    protected function closedWindowOptions(): array
    {
        return [
            ['value' => 'none', 'label' => __('leadhub::pipelines.closed_none')],
            ['value' => '30d', 'label' => __('leadhub::pipelines.closed_days', ['days' => 30])],
            ['value' => '90d', 'label' => __('leadhub::pipelines.closed_days', ['days' => 90])],
            ['value' => '365d', 'label' => __('leadhub::pipelines.closed_days', ['days' => 365])],
            ['value' => 'all', 'label' => __('leadhub::pipelines.closed_all')],
        ];
    }

    /** Pipeline management: list pipelines + their stages. */
    public function manage(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub settings');
        abort_unless(config('leadhub.features.pipelines', false), 404);

        $pipelines = Pipeline::query()->with('stages')->orderBy('sort_order')->get()
            ->map(fn (Pipeline $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'is_active' => (bool) $p->is_active,
                'board_url' => cp_route('leadhub.pipelines.board.show', $p->id),
                'add_stage_url' => cp_route('leadhub.pipelines.stages.store', $p->id),
                'reorder_url' => cp_route('leadhub.pipelines.stages.reorder', $p->id),
                'stages' => $p->stages->map(fn (Stage $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'sort_order' => (int) $s->sort_order,
                    'is_terminal' => (bool) $s->is_terminal,
                    'terminal_outcome' => $s->terminal_outcome,
                    'opportunities_count' => $s->opportunities()->count(),
                    'update_url' => cp_route('leadhub.pipelines.stages.update', [$p->id, $s->id]),
                    'delete_url' => cp_route('leadhub.pipelines.stages.destroy', [$p->id, $s->id]),
                ])->values()->all(),
            ])->all();

        return Inertia::render('leadhub::Pipelines/Manage', [
            'pipelines' => $pipelines,
            'storeUrl' => cp_route('leadhub.pipelines.store'),
            'canManage' => $this->userCan($request, 'manage leadhub settings'),
        ]);
    }

    /** Create a pipeline with stages. */
    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub settings');
        abort_unless(config('leadhub.features.pipelines', false), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.is_terminal' => ['nullable', 'boolean'],
            'stages.*.terminal_outcome' => ['nullable', 'in:won,lost'],
        ]);

        LeadHub::createPipeline($validated['name'], $validated['stages']);

        return back()->with('success', __('leadhub::pipelines.created'));
    }

    /** Append a stage to an existing pipeline. */
    public function storeStage(Request $request, int|string $pipeline)
    {
        $model = $this->authorizeStageManagement($request, $pipeline);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_terminal' => ['nullable', 'boolean'],
            'terminal_outcome' => ['nullable', 'in:won,lost'],
        ]);

        $model->stages()->create([
            'name' => $validated['name'],
            'sort_order' => ((int) $model->stages()->max('sort_order')) + 1,
            'is_terminal' => (bool) ($validated['is_terminal'] ?? false),
            'terminal_outcome' => ($validated['is_terminal'] ?? false) ? ($validated['terminal_outcome'] ?? null) : null,
        ]);

        return back()->with('success', __('leadhub::pipelines.stage_created'));
    }

    /** Rename a stage or change its terminal behaviour. */
    public function updateStage(Request $request, int|string $pipeline, int|string $stage)
    {
        $model = $this->authorizeStageManagement($request, $pipeline);
        $stageModel = $model->stages()->findOrFail($stage);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_terminal' => ['sometimes', 'boolean'],
            'terminal_outcome' => ['nullable', 'in:won,lost'],
        ]);

        if (array_key_exists('name', $validated)) {
            $stageModel->name = $validated['name'];
        }

        if (array_key_exists('is_terminal', $validated)) {
            $stageModel->is_terminal = (bool) $validated['is_terminal'];
        }

        // A non-terminal stage must not keep a dangling outcome.
        $stageModel->terminal_outcome = $stageModel->is_terminal
            ? ($validated['terminal_outcome'] ?? $stageModel->terminal_outcome)
            : null;

        $stageModel->save();

        return back()->with('success', __('leadhub::pipelines.stage_updated'));
    }

    /**
     * Reorder the stages of a pipeline.
     *
     * Takes the complete, ordered list of stage ids. Anything that is not a
     * stage of this pipeline is rejected outright rather than silently ignored:
     * a partial reorder is worse than a refused one.
     */
    public function reorderStages(Request $request, int|string $pipeline)
    {
        $model = $this->authorizeStageManagement($request, $pipeline);

        $validated = $request->validate([
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['required'],
        ]);

        $ids = array_map('intval', $validated['stage_ids']);
        $own = $model->stages()->pluck('id')->map(fn ($id) => (int) $id)->all();

        sort($ids);
        $sortedOwn = $own;
        sort($sortedOwn);

        abort_unless($ids === $sortedOwn, 422, 'stage_ids must list exactly the stages of this pipeline.');

        foreach (array_map('intval', $validated['stage_ids']) as $index => $id) {
            $model->stages()->whereKey($id)->update(['sort_order' => $index]);
        }

        return back()->with('success', __('leadhub::pipelines.stages_reordered'));
    }

    /**
     * Delete a stage.
     *
     * Refused while opportunities still sit in it — deleting would orphan them
     * off the board with no way back.
     */
    public function destroyStage(Request $request, int|string $pipeline, int|string $stage)
    {
        $model = $this->authorizeStageManagement($request, $pipeline);
        $stageModel = $model->stages()->findOrFail($stage);

        if ($stageModel->opportunities()->count() > 0) {
            return back()->withErrors([
                'stage' => __('leadhub::pipelines.stage_not_empty'),
            ]);
        }

        if ($model->stages()->count() <= 1) {
            return back()->withErrors([
                'stage' => __('leadhub::pipelines.stage_last'),
            ]);
        }

        $stageModel->delete();

        // Close the gap so sort_order stays dense.
        foreach ($model->stages()->orderBy('sort_order')->get() as $index => $remaining) {
            $remaining->update(['sort_order' => $index]);
        }

        return back()->with('success', __('leadhub::pipelines.stage_deleted'));
    }

    public function move(Request $request, int|string $opportunity)
    {
        $this->authorizeOrFail($request, 'edit leadhub contacts');
        abort_unless(config('leadhub.features.pipelines', false), 404);

        $validated = $request->validate([
            'stage_id' => ['required'],
            'note' => ['nullable', 'string'],
        ]);

        $opp = Opportunity::query()->findOrFail($opportunity);
        $stage = $opp->pipeline->stages()->findOrFail($validated['stage_id']);

        app(StageTransitionService::class)->transition(
            $opp,
            $stage,
            $validated['note'] ?? null,
            $this->userId($request) ?: null,
        );

        return back()->with('success', __('leadhub::pipelines.moved'));
    }

    /**
     * Shared guard for every stage-editing route: feature flag, permission, and
     * a pipeline resolved through the brand-scoped query.
     */
    protected function authorizeStageManagement(Request $request, int|string $pipeline): Pipeline
    {
        $this->authorizeOrFail($request, 'manage leadhub settings');
        abort_unless(config('leadhub.features.pipelines', false), 404);

        return Pipeline::query()->findOrFail($pipeline);
    }
}
