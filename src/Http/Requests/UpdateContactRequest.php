<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Http\Requests\Concerns\ResolvesCrmReferences;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContactRequest extends FormRequest
{
    use ResolvesCrmReferences;

    public function authorize(): bool
    {
        return $this->user()?->can('edit leadhub contacts') ?? false;
    }

    public function rules(): array
    {
        $statuses = array_keys((array) config('leadhub.statuses', []));

        return [
            'first_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:255',
            'full_name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'phone' => 'sometimes|nullable|string|max:255',
            'company' => 'sometimes|nullable|string|max:255',
            'status' => ['sometimes', 'string', 'in:'.implode(',', $statuses)],
            'assigned_to' => 'sometimes|nullable|string',
            'consent' => 'sometimes|boolean',
            // See StoreContactRequest: `integer|exists:leadhub_tags,id` is a
            // database assumption in a driver-agnostic request. It made every
            // tag change on a flat-file install fail validation, which looked
            // exactly like a successful save because both redirect back.
            'tag_ids' => 'sometimes|array',
            // Shape only. Which handles exist and what each value has to be is
            // the definition's business, and CustomFieldService::apply()
            // enforces it — an unknown handle is dropped, a value that does not
            // fit its type is refused. Repeating those rules here would put the
            // definition in two places and let them drift.
            'custom_fields' => 'sometimes|array',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ($this->unknownTagIds((array) $this->input('tag_ids', [])) as $id) {
                $validator->errors()->add('tag_ids', __('leadhub::contacts.validation.tag_not_found', ['id' => $id]));
            }
        });
    }
}
