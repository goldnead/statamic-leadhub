<?php

namespace Goldnead\Leadhub\Models\Concerns;

use Goldnead\Leadhub\Support\PivotBrand;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Makes the denormalized `brand_id` on a LeadHub pivot table real.
 *
 * The brand-scoping migration (2026_07_24_100000) added `brand_id` to every
 * pivot table and justified it as "query-time defense: every read filters on
 * brand_id instead of trusting a join not to be forgotten". Nothing ever
 * stamped or read it, so the column was documentation, not defense.
 *
 * This trait closes that gap in one place:
 *
 *  - `withPivotValue()` writes the brand on every attach/sync, AND
 *  - constrains every read of the relation to that brand.
 *
 * It matters where the models' own BrandScope cannot help: cross-brand admin
 * and reporting paths deliberately run inside
 * `BrandContext::withoutBrandScope()`, and console commands iterate brands.
 * With the global scope off, a mis-stamped pivot row would otherwise hand a
 * contact of brand A a company of brand B. The pivot filter holds regardless.
 */
trait ScopesPivotToBrand
{
    /**
     * Constrain a pivot relation to this record's own brand — falling back to
     * the current brand context for models not yet stamped.
     *
     * Generic on the relation it is handed, and that is not decoration: every
     * pivot relation in this addon is wrapped in this call, so a bare
     * `BelongsToMany` here flattens `BelongsToMany<Contact, Company>` down to
     * `BelongsToMany<Model, Model>` at every single call site. The mapped
     * closures downstream then see `Model`, and reading a column off one is an
     * undefined property — which is how `Model::$status` and `Model::$id`
     * ended up frozen in the baseline instead of being typed.
     *
     * @template TRelation of BelongsToMany<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model>
     *
     * @param  TRelation  $relation
     * @return TRelation
     */
    protected function scopePivotToOwnBrand(BelongsToMany $relation, string $column = 'brand_id'): BelongsToMany
    {
        $brandId = $this->resolvePivotBrandId($column);

        if ($brandId === null) {
            return $relation;
        }

        // withPivotValue() does both halves of the contract: it stamps the
        // column on attach/sync and adds the pivot WHERE on every read.
        return $relation->withPivotValue($column, $brandId);
    }

    protected function resolvePivotBrandId(string $column = 'brand_id'): ?int
    {
        // Shared with EloquentSegmentRepository, which has to answer the same
        // question without a relation to hang it on.
        return PivotBrand::for($this, $column);
    }
}
