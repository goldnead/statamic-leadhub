<?php

namespace Goldnead\Leadhub\Support\Timeline;

/**
 * Cents to a readable amount, in the CP user's locale.
 *
 * Server-side on purpose. The one money helper the Vue bundle has
 * (`support/money.js`) is hard-wired to EUR because deal values carry no
 * currency column; payments do carry one, and a lifetime value in two
 * currencies must not be labelled in one.
 */
final class Amount
{
    public static function format(int $cent, string $currency): string
    {
        $currency = strtoupper(trim($currency)) ?: 'EUR';
        $value = $cent / 100;

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter((string) app()->getLocale(), \NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency($value, $currency);

            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return number_format($value, 2, ',', '.').' '.$currency;
    }
}
