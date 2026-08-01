<?php

namespace Goldnead\Leadhub\Http\Requests\Concerns;

use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Tag;
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

    /** The opportunity itself, so the caller can also check who it belongs to. */
    protected function findOpportunity(mixed $id): ?Opportunity
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Opportunity::query()->whereKey($id)->first();
    }

    /**
     * Tag ids, resolved through the tag repository rather than
     * `exists:leadhub_tags,id`.
     *
     * The `exists` rule is a raw query against a table. That is wrong twice
     * over here: it bypasses the brand scope like every other `exists` in this
     * file's docblock, and — the reason it was found — under the flat-file
     * storage driver the table is empty, because tags live in `tags.yaml`.
     * The rule therefore rejected every tag on a flat install, so no tag could
     * be attached to a contact through the CP at all.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string> the ids that could not be resolved
     */
    protected function unknownTagIds(array $ids): array
    {
        $repository = app(TagRepository::class);

        return collect($ids)
            // A non-scalar cannot be an id and must not reach the cast below.
            ->map(fn ($id) => is_scalar($id) ? (string) $id : null)
            ->reject(fn (?string $id) => $id !== null && $repository->find($id) instanceof Tag)
            ->map(fn (?string $id) => $id ?? '')
            ->values()
            ->all();
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
