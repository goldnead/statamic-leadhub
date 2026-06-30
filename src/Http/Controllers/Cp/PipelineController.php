<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Services\StageTransitionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PipelineController extends Controller
{
    /**
     * Kanban board for a pipeline: stages as columns, open opportunities as
     * cards. Defaults to the first active pipeline.
     */
    public function board(Request $request, int|string|null $pipeline = null)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.pipelines', false), 404);

        $pipelines = Pipeline::query()->active()->orderBy('sort_order')->get();
        abort_if($pipelines->isEmpty(), 404);

        $current = $pipeline
            ? $pipelines->firstWhere(fn ($p) => (string) $p->id === (string) $pipeline || $p->slug === $pipeline)
            : $pipelines->first();
        $current ??= $pipelines->first();
        $current->load('stages');

        $opportunities = Opportunity::query()
            ->where('pipeline_id', $current->id)
            ->open()
            ->with('contact')
            ->orderByDesc('last_activity_at')
            ->get();

        $columns = $current->stages->map(fn ($stage) => [
            'id' => $stage->id,
            'name' => $stage->name,
            'slug' => $stage->slug,
            'is_terminal' => (bool) $stage->is_terminal,
            'terminal_outcome' => $stage->terminal_outcome,
            'cards' => $opportunities->where('stage_id', $stage->id)->map(fn (Opportunity $opp) => [
                'id' => $opp->id,
                'title' => $opp->title,
                'value_estimate' => $opp->value_estimate,
                'confidence' => $opp->confidence,
                'contact_name' => $opp->contact?->displayName(),
                'contact_url' => $opp->contact ? cp_route('leadhub.contacts.show', $opp->contact->id) : null,
                'move_url' => cp_route('leadhub.pipelines.move', $opp->id),
            ])->values()->all(),
            'total_value' => (float) $opportunities->where('stage_id', $stage->id)->sum('value_estimate'),
        ])->values()->all();

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
        ]);
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
                'stages' => $p->stages->map(fn ($s) => [
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'is_terminal' => (bool) $s->is_terminal,
                    'terminal_outcome' => $s->terminal_outcome,
                ])->all(),
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

        \Goldnead\Leadhub\Facades\LeadHub::createPipeline($validated['name'], $validated['stages']);

        return back()->with('success', __('leadhub::pipelines.created'));
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
            (string) ($request->user()?->id() ?? '') ?: null,
        );

        return back()->with('success', __('leadhub::pipelines.moved'));
    }
}
