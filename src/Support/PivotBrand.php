<?php

namespace Goldnead\Leadhub\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves which brand a LeadHub pivot row belongs to.
 *
 * The pivot tables carry a denormalized `brand_id` that the brand-scoping
 * migration justified as "query-time defense: every read filters on brand_id
 * instead of trusting a join not to be forgotten". Two consumers need the same
 * answer to that question:
 *
 *  - `Models\Concerns\ScopesPivotToBrand`, for pivots reached through an
 *    Eloquent relation (`leadhub_contact_company`, `leadhub_contact_tag`,
 *    `leadhub_segment_contact`), and
 *  - `Repositories\Eloquent\EloquentSegmentRepository`, which writes and reads
 *    `leadhub_segment_contact` through raw query-builder calls and therefore
 *    has no relation to hang the filter on.
 *
 * They resolve it here so the two paths cannot drift apart.
 */
final class PivotBrand
{
    /**
     * The brand of the record that owns the pivot row, falling back to the
     * current brand context for records not yet stamped.
     *
     * Returns null when no brand can be resolved at all — on a fresh install
     * mid-migration, or with the brand-context addon absent. Callers treat null
     * as "do not filter and do not stamp", which keeps this inert rather than
     * making every pivot query match nothing.
     */
    public static function for(?Model $owner, string $column = 'brand_id'): ?int
    {
        if ($owner !== null) {
            $own = $owner->getAttribute(
                method_exists($owner, 'getBrandColumn') ? $owner->getBrandColumn() : $column
            );

            if (! empty($own)) {
                return (int) $own;
            }
        }

        return static::current();
    }

    /** The brand of the current request/console context, or null if there is none. */
    public static function current(): ?int
    {
        if (! app()->bound('brand-context')) {
            return null;
        }

        try {
            $id = (int) app('brand-context')->currentId();
        } catch (\Throwable) {
            // No brands table yet (fresh install mid-migration): stay inert
            // rather than break every query that touches a pivot.
            return null;
        }

        return $id > 0 ? $id : null;
    }
}
