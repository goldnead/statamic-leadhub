<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Events\LeadHubCompanyCreated;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;

/**
 * Resolves companies (deduplicated by normalized name or domain) and links
 * them to contacts. Eloquent driver only.
 */
class CompanyResolver
{
    /**
     * Find an existing company by domain or normalized name, or create one.
     * Returns [Company $company, bool $wasCreated].
     */
    public function resolveOrCreate(array $attributes): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        $domain = Company::deriveDomain($attributes['website'] ?? null);

        $existing = null;

        if ($domain) {
            $existing = Company::query()->where('domain', $domain)->first();
        }

        if (! $existing && $name !== '') {
            $existing = Company::query()
                ->where('name_normalized', Company::normalizeName($name))
                ->first();
        }

        if ($existing) {
            return [$existing, false];
        }

        $company = Company::query()->create(array_filter([
            'name' => $name !== '' ? $name : ($domain ?? 'Unknown'),
            'website' => $attributes['website'] ?? null,
            'industry' => $attributes['industry'] ?? null,
            'employee_range' => $attributes['employee_range'] ?? null,
            'description' => $attributes['description'] ?? null,
            'owner_id' => $attributes['owner_id'] ?? null,
        ], fn ($v) => $v !== null));

        event(new LeadHubCompanyCreated($company));

        return [$company, true];
    }

    /**
     * Attach a company to a contact, optionally as the primary company.
     */
    public function link(Contact $contact, Company $company, ?string $label = null, bool $primary = false): void
    {
        if ($primary) {
            // Demote any existing primary for this contact — within its brand.
            // This is a raw pivot statement, so the relation's brand filter does
            // not apply and has to be repeated by hand.
            $contact->companies()->newPivotStatement()
                ->where('contact_id', $contact->id)
                ->where('brand_id', $contact->brand_id)
                ->update(['is_primary' => false]);
        }

        // brand_id is stamped by the relation itself (withPivotValue in
        // Models\Concerns\ScopesPivotToBrand), so a link can never be written
        // into the wrong brand from here.
        $contact->companies()->syncWithoutDetaching([
            $company->id => array_filter([
                'relationship_label' => $label,
                'is_primary' => $primary,
            ], fn ($v) => $v !== null),
        ]);
    }
}
