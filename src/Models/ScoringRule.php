<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One lead-scoring rule: an activity type and the points it awards.
 *
 * Brand-scoped through {@see HasBrand}, which is the whole point of the table —
 * config could never be. A rule of brand A is neither visible nor arithmetically
 * effective in brand B; the second half is the one that matters, because a rule
 * computing in the wrong tenant would move scores, and scores steer segments,
 * and segments steer who gets mail. `ScoringRuleBrandIsolationTest` asserts both
 * halves separately.
 */
class ScoringRule extends Model
{
    use HasBrand;

    /**
     * The catch-all rule: applies to every activity type without a rule of its
     * own. This is `leadhub.scoring.default` expressed as a row, so a brand can
     * set its own baseline instead of inheriting one global config value.
     */
    public const CATCH_ALL = '*';

    protected $table = 'leadhub_scoring_rules';

    protected $guarded = [];

    protected $casts = [
        'points' => 'integer',
        'enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $rule): void {
            if (empty($rule->uuid)) {
                $rule->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function isCatchAll(): bool
    {
        return $this->event_type === self::CATCH_ALL;
    }
}
