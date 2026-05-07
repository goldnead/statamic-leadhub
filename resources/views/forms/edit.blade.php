@extends('leadhub::layouts.leadhub')

@section('title', $formTitle)

@section('leadhub-content')
    <header class="mb-6">
        <a href="{{ cp_route('leadhub.forms.index') }}" class="text-xs text-gray-600 hover:underline">
            ← {{ __('leadhub::forms.index_title') }}
        </a>
        <h1 class="text-2xl font-bold mt-1">{{ $formTitle }}</h1>
        <div class="text-sm text-gray-600 mt-1">
            {{ $formHandle }}
        </div>
    </header>

    <form method="POST" action="{{ cp_route('leadhub.forms.update', $formHandle) }}" class="card p-6 space-y-6">
        @csrf
        @method('PATCH')

        {{-- Enable toggle --}}
        <div>
            <label class="inline-flex items-center gap-2 font-semibold">
                <input type="checkbox" name="enabled" value="1" @checked($mapping->enabled)>
                {{ __('leadhub::forms.mapping.enable') }}
            </label>
        </div>

        @if (empty($fields))
            <div class="px-3 py-2 rounded bg-yellow-100 text-yellow-800 text-sm">
                We could not auto-discover this form's fields. You can still type field handles manually below.
            </div>
        @endif

        {{-- Field mappings --}}
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ([
                'email_field' => __('leadhub::forms.mapping.email_field'),
                'first_name_field' => __('leadhub::forms.mapping.first_name_field'),
                'last_name_field' => __('leadhub::forms.mapping.last_name_field'),
                'full_name_field' => __('leadhub::forms.mapping.full_name_field'),
                'phone_field' => __('leadhub::forms.mapping.phone_field'),
                'company_field' => __('leadhub::forms.mapping.company_field'),
                'message_field' => __('leadhub::forms.mapping.message_field'),
                'consent_field' => __('leadhub::forms.mapping.consent_field'),
                'tags_field' => __('leadhub::forms.mapping.tags_field'),
            ] as $name => $label)
                <div>
                    <label class="block text-sm font-medium mb-1">{{ $label }}</label>
                    @if (! empty($fields))
                        <select name="{{ $name }}" class="input w-full">
                            <option value="">— none —</option>
                            @foreach ($fields as $handle => $display)
                                <option value="{{ $handle }}" @selected($mapping->{$name} === $handle)>
                                    {{ $display }} ({{ $handle }})
                                </option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" name="{{ $name }}" value="{{ $mapping->{$name} }}" class="input w-full">
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Defaults --}}
        <div class="grid gap-4 md:grid-cols-2 pt-4 border-t">
            <div>
                <label class="block text-sm font-medium mb-1">
                    {{ __('leadhub::forms.mapping.default_status') }}
                </label>
                <select name="default_status" class="input w-full">
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($mapping->default_status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">
                    {{ __('leadhub::forms.mapping.default_source') }}
                </label>
                <input type="text" name="default_source"
                       value="{{ $mapping->default_source ?? $formHandle }}" class="input w-full">
            </div>

            <div class="md:col-span-2">
                <label class="block text-sm font-medium mb-1">
                    {{ __('leadhub::forms.mapping.default_tags') }}
                </label>
                <input type="text" name="default_tags_csv"
                       value="{{ implode(',', (array) ($mapping->default_tags ?? [])) }}"
                       class="input w-full"
                       placeholder="lead, website">
                <p class="text-xs text-gray-500 mt-1">Comma-separated.</p>
            </div>

            <div class="md:col-span-2">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="attach_full_submission" value="1"
                           @checked($mapping->attach_full_submission)>
                    {{ __('leadhub::forms.mapping.attach_full_submission') }}
                </label>
            </div>
        </div>

        <div class="text-right pt-4 border-t">
            <button type="submit" class="btn-primary">{{ __('leadhub::forms.mapping.save') }}</button>
        </div>
    </form>

    <script>
        // Convert the CSV input back into an array on submit so it matches the validator.
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('form[action$="/{{ $formHandle }}"]');
            if (!form) return;
            form.addEventListener('submit', () => {
                const csv = form.querySelector('input[name="default_tags_csv"]');
                if (!csv) return;
                const tags = csv.value.split(',').map(s => s.trim()).filter(Boolean);
                tags.forEach((t, i) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `default_tags[${i}]`;
                    input.value = t;
                    form.appendChild(input);
                });
                csv.disabled = true;
            });
        });
    </script>
@endsection
