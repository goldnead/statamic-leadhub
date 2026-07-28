<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Http\Requests\StoreScoringRuleRequest;
use Goldnead\Leadhub\Http\Requests\UpdateScoringRuleRequest;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\ScoringRule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

/**
 * The scoring rule screen: the point table, editable, per brand.
 *
 * Reads and writes go through the ScoringRule model, so the HasBrand global
 * scope decides what this screen can see and reach. There is no cross-brand
 * path here at all, including the id lookups — `findOrFail` on a scoped query
 * 404s for another brand's rule rather than opening it.
 */
class ScoringController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        $this->abortUnlessAvailable();

        $canManage = $this->userCan($request, 'manage leadhub scoring');

        $rules = ScoringRule::query()
            ->orderByRaw("case when event_type = '*' then 1 else 0 end")
            ->orderBy('event_type')
            ->get();

        $rows = $rules->map(fn (ScoringRule $rule) => [
            'id' => (string) $rule->id,
            'event_type' => $rule->event_type,
            'is_catch_all' => $rule->isCatchAll(),
            'points' => (int) $rule->points,
            'label' => $rule->label,
            'enabled' => (bool) $rule->enabled,
            'update_url' => cp_route('leadhub.scoring.update', $rule->id),
            'delete_url' => cp_route('leadhub.scoring.destroy', $rule->id),
        ])->all();

        $columns = collect([
            Column::make('event_type')->label(__('leadhub::scoring.event_type')),
            Column::make('label')->label(__('leadhub::scoring.label')),
            Column::make('points')->label(__('leadhub::scoring.points')),
            Column::make('enabled')->label(__('leadhub::scoring.enabled')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Scoring/Index', [
            'rules' => $rows,
            'columns' => $columns,
            'canManage' => $canManage,
            'storeUrl' => cp_route('leadhub.scoring.store'),
            'catchAll' => ScoringRule::CATCH_ALL,
            'configDefault' => (int) config('leadhub.scoring.default', 1),
            // With no rule at all, the config file still decides — the upgrade
            // fallback in ScoringService. The screen has to say so, or an empty
            // list reads as "nothing is being scored" when the opposite is true.
            'usingConfigFallback' => $rules->isEmpty(),
            'configRules' => collect((array) config('leadhub.scoring.events', []))
                ->map(fn ($points, $type) => ['event_type' => (string) $type, 'points' => (int) $points])
                ->values()->all(),
            'importCommand' => 'php artisan leadhub:scoring:import',
            'knownEventTypes' => $this->knownEventTypes(),
        ]);
    }

    public function store(StoreScoringRuleRequest $request)
    {
        $this->abortUnlessAvailable();

        ScoringRule::create($request->validated());

        return back()->with('success', __('leadhub::scoring.flashes.created'));
    }

    public function update(UpdateScoringRuleRequest $request, int|string $scoringRule)
    {
        $this->abortUnlessAvailable();

        $model = ScoringRule::query()->findOrFail($scoringRule);
        $model->fill($request->validated())->save();

        return back()->with('success', __('leadhub::scoring.flashes.updated'));
    }

    /**
     * Scoring rules delete outright.
     *
     * The house rule since v1.5.0 is to refuse a delete while something still
     * hangs on the record. Nothing hangs on a scoring rule: no table carries a
     * rule id, timeline entries store the numbers and a composed sentence
     * rather than a reference, and a contact's engagement_score is a running
     * total, not a sum recomputed from rules. Inventing a block here would be a
     * lock on a door with no room behind it.
     *
     * What deleting does change is the future: the type falls back to the
     * catch-all rule, and deleting the last rule of a brand hands scoring back
     * to the config file. Neither is data loss, both are surprising, so the
     * screen says it in the confirmation rather than the controller refusing.
     */
    public function destroy(Request $request, int|string $scoringRule)
    {
        $this->authorizeOrFail($request, 'manage leadhub scoring');
        $this->abortUnlessAvailable();

        ScoringRule::query()->findOrFail($scoringRule)->delete();

        return back()->with('success', __('leadhub::scoring.flashes.deleted'));
    }

    /**
     * Rules are eloquent-only (they are a table) and pointless with scoring
     * switched off, so the screen 404s in both cases like every other
     * feature-flagged module in this addon.
     */
    protected function abortUnlessAvailable(): void
    {
        abort_unless(config('leadhub.features.scoring', false), 404);
        abort_unless(config('leadhub.storage.driver', 'eloquent') === 'eloquent', 404);
    }

    /**
     * Suggestions for the event-type field: what the config file lists, plus
     * what this brand's timeline has actually recorded. Typing a type that
     * never occurs is the easiest way to build a rule that does nothing.
     *
     * @return list<string>
     */
    protected function knownEventTypes(): array
    {
        $fromConfig = array_keys((array) config('leadhub.scoring.events', []));

        $fromTimeline = Event::query()
            ->select('type')
            ->distinct()
            ->limit(50)
            ->pluck('type')
            ->all();

        return collect($fromConfig)
            ->merge($fromTimeline)
            ->unique()
            ->reject(fn ($type) => $type === null || $type === '')
            ->sort()
            ->values()
            ->all();
    }
}
