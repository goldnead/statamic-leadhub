<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Http\Requests\Concerns\ResolvesCrmReferences;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    use ResolvesCrmReferences;

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
            // No `integer|exists:leadhub_tags,id` here. A tag id is a database
            // id under the eloquent driver and a UUID under the flat-file one,
            // so `integer` is wrong half the time; and `exists` queries a
            // table that the flat-file driver never writes to. Resolution goes
            // through the repository instead — see ResolvesCrmReferences.
            'tag_ids' => 'sometimes|array',
            // See UpdateContactRequest: shape here, meaning in the service.
            'custom_fields' => 'sometimes|array',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (blank($this->input('email')) && blank($this->input('phone'))) {
                $validator->errors()->add('email', __('leadhub::contacts.validation.identifier_required'));
            }

            foreach ($this->unknownTagIds((array) $this->input('tag_ids', [])) as $id) {
                $validator->errors()->add('tag_ids', __('leadhub::contacts.validation.tag_not_found', ['id' => $id]));
            }
        });
    }
}
