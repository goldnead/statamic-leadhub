<?php

namespace Goldnead\Leadhub\Models\Concerns;

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
        $own = $this->getAttribute(
            method_exists($this, 'getBrandColumn') ? $this->getBrandColumn() : $column
        );

        if (! empty($own)) {
            return (int) $own;
        }

        if (! app()->bound('brand-context')) {
            return null;
        }

        try {
            return (int) app('brand-context')->currentId();
        } catch (\Throwable) {
            // No brands table yet (fresh install mid-migration): stay inert
            // rather than break the relation.
            return null;
        }
    }
}
