<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\Segment;
use Goldnead\Leadhub\Services\SegmentService;
use Goldnead\Leadhub\Support\SegmentEvaluator;
use Illuminate\Http\Request;
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

        $page = $this->segments->paginate(50, (int) $request->input('page', 1));

        $rows = collect($page->items())->map(fn (Segment $segment) => [
            'id' => (string) $segment->uuid,
            'name' => $segment->name,
            'handle' => $segment->handle,
            'description' => $segment->description,
            'is_active' => (bool) $segment->is_active,
            'members_count' => (int) ($segment->members_count ?? $this->segments->membersCount($segment)),
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

        $this->segments->update($model, $this->validated($request));

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

        $count = 0;
        $evaluator = app(SegmentEvaluator::class);
        $contacts = app(ContactRepository::class);

        $page = 1;
        do {
            $paginator = $contacts->paginate([], perPage: 200, page: $page);
            foreach ($paginator->items() as $contact) {
                if ($evaluator->matches($contact, (array) $draft->rules)) {
                    $count++;
                }
            }
            $page++;
        } while ($paginator->hasMorePages());

        return response()->json(['count' => $count]);
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
            'tag_operators' => ['has', 'has_not'],
            'event_operators' => ['has', 'has_not'],
            'statuses' => array_keys((array) config('leadhub.statuses', [])),
        ];
    }
}
