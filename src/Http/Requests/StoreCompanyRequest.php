<?php

namespace Goldnead\Leadhub\Http\Requests;

use Goldnead\Leadhub\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage leadhub companies') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'website' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:255',
            'employee_range' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'status' => 'sometimes|nullable|string|max:50',
            'owner_id' => 'nullable|string|max:255',
        ];
    }

    /**
     * Companies are deduplicated by normalized name and by derived domain
     * everywhere else in the addon (Services\CompanyResolver). A form that
     * silently created a second "Muster GmbH" would undo that, so the same two
     * keys are checked here — brand-scoped, because both queries go through
     * the model.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $name = Company::normalizeName((string) $this->input('name'));

            if ($name && $this->duplicateQuery()->where('name_normalized', $name)->exists()) {
                $validator->errors()->add('name', __('leadhub::companies.validation.duplicate_name'));
            }

            $domain = Company::deriveDomain($this->input('website'));

            if ($domain && $this->duplicateQuery()->where('domain', $domain)->exists()) {
                $validator->errors()->add(
                    'website',
                    __('leadhub::companies.validation.duplicate_domain', ['domain' => $domain])
                );
            }
        });
    }

    /** The update request narrows this to "everything except myself". */
    protected function duplicateQuery()
    {
        return Company::query();
    }
}
