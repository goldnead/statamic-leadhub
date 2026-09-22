<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Models\PostalCode;
use Illuminate\Support\Facades\Cache;

/**
 * Which postal codes lie within a radius of another one.
 *
 * Resolved through this service and not through an Eloquent relation on the
 * contact, because the flat-file driver hydrates contacts without relations —
 * the same reason tags and events go through repositories. See the note in the
 * head of `SegmentEvaluator`.
 *
 * The answer does not change with time, only when the postal-code table is
 * re-imported. So it is cached twice: once per process, because a segment sweep
 * asks the same question for every one of three thousand contacts, and once in
 * the application cache, so the next sweep does not start from the database
 * either.
 */
class PostalCodeRadius
{
    /** @var array<string, array<string, true>> Cache key => set of "CC:CODE". */
    protected array $memo = [];

    public function __construct(protected int $ttlSeconds = 86400) {}

    /**
     * The set of postal codes within `$km` of the given one.
     *
     * An unknown centre returns an empty set rather than everything: a typo in
     * the segment must not mail the whole list.
     *
     * @return array<string, true> keyed "CC:CODE" for O(1) membership
     */
    public function within(string $postalCode, string $country, float $km): array
    {
        $country = strtoupper(trim($country)) ?: 'DE';
        $code = PostalCode::normalise($postalCode);
        $key = "leadhub:postal-radius:{$country}:{$code}:".round($km, 2);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $set = Cache::remember($key, $this->ttlSeconds, function () use ($code, $country, $km): array {
            $centre = PostalCode::lookup($code, $country);

            if (! $centre) {
                return [];
            }

            $lat = (float) $centre->latitude;
            $lng = (float) $centre->longitude;

            return PostalCode::query()
                ->withinBox($lat, $lng, $km)
                ->get(['country', 'postal_code', 'latitude', 'longitude'])
                // The box is a square around the circle, so it hands back
                // corners that are further away than the radius. Trimming them
                // here keeps the SQL portable; see the scope's docblock.
                ->filter(fn (PostalCode $row) => PostalCode::distanceKm(
                    $lat, $lng, (float) $row->latitude, (float) $row->longitude
                ) <= $km)
                ->mapWithKeys(fn (PostalCode $row) => [$row->country.':'.$row->postal_code => true])
                ->all();
        });

        return $this->memo[$key] = $set;
    }

    /**
     * Is this contact's postal code inside the radius?
     *
     * A contact without a postal code is **not** a match, in either direction.
     * `within_km` must not mail somebody whose location is unknown, and
     * `outside_km` must not either — "we do not know" is not "far away".
     * The count of those contacts belongs next to the segment size, otherwise a
     * small segment reads as a wrong radius instead of as missing data.
     */
    public function covers(
        string $centrePostalCode,
        string $centreCountry,
        float $km,
        ?string $postalCode,
        ?string $country,
    ): bool {
        if ($postalCode === null || trim($postalCode) === '') {
            return false;
        }

        $set = $this->within($centrePostalCode, $centreCountry, $km);

        if ($set === []) {
            return false;
        }

        $key = (strtoupper(trim((string) $country)) ?: 'DE').':'.PostalCode::normalise($postalCode);

        return isset($set[$key]);
    }

    /** Drops the per-process memo. The import command calls this after writing. */
    public function forget(): void
    {
        $this->memo = [];
    }
}
