<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Leadhub\Models\Segment;
use Goldnead\Leadhub\Services\CustomFieldService;
use Goldnead\Leadhub\Services\SegmentService;
use Goldnead\Leadhub\Support\SegmentEvaluator;
use Goldnead\Leadhub\Support\Setup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Statamic\CP\Column;

class SegmentController extends Controller
{
    public function __construct(
        protected SegmentRepository $segments,
        protected SegmentService $service,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub segments');

        // Beyond the segments and their membership pivot: `leadhub_custom_fields`,
        // because vocabulary() hands the rule builder the site's own fields on
        // every render — the table whose absence used to take the whole segment
        // area down, not just that one list.
        if ($setup = Setup::guard(
            __('leadhub::nav.segments'),
            'leadhub_segments',
            'leadhub_segment_contact',
            'leadhub_contacts',
            'leadhub_custom_fields',
        )) {
            return $setup;
        }

        $page = $this->segments->paginate(50, (int) $request->input('page', 1));

        $rows = collect($page->items())->map(fn (Segment $segment) => [
            'id' => (string) $segment->uuid,
            'name' => $segment->name,
            'handle' => $segment->handle,
            'description' => $segment->description,
            'is_active' => (bool) $segment->is_active,
            'members_count' => (int) ($segment->members_count ?? $this->segments->membersCount($segment)),
            'managed_by' => $segment->managedBy(),
            'edit_url' => cp_route('leadhub.segments.edit', $segment->uuid),
            'delete_url' => cp_route('leadhub.segments.destroy', $segment->uuid),
        ])->all();

        $columns = collect([
            Column::make('name')->label(__('leadhub::segments.name'))->sortable(true),
            Column::make('handle')->label(__('leadhub::segments.handle')),
            Column::make('members_count')->label(__('leadhub::segments.members_count')),
            Column::make('is_active')->label(__('leadhub::segments.active')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Segments/Index', [
            'segments' => $rows,
            'columns' => $columns,
            'createUrl' => cp_route('leadhub.segments.create'),
            'previewUrl' => cp_route('leadhub.segments.preview'),
            'canManage' => $this->userCan($request, 'manage leadhub segments'),
            'vocabulary' => $this->vocabulary(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub segments');

        return Inertia::render('leadhub::Segments/Edit', [
            'segment' => null,
            'storeUrl' => cp_route('leadhub.segments.store'),
            'previewUrl' => cp_route('leadhub.segments.preview'),
            'indexUrl' => cp_route('leadhub.segments.index'),
            'vocabulary' => $this->vocabulary(),
        ]);
    }

    public function edit(Request $request, int|string $segment)
    {
        $this->authorizeOrFail($request, 'manage leadhub segments');

        $model = $this->segments->find($segment);
        abort_unless($model, 404);

        return Inertia::render('leadhub::Segments/Edit', [
            'segment' => [
                'id' => (string) $model->uuid,
                'name' => $model->name,
                'handle' => $model->handle,
                'description' => $model->description,
                'is_active' => (bool) $model->is_active,
                'rules' => (array) $model->rules,
                'managed_by' => $model->managedBy(),
                'update_url' => cp_route('leadhub.segments.update', $model->uuid),
                'delete_url' => cp_route('leadhub.segments.destroy', $model->uuid),
            ],
            'previewUrl' => cp_route('leadhub.segments.preview'),
            'indexUrl' => cp_route('leadhub.segments.index'),
            'vocabulary' => $this->vocabulary(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub segments');

        $data = $this->validated($request);

        $this->segments->create($data);

        return redirect(cp_route('leadhub.segments.index'))
            ->with('success', __('leadhub::segments.flashes.created'));
    }

    public function update(Request $request, int|string $segment)
    {
        $this->authorizeOrFail($request, 'manage leadhub segments');

        $model = $this->segments->find($segment);
        abort_unless($model, 404);

        $attributes = $this->validated($request);

        // The owner's next sync writes name and rule back, so an edit here
        // would look saved and then quietly revert. The form shows them
        // read-only; this is the same rule for anyone posting around it.
        if ($model->isManaged()) {
            unset($attributes['name'], $attributes['handle'], $attributes['rules']);
        }

        $this->segments->update($model, $attributes);

        return back()->with('success', __('leadhub::segments.flashes.updated'));
    }

    public function destroy(Request $request, int|string $segment)
    {
        $this->authorizeOrFail($request, 'manage leadhub segments');

        $model = $this->segments->find($segment);
        abort_unless($model, 404);

        $this->segments->delete($model);

        return redirect(cp_route('leadhub.segments.index'))
            ->with('success', __('leadhub::segments.flashes.deleted'));
    }

    /**
     * Live member-count preview for the builder. Evaluates the submitted rules
     * against all contacts WITHOUT persisting anything — hence a GET.
     */
    public function preview(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub segments');

        $request->validate(['rules' => ['nullable', 'array']]);

        // Evaluate against a transient (unsaved) segment so nothing is written.
        $draft = new Segment(['rules' => (array) $request->input('rules', [])]);
        $draft->handle = '__preview__';

        $rules = (array) $draft->rules;
        $geo = $this->geoConditions($rules);

        $count = 0;
        $withoutPostalCode = 0;
        $evaluator = app(SegmentEvaluator::class);
        $contacts = app(ContactRepository::class);

        $page = 1;
        do {
            $paginator = $contacts->paginate([], perPage: 200, page: $page);
            foreach ($paginator->items() as $contact) {
                if ($evaluator->matches($contact, $rules)) {
                    $count++;
                }
                if ($geo !== [] && trim((string) $contact->getAttribute('postal_code')) === '') {
                    $withoutPostalCode++;
                }
            }
            $page++;
        } while ($paginator->hasMorePages());

        return response()->json([
            'count' => $count,
            // A geo condition matches nobody without a postal code, in either
            // direction (see SegmentEvaluator::evaluateGeo). Said beside the
            // count, so a small segment reads as missing data, not as a wrong
            // radius. Null when no condition asks where anybody lives.
            'without_postal_code' => $geo === [] ? null : $withoutPostalCode,
            // "DE:50667" => "Köln", or null for a centre the directory does not
            // know — which matches nobody and is the typo worth showing.
            'places' => $this->places($geo),
        ]);
    }

    /**
     * Every geo condition in a rule tree, nested groups included.
     *
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    protected function geoConditions(array $rules): array
    {
        $found = [];

        foreach ((array) ($rules['conditions'] ?? []) as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (isset($condition['conditions'])) {
                array_push($found, ...$this->geoConditions($condition));

                continue;
            }

            if (($condition['type'] ?? null) === 'geo') {
                $found[] = $condition;
            }
        }

        return $found;
    }

    /**
     * @param  array<int, array<string, mixed>>  $conditions
     * @return array<string, string|null>
     */
    protected function places(array $conditions): array
    {
        $places = [];
        $directory = $conditions !== [] && Schema::hasTable('leadhub_postal_codes');

        foreach ($conditions as $condition) {
            $code = PostalCode::normalise((string) ($condition['plz'] ?? $condition['postal_code'] ?? ''));
            $country = strtoupper(trim((string) ($condition['country'] ?? 'DE'))) ?: 'DE';

            if ($code === '' || array_key_exists($country.':'.$code, $places)) {
                continue;
            }

            $places[$country.':'.$code] = $directory ? PostalCode::lookup($code, $country)?->place : null;
        }

        return $places;
    }

    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'rules' => ['nullable', 'array'],
            'rules.match' => ['nullable', 'in:all,any'],
            'rules.conditions' => ['nullable', 'array'],
        ]);

        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);
        $validated['rules'] = $validated['rules'] ?? ['match' => 'all', 'conditions' => []];

        return $validated;
    }

    /** Field/operator vocabulary handed to the builder UI. */
    protected function vocabulary(): array
    {
        return [
            'fields' => SegmentEvaluator::FIELDS,
            'field_operators' => [
                'eq', 'neq', 'in', 'not_in', 'contains', 'starts_with',
                'gt', 'gte', 'lt', 'lte', 'is_set', 'is_empty',
                'is_true', 'is_false', 'before', 'after',
                'within_days', 'older_than_days',
            ],
            // The site's own fields, with the comparisons that make sense for
            // each type. Without this the builder offers `field`, `tag` and
            // `event` and nothing else — and a custom field that cannot be
            // segmented on is exactly the decoration the ticket warned about.
            'custom_fields' => app(CustomFieldService::class)->forRuleBuilder(),
            'tag_operators' => ['has', 'has_not'],
            'event_operators' => ['has', 'has_not'],
            // „Postleitzahl im Umkreis von 30 km um 50667 (DE)". The countries
            // are the ones the postal-code directory imports; a centre outside
            // them could never resolve.
            'geo_operators' => ['within_km', 'outside_km'],
            'countries' => PostalCode::countries(),
            'statuses' => array_keys((array) config('leadhub.statuses', [])),
        ];
    }
}
