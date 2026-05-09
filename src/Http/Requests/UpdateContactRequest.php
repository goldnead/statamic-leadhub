<?php

namespace Goldnead\Leadhub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactRequest extends FormRequest
{
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
            'consent' => 'sometimes|boolean',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer|exists:leadhub_tags,id',
        ];
    }
}
