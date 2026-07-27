<?php

namespace Goldnead\Leadhub\Support;

use DateTimeInterface;

/**
 * Normalizes what a Statamic CP `<DatePicker>` submits into something a
 * Laravel `date` validation rule accepts.
 *
 * The CP date picker is built on reka-ui, and its `v-model` is an
 * `@internationalized/date` *DateValue object*, never a string. Serialized
 * into a request it arrives as:
 *
 *     {"calendar":{"identifier":"gregory"},"era":"AD","year":2026,"month":7,"day":19}
 *
 * Laravel's `date` rule rejects anything that is not a string, a number or a
 * DateTimeInterface, so such a payload fails with "Not a valid date." — the
 * exact 422 that made follow-ups uncreatable from the UI.
 *
 * The Vue side normalizes before sending (resources/js/support/datetime.js).
 * This class is the second line of defense so the same payload can never
 * produce a silent 422 again, no matter which caller sends it.
 */
class DateValueNormalizer
{
    /**
     * Return a `Y-m-d H:i:s` string for a DateValue-shaped array, and the
     * value untouched for everything else (strings, numbers, null, DateTime).
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null || $value instanceof DateTimeInterface || is_string($value) || is_numeric($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        // Wrapper shapes some pickers use: {date: "..."} / {value: "..."}.
        foreach (['iso', 'date', 'datetime', 'value'] as $key) {
            if (isset($value[$key]) && (is_string($value[$key]) || is_numeric($value[$key]))) {
                return (string) $value[$key];
            }
        }

        if (! isset($value['year'], $value['month'], $value['day'])) {
            return $value;
        }

        // A DateValue carries wall-clock parts; the picker already renders them
        // in the CP timezone, so they are taken at face value.
        return sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            (int) $value['year'],
            (int) $value['month'],
            (int) $value['day'],
            (int) ($value['hour'] ?? 0),
            (int) ($value['minute'] ?? 0),
            (int) ($value['second'] ?? 0),
        );
    }
}
