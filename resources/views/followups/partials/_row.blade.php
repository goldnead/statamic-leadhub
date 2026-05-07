@php /** @var \Goldnead\Leadhub\Models\Followup $f */ @endphp
<div class="px-4 py-3 flex items-center justify-between gap-4">
    <div class="min-w-0">
        <a href="{{ cp_route('leadhub.contacts.show', $f->contact_id) }}"
           class="font-medium hover:underline">
            {{ $f->contact?->displayName() ?? '—' }}
        </a>
        <div class="text-xs text-gray-500 mt-0.5">
            {{ __('leadhub::followups.fields.due_at') }}: {{ $f->due_at->format('Y-m-d H:i') }}
            @if ($f->isOverdue())
                <span class="text-red-600 ml-1">(overdue)</span>
            @endif
        </div>
        @if ($f->note)
            <div class="text-sm text-gray-700 mt-1">{{ $f->note }}</div>
        @endif
    </div>
    <div class="flex gap-1">
        <form method="POST" action="{{ cp_route('leadhub.followups.complete', $f) }}" class="inline">
            @csrf
            <button class="btn btn-sm">{{ __('leadhub::contacts.actions.complete_followup') }}</button>
        </form>
    </div>
</div>
