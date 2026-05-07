@extends('leadhub::layouts.leadhub')

@section('title', $contact->displayName())

@section('leadhub-content')
    <header class="mb-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <a href="{{ cp_route('leadhub.contacts.index') }}" class="text-xs text-gray-600 hover:underline">
                    ← {{ __('leadhub::contacts.index.title') }}
                </a>
                <h1 class="text-2xl font-bold mt-1">{{ $contact->displayName() }}</h1>
                <div class="text-sm text-gray-700 mt-1">
                    @if ($contact->email)<span>{{ $contact->email }}</span>@endif
                    @if ($contact->phone) · <span>{{ $contact->phone }}</span>@endif
                    @if ($contact->company) · <span>{{ $contact->company }}</span>@endif
                </div>
            </div>

            <form method="POST" action="{{ cp_route('leadhub.contacts.update', $contact) }}"
                  class="inline-flex items-center gap-2">
                @csrf
                @method('PATCH')
                <select name="status" onchange="this.form.submit()" class="input">
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($contact->status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            {{-- Add note --}}
            <div class="card p-4">
                <h3 class="font-semibold mb-2">{{ __('leadhub::contacts.detail.add_note') }}</h3>
                <form method="POST" action="{{ cp_route('leadhub.contacts.notes.store', $contact) }}">
                    @csrf
                    <textarea name="body" rows="3" required
                              class="input w-full"
                              placeholder="{{ __('leadhub::contacts.detail.note_placeholder') }}"></textarea>
                    <div class="text-right mt-2">
                        <button type="submit" class="btn-primary">
                            {{ __('leadhub::contacts.actions.add_note') }}
                        </button>
                    </div>
                </form>
            </div>

            {{-- Timeline --}}
            <div class="card">
                <div class="px-4 py-3 border-b">
                    <h3 class="font-semibold">{{ __('leadhub::contacts.detail.timeline') }}</h3>
                </div>
                <div class="divide-y">
                    @forelse ($events as $event)
                        <div class="px-4 py-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-medium">{{ $event->summary }}</span>
                                <span class="text-xs text-gray-500">
                                    {{ $event->created_at?->diffForHumans() }}
                                </span>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                {{ $event->actor_type === 'user'
                                    ? __('leadhub::timeline.actor_user')
                                    : __('leadhub::timeline.actor_system') }}
                                · {{ $event->type }}
                            </div>
                            @if (! empty($event->payload))
                                <details class="mt-2">
                                    <summary class="text-xs text-gray-500 cursor-pointer">Payload</summary>
                                    <pre class="text-xs mt-1 bg-gray-50 p-2 rounded overflow-x-auto">{{ json_encode($event->payload, JSON_PRETTY_PRINT) }}</pre>
                                </details>
                            @endif
                        </div>
                    @empty
                        <div class="px-4 py-8 text-center text-gray-500 text-sm">
                            No timeline events yet.
                        </div>
                    @endforelse
                </div>

                @if ($events->hasPages())
                    <div class="p-4 border-t">{{ $events->links() }}</div>
                @endif
            </div>
        </div>

        {{-- Sidebar --}}
        <aside class="space-y-4">
            {{-- Active follow-up --}}
            <div class="card p-4">
                <h3 class="font-semibold mb-2">{{ __('leadhub::contacts.detail.active_followup') }}</h3>
                @if ($activeFollowup)
                    <div class="text-sm">
                        <div class="font-medium">
                            {{ $activeFollowup->due_at->format('Y-m-d H:i') }}
                            @if ($activeFollowup->isOverdue())
                                <span class="text-red-600 text-xs ml-2">overdue</span>
                            @endif
                        </div>
                        @if ($activeFollowup->note)
                            <div class="text-gray-700 mt-1">{{ $activeFollowup->note }}</div>
                        @endif
                        <div class="flex gap-2 mt-3">
                            <form method="POST" action="{{ cp_route('leadhub.followups.complete', $activeFollowup) }}" class="inline">
                                @csrf
                                <button class="btn">{{ __('leadhub::contacts.actions.complete_followup') }}</button>
                            </form>
                            <form method="POST" action="{{ cp_route('leadhub.followups.destroy', $activeFollowup) }}" class="inline"
                                  onsubmit="return confirm('Remove follow-up?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn">{{ __('leadhub::contacts.actions.remove_followup') }}</button>
                            </form>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-500 mb-3">{{ __('leadhub::contacts.detail.no_active_followup') }}</p>
                @endif

                <details class="mt-3">
                    <summary class="text-sm cursor-pointer">{{ __('leadhub::contacts.actions.set_followup') }}</summary>
                    <form method="POST" action="{{ cp_route('leadhub.contacts.followup.store', $contact) }}" class="mt-2 space-y-2">
                        @csrf
                        <input type="datetime-local" name="due_at" required class="input w-full">
                        <textarea name="note" rows="2" class="input w-full" placeholder="Optional note"></textarea>
                        <button class="btn-primary w-full">{{ __('leadhub::contacts.actions.set_followup') }}</button>
                    </form>
                </details>
            </div>

            {{-- Contact fields --}}
            <div class="card p-4 text-sm">
                <h3 class="font-semibold mb-3">{{ __('leadhub::contacts.detail.fields') }}</h3>
                <dl class="space-y-1">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('leadhub::contacts.email') }}</dt><dd>{{ $contact->email ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('leadhub::contacts.phone') }}</dt><dd>{{ $contact->phone ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('leadhub::contacts.company') }}</dt><dd>{{ $contact->company ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('leadhub::contacts.consent') }}</dt><dd>{{ $contact->consent ? '✓' : '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('leadhub::contacts.detail.source') }}</dt><dd>{{ $contact->source_form ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('leadhub::contacts.detail.created_at') }}</dt><dd>{{ $contact->created_at?->format('Y-m-d') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('leadhub::contacts.detail.last_activity_at') }}</dt><dd>{{ $contact->last_activity_at?->diffForHumans() }}</dd></div>
                </dl>
            </div>

            {{-- Tags --}}
            <div class="card p-4 text-sm">
                <h3 class="font-semibold mb-2">{{ __('leadhub::contacts.tags') }}</h3>
                <form method="POST" action="{{ cp_route('leadhub.contacts.update', $contact) }}" class="space-y-2">
                    @csrf
                    @method('PATCH')
                    @foreach ($allTags as $tag)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}"
                                   @checked($contact->tags->contains($tag))>
                            <span>{{ $tag->name }}</span>
                        </label>
                    @endforeach
                    <button class="btn-primary w-full mt-2">Save tags</button>
                </form>
            </div>

            {{-- Danger zone --}}
            <div class="card p-4 text-sm border-red-200">
                @if (! $contact->isArchived())
                    <form method="POST" action="{{ cp_route('leadhub.contacts.archive', $contact) }}"
                          onsubmit="return confirm('{{ __('leadhub::contacts.index.archive_confirm') }}');">
                        @csrf
                        <button class="btn w-full">{{ __('leadhub::contacts.actions.archive') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ cp_route('leadhub.contacts.restore', $contact) }}">
                        @csrf
                        <button class="btn w-full">{{ __('leadhub::contacts.actions.restore') }}</button>
                    </form>
                @endif

                <form method="POST" action="{{ cp_route('leadhub.contacts.destroy', $contact) }}"
                      class="mt-2"
                      onsubmit="return confirm('{{ __('leadhub::contacts.index.delete_confirm') }}');">
                    @csrf
                    @method('DELETE')
                    <button class="btn text-red-700 w-full">{{ __('leadhub::contacts.actions.delete') }}</button>
                </form>
            </div>
        </aside>
    </div>
@endsection
