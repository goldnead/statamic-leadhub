@extends('leadhub::layouts.leadhub')

@section('title', __('leadhub::settings.title'))

@section('leadhub-content')
    <header class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('leadhub::settings.title') }}</h1>
        <p class="text-gray-600 text-sm mt-1">{{ __('leadhub::settings.description') }}</p>
    </header>

    <div class="card p-4 mb-4">
        <h3 class="font-semibold mb-2">Publish config</h3>
        <p class="text-sm text-gray-600 mb-2">
            To customize values, publish the config and edit it in your project:
        </p>
        <pre class="bg-gray-50 p-3 rounded text-xs overflow-x-auto">{{ __('leadhub::settings.publish_command') }}</pre>
    </div>

    <div class="card p-4 mb-4">
        <h3 class="font-semibold mb-2">{{ __('leadhub::settings.sections.statuses') }}</h3>
        <ul class="text-sm space-y-1">
            @foreach ($config['statuses'] as $key => $label)
                <li class="flex justify-between border-b py-1 last:border-0">
                    <span class="text-gray-500">{{ $key }}</span>
                    <span>{{ $label }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="card p-4 mb-4">
        <h3 class="font-semibold mb-2">{{ __('leadhub::settings.sections.behavior') }}</h3>
        <dl class="text-sm space-y-1">
            <div class="flex justify-between"><dt class="text-gray-500">overwrite_existing_fields_from_submissions</dt><dd>{{ $config['overwrite_existing_fields_from_submissions'] ? 'true' : 'false' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">store_full_submission_payload</dt><dd>{{ $config['store_full_submission_payload'] ? 'true' : 'false' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">default_status</dt><dd>{{ $config['default_status'] }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">{{ __('leadhub::settings.queue_threshold_label') }}</dt><dd>{{ $config['exports']['queue_threshold'] ?? 1000 }}</dd></div>
        </dl>
    </div>

    <div class="card p-4 mb-4">
        <h3 class="font-semibold mb-2">{{ __('leadhub::settings.sections.redaction') }}</h3>
        <ul class="text-sm flex flex-wrap gap-2">
            @foreach ((array) ($config['timeline_payload_redaction'] ?? []) as $key)
                <li class="px-2 py-1 rounded bg-gray-100">{{ $key }}</li>
            @endforeach
        </ul>
    </div>

    <div class="card p-4 mb-4">
        <h3 class="font-semibold mb-2">{{ __('leadhub::settings.sections.features') }}</h3>
        <dl class="text-sm space-y-1">
            @foreach ((array) ($config['features'] ?? []) as $key => $value)
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ $key }}</dt>
                    <dd>{{ $value ? '✓' : '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
@endsection
