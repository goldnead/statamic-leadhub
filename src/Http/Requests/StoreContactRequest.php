<?php

namespace Goldnead\Leadhub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create leadhub contacts') ?? false;
    }

    public function rules(): array
    {
        $statuses = array_keys((array) config('leadhub.statuses', []));

        return [
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'full_name' => 'nullable|string|max:255',
            // A contact needs at least one identifier. Email stays optional at
            // the field level so phone-only contacts are allowed, but the
            // withValidator hook below enforces "at least one of email/phone".
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'status' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', $statuses)],
            'assigned_to' => 'nullable|string',
            'consent' => 'sometimes|boolean',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer|exists:leadhub_tags,id',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (blank($this->input('email')) && blank($this->input('phone'))) {
                $validator->errors()->add('email', __('leadhub::contacts.validation.identifier_required'));
            }
        });
    }
}
