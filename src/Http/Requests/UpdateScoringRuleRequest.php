<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Models\ScoringRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateScoringRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage leadhub scoring');
    }

    public function rules(): array
    {
        return [
            'event_type' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:\-*]+$/'],
            'points' => ['sometimes', 'required', 'integer', 'between:-1000,1000'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Same reasoning as StoreScoringRuleRequest: the collision check runs
     * through the model so the brand scope applies, and it ignores the row
     * being edited.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('event_type')) {
                return;
            }

            $type = (string) $this->input('event_type');
            $current = $this->route('rule');

            $exists = ScoringRule::query()
                ->where('event_type', $type)
                ->when($current !== null, fn ($query) => $query->where('id', '!=', $current))
                ->exists();

            if ($exists) {
                $validator->errors()->add('event_type', __('leadhub::scoring.errors.duplicate'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'event_type.regex' => __('leadhub::scoring.errors.event_type_format'),
        ];
    }

    public function attributes(): array
    {
        return [
            'event_type' => __('leadhub::scoring.event_type'),
            'points' => __('leadhub::scoring.points'),
            'label' => __('leadhub::scoring.label'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('event_type'))) {
            $this->merge(['event_type' => trim($this->input('event_type'))]);
        }
    }
}
