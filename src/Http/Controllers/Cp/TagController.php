<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TagController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub tags'), 403);

        $tags = Tag::query()
            ->withCount('contacts')
            ->orderBy('name')
            ->paginate(50);

        return view('leadhub::tags.index', [
            'tags' => $tags,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub tags'), 403);

        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:50',
        ]);

        $tag = Tag::create([
            'name' => $request->string('name')->toString(),
            'slug' => Str::slug($request->string('name')->toString()),
            'color' => $request->input('color'),
        ]);

        return back()->with('success', __('leadhub::tags.flashes.created'));
    }

    public function update(Request $request, int $tagId)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub tags'), 403);

        $tag = Tag::query()->findOrFail($tagId);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'color' => 'sometimes|nullable|string|max:50',
        ]);

        if ($request->has('name')) {
            $tag->name = $request->string('name')->toString();
            $tag->slug = Str::slug($tag->name);
        }
        if ($request->has('color')) {
            $tag->color = $request->input('color');
        }
        $tag->save();

        return back()->with('success', __('leadhub::tags.flashes.updated'));
    }

    public function destroy(Request $request, int $tagId)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub tags'), 403);

        $tag = Tag::query()->findOrFail($tagId);
        $tag->delete();

        return back()->with('success', __('leadhub::tags.flashes.deleted'));
    }
}
