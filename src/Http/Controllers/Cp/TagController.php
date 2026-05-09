<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function __construct(protected TagRepository $tags)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->can('manage leadhub tags'), 403);

        return view('leadhub::tags.index', [
            'tags' => $this->tags->paginate(50, (int) $request->input('page', 1)),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->can('manage leadhub tags'), 403);

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
        abort_unless($request->user()?->can('manage leadhub tags'), 403);

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
        abort_unless($request->user()?->can('manage leadhub tags'), 403);

        $tag = $this->tags->find($tagId);
        abort_unless($tag, 404);

        $this->tags->delete($tag);

        return back()->with('success', __('leadhub::tags.flashes.deleted'));
    }
}
