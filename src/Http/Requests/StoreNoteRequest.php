<?php

namespace Goldnead\Leadhub\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('edit leadhub contacts') ?? false;
    }

    public function rules(): array
    {
        return [
            'body' => 'required|string|max:10000',
        ];
    }
}
