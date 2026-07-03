<?php

namespace Goldnead\Leadhub\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast for a Segment's `rules` JSON.
 *
 * Unlike Laravel's built-in `'array'` cast, this accepts BOTH a stored JSON
 * string (eloquent driver, read straight from the DB column) AND an
 * already-decoded PHP array (flat-file driver, where ModelHydrator feeds
 * setRawAttributes() a pre-parsed array, and callers constructing
 * `new Segment(['rules' => [...]])` in memory). The built-in cast assumes a
 * string on get() and json_decode()s it — passing an array there throws.
 * This cast is symmetric and null-safe in both directions.
 */
class SegmentRules implements CastsAttributes
{
    /**
     * @return array<string,mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            // Already-encoded JSON string — store as-is (validated by a round-trip).
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $value : json_encode([]);
        }

        return json_encode($value);
    }
}
