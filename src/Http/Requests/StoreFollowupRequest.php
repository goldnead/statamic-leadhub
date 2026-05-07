<?php

namespace Goldnead\Leadhub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFollowupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('edit leadhub contacts') ?? false;
    }

    public function rules(): array
    {
        return [
            'due_at' => 'required|date',
            'note' => 'sometimes|nullable|string|max:5000',
        ];
    }
}
