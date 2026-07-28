<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Http\Requests\Concerns\ResolvesCrmReferences;
use Goldnead\Leadhub\Models\Opportunity;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOpportunityRequest extends FormRequest
{
    use ResolvesCrmReferences;

    public function authorize(): bool
    {
        return $this->user()?->can('manage leadhub opportunities') ?? false;
    }

    /**
     * The pipeline is not editable here. Moving a deal to another pipeline
     * would mean re-deriving its stage, its status and its terminal outcome —
     * that is a transition, not a field edit, and it has no defined behaviour
     * yet. The stage may change and is routed through StageTransitionService
     * by the controller, so status and outcome stay consistent.
     */
    public function rules(): array
    {
        return [
            'stage_id' => 'sometimes|nullable',
            'company_id' => 'sometimes|nullable',
            'title' => 'sometimes|nullable|string|max:255',
            'value_estimate' => 'sometimes|nullable|numeric|min:0|max:99999999.99',
            'confidence' => 'sometimes|nullable|integer|between:0,100',
            'owner_id' => 'sometimes|nullable|string|max:255',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // Brand-scoped: an opportunity of another brand is simply not
            // found, and the stage check below then fails closed.
            $opportunity = Opportunity::query()->whereKey($this->route('opportunity'))->first();

            if ($opportunity && filled($this->input('stage_id'))
                && ! $this->stageBelongsToPipeline($this->input('stage_id'), $opportunity->pipeline_id)) {
                $validator->errors()->add('stage_id', __('leadhub::pipelines.validation.stage_not_in_pipeline'));
            }

            $companyId = $this->input('company_id');

            if (filled($companyId) && ! $this->companyExists($companyId)) {
                $validator->errors()->add('company_id', __('leadhub::pipelines.validation.company_not_found'));
            }
        });
    }
}
