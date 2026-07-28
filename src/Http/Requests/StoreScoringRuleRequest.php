<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Models\ScoringRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreScoringRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage leadhub scoring');
    }

    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:\-*]+$/'],
            'points' => ['required', 'integer', 'between:-1000,1000'],
            'label' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Uniqueness is checked through the model, never through Laravel's `unique`
     * rule.
     *
     * `unique:leadhub_scoring_rules,event_type` compiles to a raw query-builder
     * statement — it never passes through the model, so the HasBrand global
     * scope does not apply and it would report a collision against another
     * brand's rule. That is the same trap `exists:` sets, documented in v1.7.0
     * and still true here; the consequence is different but no better: brand B
     * would be told a rule already exists that brand B cannot see.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('event_type');

            if ($type === '') {
                return;
            }

            if (ScoringRule::query()->where('event_type', $type)->exists()) {
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

    /** @return array<string,mixed> */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();
        $data['enabled'] = (bool) ($data['enabled'] ?? true);

        return $data;
    }
}
