<?php

namespace Goldnead\Leadhub\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One postal code and where it is.
 *
 * Deliberately without `HasBrand`: a postal code is the same place for every
 * brand on the installation, and a per-brand copy would mean importing the same
 * ten thousand German rows once per brand.
 *
 * @property int $id
 * @property string $country
 * @property string $postal_code
 * @property string|null $place
 * @property string|null $region
 * @property string $latitude
 * @property string $longitude
 */
class PostalCode extends Model
{
    protected $table = 'leadhub_postal_codes';

    protected $guarded = [];

    /** Mean radius of the earth in kilometres. */
    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * A square around a circle: everything that *could* be within `$km`.
     *
     * The precise distance is measured in PHP afterwards, not here, and that is
     * deliberate. LeadHub runs against MySQL and SQLite, and the great-circle
     * term needs `least`, `greatest` and `radians` — SQLite has none of them and
     * fails with "no such function: least" rather than with a wrong number. A
     * box on two indexed columns is the same in every dialect, and it throws
     * away all but a few hundred rows, which PHP measures in microseconds.
     *
     * @param  Builder<PostalCode>  $query
     * @return Builder<PostalCode>
     */
    public function scopeWithinBox(Builder $query, float $latitude, float $longitude, float $km): Builder
    {
        $latDelta = rad2deg($km / self::EARTH_RADIUS_KM);

        // Longitude degrees shrink towards the poles, so the box has to widen.
        // cos() of a latitude at the poles is ~0; guard against dividing by it.
        $cos = max(cos(deg2rad($latitude)), 0.000001);
        $lngDelta = rad2deg($km / self::EARTH_RADIUS_KM / $cos);

        return $query
            ->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta])
            ->whereBetween('longitude', [$longitude - $lngDelta, $longitude + $lngDelta]);
    }

    /**
     * Great-circle distance in kilometres.
     *
     * Haversine rather than the spherical law of cosines: the argument of
     * `asin` stays inside [0, 1] by construction, so it needs no clamping —
     * which is what dragged `least`/`greatest` into the SQL in the first place.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * The row for a postal code, or null.
     *
     * Normalised the way people write them: `a-1070`, `A 1070` and `A-1070` are
     * one place, and a German code pasted with a stray space still resolves.
     */
    public static function lookup(string $postalCode, string $country = 'DE'): ?self
    {
        return static::query()
            ->where('country', strtoupper(trim($country)))
            ->where('postal_code', self::normalise($postalCode))
            ->first();
    }

    /** Strips spaces, dots and a country prefix, uppercases the rest. */
    public static function normalise(string $postalCode): string
    {
        $value = strtoupper(preg_replace('/[\s.]+/', '', trim($postalCode)) ?? '');

        // "A-1070" and "CH-8001" carry their country in front of the code. The
        // country lives in its own column here, so the prefix goes.
        return preg_replace('/^[A-Z]{1,2}-/', '', $value) ?? $value;
    }
}
