<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.companies', false), 404);

        $page = Company::query()
            ->search($request->string('search')->toString() ?: null)
            ->withCount('contacts')
            ->orderBy('name')
            ->paginate(25, ['*'], 'page', (int) $request->input('page', 1));

        $rows = collect($page->items())->map(fn (Company $company) => [
            'id' => (string) $company->id,
            'name' => $company->name,
            'domain' => $company->domain,
            'industry' => $company->industry,
            'contacts_count' => (int) $company->contacts_count,
            'url' => cp_route('leadhub.companies.show', $company->id),
        ])->all();

        $columns = collect([
            Column::make('name')->label(__('leadhub::companies.singular'))->sortable(true),
            Column::make('domain')->label(__('Domain')),
            Column::make('industry')->label(__('Industry')),
            Column::make('contacts_count')->label(__('Contacts')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Companies/Index', [
            'companies' => $rows,
            'columns' => $columns,
            'filters' => array_filter(['search' => $request->string('search')->toString()], fn ($v) => $v !== null && $v !== ''),
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
