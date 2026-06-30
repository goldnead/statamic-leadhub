<?php

namespace Goldnead\Leadhub\Support;

class PhoneNormalizer
{
    /**
     * Normalize a phone number for deduplication: keep a leading "+" and the
     * digits, drop spaces, dashes, parentheses and other punctuation.
     *
     * Intentionally conservative — no country-code inference, which would
     * require libphonenumber and a known default region.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $value = trim($phone);

        if ($value === '') {
            return null;
        }

        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        return ($hasPlus ? '+' : '').$digits;
    }
}
