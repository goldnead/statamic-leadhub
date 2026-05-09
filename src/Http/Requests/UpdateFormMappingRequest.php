<?php

namespace Goldnead\Leadhub\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFormMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage leadhub form mappings') ?? false;
    }

    public function rules(): array
    {
        $statuses = array_keys((array) config('leadhub.statuses', []));

        return [
            'enabled' => 'required|boolean',
            'email_field' => 'nullable|string|max:255',
            'first_name_field' => 'nullable|string|max:255',
            'last_name_field' => 'nullable|string|max:255',
            'full_name_field' => 'nullable|string|max:255',
            'phone_field' => 'nullable|string|max:255',
            'company_field' => 'nullable|string|max:255',
            'message_field' => 'nullable|string|max:255',
            'consent_field' => 'nullable|string|max:255',
            'tags_field' => 'nullable|string|max:255',
            'default_status' => ['nullable', 'string', 'in:'.implode(',', $statuses)],
            'default_source' => 'nullable|string|max:255',
            'default_tags' => 'nullable|array',
            'default_tags.*' => 'string|max:255',
            'attach_full_submission' => 'sometimes|boolean',
        ];
    }

    /**
     * Custom validation: when enabled, an email_field must be set.
     * (PRD §19.2)
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->boolean('enabled') && empty($this->input('email_field'))) {
                $v->errors()->add(
                    'email_field',
                    __('leadhub::forms.errors.email_required_when_enabled')
                );
            }
        });
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'attach_full_submission' => $this->boolean('attach_full_submission'),
        ]);
    }
}
