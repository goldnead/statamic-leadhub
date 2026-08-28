<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

/**
 * A field a site defined for its own contacts.
 *
 * The definition lives here; the value lives on the contact, under this
 * field's handle. See the migration for why the two are stored differently.
 *
 * The columns are declared below rather than left to inference. Larastan reads
 * a model's schema from migrations only when it can resolve them, and it
 * cannot resolve an addon's migrations from a consuming application — so every
 * read of `$field->handle` in this addon was a level 5 error, and the baseline
 * this repo keeps is a ratchet that must not grow.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $handle
 * @property string $label
 * @property string $type
 *                        Deliberately loose: the column is JSON, `optionValues()` defends against
 *                        scalars and missing keys, and the shape it is written with today is not a
 *                        guarantee about the rows a database already holds.
 * @property array<int, mixed>|null $options
 * @property string|null $instructions
 * @property int $sort
 */
class CustomField extends Model
{
    use HasBrand;

    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_SELECT = 'select';

    public const TYPE_DATE = 'date';

    public const TYPE_BOOLEAN = 'boolean';

    protected $table = 'leadhub_custom_fields';

    protected $fillable = ['handle', 'label', 'type', 'options', 'instructions', 'sort'];

    protected $casts = [
        'options' => 'array',
        'sort' => 'integer',
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_TEXT, self::TYPE_NUMBER, self::TYPE_SELECT, self::TYPE_DATE, self::TYPE_BOOLEAN];
    }

    /**
     * Which comparisons make sense for this field.
     *
     * The segment builder asks, so that a date does not offer "contains" and a
     * yes/no does not offer "greater than". Guessing here is what turns a rule
     * builder into a place where you can write a condition that can never be
     * true.
     *
     * @return list<string>
     */
    public function operators(): array
    {
        // The evaluator's own vocabulary, not a second one. `is_set` and
        // `is_empty` already exist there; inventing `set`/`not_set` here would
        // have produced rules the builder offers and the evaluator answers
        // `false` to — a condition that can never be true, with nothing saying
        // so.
        return match ($this->type) {
            self::TYPE_NUMBER => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'is_set', 'is_empty'],
            self::TYPE_DATE => ['eq', 'neq', 'before', 'after', 'within_days', 'older_than_days', 'is_set', 'is_empty'],
            self::TYPE_BOOLEAN => ['is_true', 'is_false', 'is_set', 'is_empty'],
            self::TYPE_SELECT => ['eq', 'neq', 'in', 'not_in', 'is_set', 'is_empty'],
            default => ['eq', 'neq', 'contains', 'starts_with', 'is_set', 'is_empty'],
        };
    }

    /**
     * Bring a written value into the shape this field stores.
     *
     * A number arrives from a form as a string, a checkbox as "on", a date in
     * whatever the input produced. Storing them as they arrive would make every
     * later comparison a guess — `"20" > 40` is a different question from
     * `20 > 40`, and only one of them has the answer somebody meant.
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            self::TYPE_NUMBER => is_numeric($value) ? $value + 0 : null,
            self::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            self::TYPE_DATE => ($t = strtotime((string) $value)) === false ? null : date('Y-m-d', $t),
            self::TYPE_SELECT => in_array((string) $value, $this->optionValues(), true) ? (string) $value : null,
            default => (string) $value,
        };
    }

    /** @return list<string> */
    public function optionValues(): array
    {
        return collect($this->options ?? [])
            ->map(static fn (mixed $o): ?string => is_array($o) ? ($o['value'] ?? null) : (is_scalar($o) ? (string) $o : null))
            ->filter()
            ->values()
            ->all();
    }
}
