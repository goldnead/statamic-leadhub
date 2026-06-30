<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.companies', false), 404);

        $companies = Company::query()
            ->search($request->string('search')->toString() ?: null)
            ->withCount('contacts')
            ->orderBy('name')
            ->paginate(25)
            ->through(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'domain' => $company->domain,
                'industry' => $company->industry,
                'contacts_count' => $company->contacts_count,
                'url' => cp_route('leadhub.companies.show', $company->id),
            ]);

        return Inertia::render('leadhub::Companies/Index', [
            'companies' => $companies,
            'columns' => [
                ['label' => __('leadhub::companies.singular'), 'field' => 'name'],
                ['label' => __('Domain'), 'field' => 'domain'],
                ['label' => __('Industry'), 'field' => 'industry'],
                ['label' => __('Contacts'), 'field' => 'contacts_count'],
            ],
            'filters' => ['search' => $request->string('search')->toString()],
        ]);
    }

    public function show(Request $request, int|string $company)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.companies', false), 404);

        $model = Company::query()->with(['contacts'])->findOrFail($company);

        return Inertia::render('leadhub::Companies/Show', [
            'company' => [
                'id' => $model->id,
                'name' => $model->name,
                'website' => $model->website,
                'domain' => $model->domain,
                'industry' => $model->industry,
                'employee_range' => $model->employee_range,
                'description' => $model->description,
                'status' => $model->status,
            ],
            'contacts' => $model->contacts->map(fn ($contact) => [
                'id' => $contact->id,
                'name' => $contact->displayName(),
                'email' => $contact->email,
                'relationship_label' => $contact->pivot->relationship_label,
                'is_primary' => (bool) $contact->pivot->is_primary,
                'url' => cp_route('leadhub.contacts.show', $contact->id),
            ])->all(),
        ]);
    }
}
