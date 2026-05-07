@extends('leadhub::layouts.leadhub')

@section('title', __('leadhub::contacts.index.title'))

@section('leadhub-content')
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ __('leadhub::contacts.index.title') }}</h1>

        <form method="POST" action="{{ cp_route('leadhub.export') }}" class="inline">
            @csrf
            @foreach ($filters as $k => $v)
                @if ($v !== null && $v !== '')
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endif
            @endforeach
            <button type="submit" class="btn">
                {{ __('leadhub::contacts.index.export_csv') }}
            </button>
        </form>
    </header>

    {{-- Filters --}}
    <form method="GET" class="card p-4 mb-4">
        <div class="grid gap-3 md:grid-cols-5">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="{{ __('leadhub::contacts.index.search_placeholder') }}"
                   class="input md:col-span-2">

            <select name="status" class="input">
                <option value="">{{ __('leadhub::contacts.index.filter_status') }}</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>

            <select name="source" class="input">
                <option value="">{{ __('leadhub::contacts.index.filter_source') }}</option>
                @foreach ($sources as $source)
                    <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>
                        {{ $source }}
                    </option>
                @endforeach
            </select>

            <select name="tag" class="input">
                <option value="">{{ __('leadhub::contacts.index.filter_tag') }}</option>
                @foreach ($tags as $tag)
                    <option value="{{ $tag->id }}" @selected((string) ($filters['tag'] ?? '') === (string) $tag->id)>
                        {{ $tag->name }}
                    </option>
                @endforeach
            </select>

            <select name="followup" class="input">
                <option value="">{{ __('leadhub::contacts.index.filter_followup') }}</option>
                <option value="has" @selected(($filters['followup'] ?? '') === 'has')>
                    {{ __('leadhub::contacts.index.filter_followup_has') }}
                </option>
                <option value="today" @selected(($filters['followup'] ?? '') === 'today')>
                    {{ __('leadhub::contacts.index.filter_followup_today') }}
                </option>
                <option value="overdue" @selected(($filters['followup'] ?? '') === 'overdue')>
                    {{ __('leadhub::contacts.index.filter_followup_overdue') }}
                </option>
            </select>

            <label class="inline-flex items-center text-sm gap-2">
                <input type="checkbox" name="archived" value="1" @checked($filters['archived'] ?? false)>
                {{ __('leadhub::contacts.index.show_archived') }}
            </label>

            <div class="md:col-span-4 text-right">
                <button type="submit" class="btn-primary">Filter</button>
                <a href="{{ cp_route('leadhub.contacts.index') }}" class="btn">Reset</a>
            </div>
        </div>
    </form>

    {{-- Table --}}
    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="px-4 py-2">{{ __('leadhub::contacts.name') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::contacts.email') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::contacts.company') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::contacts.status') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::contacts.tags') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::contacts.source') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::contacts.last_activity') }}</th>
                    <th class="px-4 py-2">{{ __('leadhub::contacts.followup') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($contacts as $contact)
                    @php $active = $contact->followups->whereNull('completed_at')->sortBy('due_at')->first(); @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <a href="{{ cp_route('leadhub.contacts.show', $contact) }}"
                               class="font-medium hover:underline">
                                {{ $contact->displayName() }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ $contact->email }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $contact->company }}</td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-2 py-1 rounded text-xs bg-gray-200 text-gray-800">
                                {{ $statuses[$contact->status] ?? $contact->status }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            @foreach ($contact->tags as $t)
                                <span class="inline-block text-xs rounded px-2 py-0.5 mr-1"
                                      style="background:{{ $t->color ?? '#e5e7eb' }};color:#111;">
                                    {{ $t->name }}
                                </span>
                            @endforeach
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ $contact->source_form }}</td>
                        <td class="px-4 py-2 text-gray-600">
                            {{ $contact->last_activity_at?->diffForHumans() }}
                        </td>
                        <td class="px-4 py-2">
                            @if ($active)
                                @if ($active->isOverdue())
                                    <span class="text-red-600 text-xs">
                                        {{ $active->due_at->format('Y-m-d') }} (overdue)
                                    </span>
                                @else
                                    <span class="text-gray-700 text-xs">
                                        {{ $active->due_at->format('Y-m-d') }}
                                    </span>
                                @endif
                            @else
                                <span class="text-gray-400 text-xs">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-gray-500">
                            {{ __('leadhub::contacts.index.no_contacts') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($contacts->hasPages())
            <div class="p-4 border-t">
                {{ $contacts->links() }}
            </div>
        @endif
    </div>
@endsection
