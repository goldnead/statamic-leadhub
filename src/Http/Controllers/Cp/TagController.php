<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

class TagController extends Controller
{
    public function __construct(protected TagRepository $tags)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub tags');

        $page = $this->tags->paginate(50, (int) $request->input('page', 1));

        $rows = collect($page->items())->map(fn ($tag) => [
            'id' => (string) $tag->uuid,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'color' => $tag->color,
            'contacts_count' => (int) ($tag->contacts_count ?? 0),
            'delete_url' => cp_route('leadhub.tags.destroy', $tag->uuid),
        ])->all();

        $columns = collect([
            Column::make('name')->label(__('leadhub::tags.name'))->sortable(true),
            Column::make('slug')->label(__('leadhub::tags.slug')),
            Column::make('color')->label(__('leadhub::tags.color')),
            Column::make('contacts_count')->label(__('leadhub::tags.contacts_count')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Tags/Index', [
            'tags' => $rows,
            'columns' => $columns,
            'storeUrl' => cp_route('leadhub.tags.store'),
            'canManage' => $request->user()?->hasPermission('manage leadhub tags') ?? false,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub tags');

        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:50',
        ]);

        $this->tags->create([
            'name' => $request->string('name')->toString(),
            'color' => $request->input('color'),
        ]);

        return back()->with('success', __('leadhub::tags.flashes.created'));
    }

    public function update(Request $request, int|string $tagId)
    {
        $this->authorizeOrFail($request, 'manage leadhub tags');

        $tag = $this->tags->find($tagId);
        abort_unless($tag, 404);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'color' => 'sometimes|nullable|string|max:50',
        ]);

        $this->tags->update($tag, $request->only(['name', 'color']));

        return back()->with('success', __('leadhub::tags.flashes.updated'));
    }

    public function destroy(Request $request, int|string $tagId)
    {
        $this->authorizeOrFail($request, 'manage leadhub tags');

        $tag = $this->tags->find($tagId);
        abort_unless($tag, 404);

        $this->tags->delete($tag);

        return back()->with('success', __('leadhub::tags.flashes.deleted'));
    }
}
