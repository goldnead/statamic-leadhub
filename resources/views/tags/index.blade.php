@extends('leadhub::layouts.leadhub')

@section('title', __('leadhub::tags.index_title'))

@section('leadhub-content')
    <header class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('leadhub::tags.index_title') }}</h1>
        <p class="text-gray-600 text-sm mt-1">{{ __('leadhub::tags.index_description') }}</p>
    </header>

    <div class="card p-4 mb-4">
        <form method="POST" action="{{ cp_route('leadhub.tags.store') }}" class="flex gap-2">
            @csrf
            <input type="text" name="name" required
                   placeholder="{{ __('leadhub::tags.placeholder_name') }}"
                   class="input flex-1">
            <input type="color" name="color" value="#e5e7eb" class="input w-20" title="Color">
            <button type="submit" class="btn-primary">{{ __('leadhub::tags.create_new') }}</button>
        </form>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="px-4 py-2">{{ __('leadhub::tags.name') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::tags.slug') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::tags.color') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::tags.contacts_count') }}</th>
                    <th class="px-4 py-2 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($tags as $tag)
                    <tr>
                        <td class="px-4 py-3">{{ $tag->name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $tag->slug }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-block w-6 h-6 rounded"
                                  style="background:{{ $tag->color ?? '#e5e7eb' }};"></span>
                        </td>
                        <td class="px-4 py-3">{{ $tag->contacts_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ cp_route('leadhub.tags.destroy', $tag) }}"
                                  onsubmit="return confirm('Delete tag?');" class="inline">
                                @csrf
                                @method('DELETE')
                                <button class="btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                            No tags yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        @if ($tags->hasPages())
            <div class="p-4 border-t">{{ $tags->links() }}</div>
        @endif
    </div>
@endsection
