<?php

namespace Goldnead\Leadhub\Http\Requests\Concerns;

use Goldnead\Leadhub\Support\DateValueNormalizer;

/**
 * Lets a FormRequest accept a CP `<DatePicker>` value on a date field.
 *
 * See Goldnead\Leadhub\Support\DateValueNormalizer for why this is needed.
 */
trait NormalizesDatePickerValues
{
    protected function normalizeDatePickerInput(string ...$keys): void
    {
        $merge = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $normalized = DateValueNormalizer::normalize($this->input($key));

            if ($normalized !== $this->input($key)) {
                $merge[$key] = $normalized;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
