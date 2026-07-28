<?php

namespace Goldnead\Leadhub\Http\Requests\Concerns;

use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Support\UserDirectory;

/**
 * Existence checks for the ids a CRM form posts.
 *
 * Deliberately NOT Laravel's `exists:` rule. `exists` compiles to a raw query
 * builder statement, which never passes through a model — so the `HasBrand`
 * global scope does not apply and `exists:leadhub_contacts,id` happily
 * confirms a contact of another brand. Every check here goes through the
 * model's query so a foreign-brand id is simply not found.
 */
trait ResolvesCrmReferences
{
    protected function contactExists(mixed $id): bool
    {
        return $id !== null && $id !== ''
            && Contact::query()->whereKey($id)->exists();
    }

    protected function companyExists(mixed $id): bool
    {
        return $id !== null && $id !== ''
            && Company::query()->whereKey($id)->exists();
    }

    protected function pipelineExists(mixed $id): bool
    {
        return $id !== null && $id !== ''
            && Pipeline::query()->whereKey($id)->exists();
    }

    /**
     * A stage id, but only when it belongs to the given pipeline. Guards
     * against a form that posts a valid stage of somebody else's pipeline.
     */
    protected function stageBelongsToPipeline(mixed $stageId, mixed $pipelineId): bool
    {
        if ($stageId === null || $stageId === '') {
            return false;
        }

        $pipeline = Pipeline::query()->whereKey($pipelineId)->first();

        return $pipeline !== null && $pipeline->stages()->whereKey($stageId)->exists();
    }

    /**
     * Whether a user id may be handed work.
     *
     * Assignees are the CP users who may view LeadHub — see
     * Support\UserDirectory. Validating against that same list is what stops a
     * hand-crafted request from parking a task on an account that cannot even
     * open the module.
     */
    protected function isAssignableUser(mixed $id): bool
    {
        if ($id === null || $id === '') {
            return true; // unassigned is always allowed
        }

        return collect(app(UserDirectory::class)->assignable())
            ->pluck('value')
            ->contains((string) $id);
    }
}
