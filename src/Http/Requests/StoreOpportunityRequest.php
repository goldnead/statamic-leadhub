<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Http\Requests\Concerns\ResolvesCrmReferences;
use Illuminate\Foundation\Http\FormRequest;

class StoreOpportunityRequest extends FormRequest
{
    use ResolvesCrmReferences;

    public function authorize(): bool
    {
        return $this->user()?->can('manage leadhub opportunities') ?? false;
    }

    public function rules(): array
    {
        return [
            'contact_id' => 'required',
            'pipeline_id' => 'required',
            'stage_id' => 'nullable',
            'company_id' => 'nullable',
            'title' => 'nullable|string|max:255',
            'value_estimate' => 'nullable|numeric|min:0|max:99999999.99',
            'confidence' => 'nullable|integer|between:0,100',
            'owner_id' => 'nullable|string|max:255',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->contactExists($this->input('contact_id'))) {
                $validator->errors()->add('contact_id', __('leadhub::pipelines.validation.contact_not_found'));
            }

            $pipelineId = $this->input('pipeline_id');

            if (! $this->pipelineExists($pipelineId)) {
                $validator->errors()->add('pipeline_id', __('leadhub::pipelines.validation.pipeline_not_found'));
            } elseif (filled($this->input('stage_id')) && ! $this->stageBelongsToPipeline($this->input('stage_id'), $pipelineId)) {
                // An empty stage means "the pipeline's default stage", which is
                // what OpportunityService falls back to. A *populated* stage
                // that belongs somewhere else is refused rather than ignored.
                $validator->errors()->add('stage_id', __('leadhub::pipelines.validation.stage_not_in_pipeline'));
            }

            $companyId = $this->input('company_id');

            if (filled($companyId) && ! $this->companyExists($companyId)) {
                $validator->errors()->add('company_id', __('leadhub::pipelines.validation.company_not_found'));
            }
        });
    }
}
