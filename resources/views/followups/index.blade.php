@extends('leadhub::layouts.leadhub')

@section('title', __('leadhub::followups.index_title'))

@section('leadhub-content')
    <header class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('leadhub::followups.index_title') }}</h1>
    </header>

    <div class="space-y-6">
        {{-- Overdue --}}
        <div class="card">
            <div class="px-4 py-3 border-b bg-red-50">
                <h3 class="font-semibold text-red-700">
                    {{ __('leadhub::followups.sections.overdue') }} ({{ $overdue->count() }})
                </h3>
            </div>
            <div class="divide-y">
                @forelse ($overdue as $f)
                    @include('leadhub::followups.partials._row', ['f' => $f])
                @empty
                    <div class="px-4 py-6 text-center text-gray-500 text-sm">
                        {{ __('leadhub::followups.no_followups') }}
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Today --}}
        <div class="card">
            <div class="px-4 py-3 border-b">
                <h3 class="font-semibold">
                    {{ __('leadhub::followups.sections.today') }} ({{ $today->count() }})
                </h3>
            </div>
            <div class="divide-y">
                @forelse ($today as $f)
                    @include('leadhub::followups.partials._row', ['f' => $f])
                @empty
                    <div class="px-4 py-6 text-center text-gray-500 text-sm">
                        {{ __('leadhub::followups.no_followups') }}
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Upcoming --}}
        <div class="card">
            <div class="px-4 py-3 border-b">
                <h3 class="font-semibold">
                    {{ __('leadhub::followups.sections.upcoming') }} ({{ $upcoming->count() }})
                </h3>
            </div>
            <div class="divide-y">
                @forelse ($upcoming as $f)
                    @include('leadhub::followups.partials._row', ['f' => $f])
                @empty
                    <div class="px-4 py-6 text-center text-gray-500 text-sm">
                        {{ __('leadhub::followups.no_followups') }}
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
