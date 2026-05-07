@extends('leadhub::layouts.leadhub')

@section('title', __('leadhub::dashboard.title'))

@section('leadhub-content')
    <header class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('leadhub::dashboard.title') }}</h1>
        <p class="text-gray text-sm mt-1">{{ __('leadhub::dashboard.subtitle') }}</p>
    </header>

    @if (! $hasFormConnected)
        <div class="card p-6 text-center">
            <h2 class="text-lg font-semibold mb-2">{{ __('leadhub::dashboard.empty.title') }}</h2>
            <p class="text-gray-700 mb-4">{{ __('leadhub::dashboard.empty.description') }}</p>
            <a href="{{ cp_route('leadhub.forms.index') }}" class="btn-primary">
                {{ __('leadhub::dashboard.empty.cta') }}
            </a>
        </div>
    @else
        {{-- KPI Cards --}}
        <div class="grid gap-4 md:grid-cols-4 mb-6">
            <a href="{{ cp_route('leadhub.contacts.index', ['from' => now()->subDays(7)->toDateString()]) }}"
               class="card p-4 hover:shadow transition">
                <div class="text-xs uppercase tracking-wide text-gray-600">
                    {{ __('leadhub::dashboard.kpi.new_leads_week') }}
                </div>
                <div class="text-3xl font-bold mt-1">{{ $kpis['new_leads_week'] }}</div>
            </a>

            <a href="{{ cp_route('leadhub.contacts.index', ['status' => 'qualified']) }}"
               class="card p-4 hover:shadow transition">
                <div class="text-xs uppercase tracking-wide text-gray-600">
                    {{ __('leadhub::dashboard.kpi.qualified') }}
                </div>
                <div class="text-3xl font-bold mt-1">{{ $kpis['qualified'] }}</div>
            </a>

            <a href="{{ cp_route('leadhub.contacts.index', ['status' => 'won']) }}"
               class="card p-4 hover:shadow transition">
                <div class="text-xs uppercase tracking-wide text-gray-600">
                    {{ __('leadhub::dashboard.kpi.won') }}
                </div>
                <div class="text-3xl font-bold mt-1">{{ $kpis['won'] }}</div>
            </a>

            <a href="{{ cp_route('leadhub.followups.index') }}"
               class="card p-4 hover:shadow transition">
                <div class="text-xs uppercase tracking-wide text-gray-600">
                    {{ __('leadhub::dashboard.kpi.due_followups') }}
                </div>
                <div class="text-3xl font-bold mt-1">
                    {{ $kpis['due_followups'] }}
                    @if ($kpis['overdue_followups'] > 0)
                        <span class="text-sm font-normal text-red-600 ml-2">
                            +{{ $kpis['overdue_followups'] }} overdue
                        </span>
                    @endif
                </div>
            </a>
        </div>

        {{-- Two-column layout --}}
        <div class="grid gap-6 md:grid-cols-2">
            {{-- Latest activity --}}
            <div class="card">
                <div class="px-4 py-3 border-b">
                    <h3 class="font-semibold">{{ __('leadhub::dashboard.sections.latest_activity') }}</h3>
                </div>
                <div class="divide-y">
                    @forelse ($latestActivity as $event)
                        <div class="px-4 py-3 text-sm">
                            <div class="flex items-center justify-between">
                                <a href="{{ cp_route('leadhub.contacts.show', $event->contact_id) }}"
                                   class="font-medium hover:underline">
                                    {{ $event->contact?->displayName() ?? '—' }}
                                </a>
                                <span class="text-xs text-gray-500">
                                    {{ $event->created_at?->diffForHumans() }}
                                </span>
                            </div>
                            <div class="text-gray-700">{{ $event->summary }}</div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-sm text-gray-500 text-center">
                            No activity yet.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Follow-ups --}}
            <div class="card">
                <div class="px-4 py-3 border-b">
                    <h3 class="font-semibold">{{ __('leadhub::dashboard.sections.followups_today') }}</h3>
                </div>
                <div class="divide-y">
                    @forelse ($followupsToday as $f)
                        <div class="px-4 py-3 text-sm">
                            <div class="flex items-center justify-between">
                                <a href="{{ cp_route('leadhub.contacts.show', $f->contact_id) }}"
                                   class="font-medium hover:underline">
                                    {{ $f->contact?->displayName() }}
                                </a>
                                <span class="text-xs text-gray-500">
                                    {{ $f->due_at?->format('H:i') }}
                                </span>
                            </div>
                            @if ($f->note)
                                <div class="text-gray-700">{{ $f->note }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="px-4 py-6 text-sm text-gray-500 text-center">
                            No follow-ups due today.
                        </div>
                    @endforelse
                </div>

                @if ($followupsOverdue->count() > 0)
                    <div class="px-4 py-3 border-t border-b bg-red-50">
                        <h3 class="font-semibold text-red-700">
                            {{ __('leadhub::dashboard.sections.followups_overdue') }}
                        </h3>
                    </div>
                    <div class="divide-y">
                        @foreach ($followupsOverdue as $f)
                            <div class="px-4 py-3 text-sm">
                                <div class="flex items-center justify-between">
                                    <a href="{{ cp_route('leadhub.contacts.show', $f->contact_id) }}"
                                       class="font-medium hover:underline">
                                        {{ $f->contact?->displayName() }}
                                    </a>
                                    <span class="text-xs text-red-600">
                                        {{ $f->due_at?->diffForHumans() }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Leads by status --}}
        <div class="card mt-6">
            <div class="px-4 py-3 border-b">
                <h3 class="font-semibold">{{ __('leadhub::dashboard.sections.leads_by_status') }}</h3>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-6 gap-2 p-4">
                @foreach ($statuses as $key => $label)
                    <a href="{{ cp_route('leadhub.contacts.index', ['status' => $key]) }}"
                       class="rounded border px-3 py-2 hover:bg-gray-50 transition">
                        <div class="text-xs uppercase text-gray-600">{{ $label }}</div>
                        <div class="text-xl font-semibold">{{ $leadsByStatus[$key] ?? 0 }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endsection
