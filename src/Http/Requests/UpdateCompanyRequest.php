<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Models\Company;

class UpdateCompanyRequest extends StoreCompanyRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'website' => 'sometimes|nullable|string|max:255',
            'industry' => 'sometimes|nullable|string|max:255',
            'employee_range' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'status' => 'sometimes|nullable|string|max:50',
            'owner_id' => 'sometimes|nullable|string|max:255',
        ];
    }

    /** The record being edited is not its own duplicate. */
    protected function duplicateQuery()
    {
        return Company::query()->whereKeyNot($this->route('company'));
    }
}
