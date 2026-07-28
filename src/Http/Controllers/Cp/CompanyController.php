<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Events\LeadHubCompanyCreated;
use Goldnead\Leadhub\Http\Requests\StoreCompanyRequest;
use Goldnead\Leadhub\Http\Requests\UpdateCompanyRequest;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

class CompanyController extends Controller
{
    public function __construct(
        protected UserDirectory $users,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');
        abort_unless(config('leadhub.features.companies', false), 404);

        $canManage = $this->userCan($request, 'manage leadhub companies');

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
            'edit_url' => cp_route('leadhub.companies.edit', $company->id),
            'delete_url' => cp_route('leadhub.companies.destroy', $company->id),
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
            'canManage' => $canManage,
            'createUrl' => $canManage ? cp_route('leadhub.companies.create') : null,
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
                'edit_url' => cp_route('leadhub.companies.edit', $model->id),
                'delete_url' => cp_route('leadhub.companies.destroy', $model->id),
            ],
            'contacts' => $model->contacts->map(fn ($contact) => [
                'id' => $contact->id,
                'name' => $contact->displayName(),
                'email' => $contact->email,
                'relationship_label' => $contact->pivot->relationship_label,
                'is_primary' => (bool) $contact->pivot->is_primary,
                'url' => cp_route('leadhub.contacts.show', $contact->id),
            ])->all(),
            'canManage' => $this->userCan($request, 'manage leadhub companies'),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub companies');
        abort_unless(config('leadhub.features.companies', false), 404);

        return Inertia::render('leadhub::Companies/Create', [
            'assignableUsers' => $this->users->assignable(),
            'storeUrl' => cp_route('leadhub.companies.store'),
            'cancelUrl' => cp_route('leadhub.companies.index'),
        ]);
    }

    /**
     * Companies are freely creatable, not only a by-product of linking one to
     * a contact.
     *
     * Not routed through Services\CompanyResolver: that one deduplicates and
     * would answer a deliberate "create" with somebody else's record. The
     * request already refuses a duplicate name or domain with a message on the
     * field, which is the honest version of the same protection. Normalization
     * and domain derivation happen in the model's `creating` hook either way,
     * and LeadHubCompanyCreated is fired here so the webhook bridge and the
     * segment listeners see CP-created companies exactly like facade-created
     * ones.
     */
    public function store(StoreCompanyRequest $request)
    {
        abort_unless(config('leadhub.features.companies', false), 404);

        $company = Company::query()->create($request->validated());

        event(new LeadHubCompanyCreated($company));

        return redirect(cp_route('leadhub.companies.show', $company->id))
            ->with('success', __('leadhub::companies.created'));
    }

    public function edit(Request $request, int|string $company)
    {
        $this->authorizeOrFail($request, 'manage leadhub companies');
        abort_unless(config('leadhub.features.companies', false), 404);

        $model = Company::query()->findOrFail($company);

        return Inertia::render('leadhub::Companies/Edit', [
            'company' => [
                'id' => $model->id,
                'name' => $model->name,
                'website' => $model->website,
                'industry' => $model->industry,
                'employee_range' => $model->employee_range,
                'description' => $model->description,
                'status' => $model->status,
                'owner_id' => (string) ($model->owner_id ?? ''),
            ],
            'assignableUsers' => $this->users->assignable(),
            'updateUrl' => cp_route('leadhub.companies.update', $model->id),
            'cancelUrl' => cp_route('leadhub.companies.show', $model->id),
        ]);
    }

    public function update(UpdateCompanyRequest $request, int|string $company)
    {
        abort_unless(config('leadhub.features.companies', false), 404);

        $model = Company::query()->findOrFail($company);
        $model->fill($request->validated());
        $model->save();

        return redirect(cp_route('leadhub.companies.show', $model->id))
            ->with('success', __('leadhub::companies.updated'));
    }

    /**
     * Deleting is refused while anything still hangs on the company.
     *
     * The same rule v1.5.0 established for pipeline stages, and for the same
     * reason: the alternatives are worse. A hard delete would cascade the
     * contact links away and leave every opportunity's `company_id` pointing
     * at nothing (that FK does not cascade), plus timeline entries naming a
     * company that no longer exists. Archiving would add a third state to
     * every list, filter and report forever. So the record stays, and the
     * message says what is in the way.
     */
    public function destroy(Request $request, int|string $company)
    {
        $this->authorizeOrFail($request, 'manage leadhub companies');
        abort_unless(config('leadhub.features.companies', false), 404);

        $model = Company::query()->findOrFail($company);

        $contacts = $model->contacts()->count();

        if ($contacts > 0) {
            return back()->withErrors([
                'company' => __('leadhub::companies.delete_has_contacts', ['count' => $contacts]),
            ]);
        }

        $opportunities = Opportunity::query()->where('company_id', $model->id)->count();

        if ($opportunities > 0) {
            return back()->withErrors([
                'company' => __('leadhub::companies.delete_has_opportunities', ['count' => $opportunities]),
            ]);
        }

        $model->delete();

        return redirect(cp_route('leadhub.companies.index'))
            ->with('success', __('leadhub::companies.deleted'));
    }
}
