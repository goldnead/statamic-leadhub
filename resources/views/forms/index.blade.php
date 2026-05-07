@extends('leadhub::layouts.leadhub')

@section('title', __('leadhub::forms.index_title'))

@section('leadhub-content')
    <header class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('leadhub::forms.index_title') }}</h1>
        <p class="text-gray-600 text-sm mt-1">{{ __('leadhub::forms.index_description') }}</p>
    </header>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="px-4 py-2">Form</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Email mapped</th>
                    <th class="px-4 py-2">{{ __('leadhub::forms.last_processed') }}</th>
                    <th class="px-4 py-2 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($forms as $form)
                    @php $mapping = $mappings->get($form['handle']); @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $form['title'] }}</div>
                            <div class="text-xs text-gray-500">{{ $form['handle'] }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($mapping?->enabled)
                                <span class="inline-block px-2 py-1 text-xs rounded bg-green-100 text-green-800">
                                    {{ __('leadhub::forms.enabled') }}
                                </span>
                            @else
                                <span class="inline-block px-2 py-1 text-xs rounded bg-gray-200 text-gray-600">
                                    {{ __('leadhub::forms.disabled') }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $mapping?->email_field ? '✓ '.$mapping->email_field : '—' }}
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            {{ $mapping?->last_processed_at?->diffForHumans() ?? __('leadhub::forms.never_processed') }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ cp_route('leadhub.forms.edit', $form['handle']) }}" class="btn">
                                {{ __('leadhub::forms.configure') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-gray-500">
                            No Statamic forms found. Create a form first.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
