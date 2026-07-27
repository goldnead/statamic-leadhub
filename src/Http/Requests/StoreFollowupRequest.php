<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Http\Requests\Concerns\NormalizesDatePickerValues;
use Illuminate\Foundation\Http\FormRequest;

class StoreFollowupRequest extends FormRequest
{
    use NormalizesDatePickerValues;

    public function authorize(): bool
    {
        return $this->user()?->can('edit leadhub contacts') ?? false;
    }

    /**
     * The CP date picker posts a DateValue object, not a string. Fold it into
     * a plain datetime string before the `date` rule ever sees it.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeDatePickerInput('due_at');
    }

    public function rules(): array
    {
        return [
            'due_at' => 'required|date',
            'note' => 'sometimes|nullable|string|max:5000',
        ];
    }
}
