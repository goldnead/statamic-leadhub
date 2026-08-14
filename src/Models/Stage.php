<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A stage: one column of a pipeline, optionally the terminal one.
 *
 * Columns spelled out for the same reason as on {@see Opportunity}.
 *
 * @property int $id
 * @property string $uuid
 * @property int $pipeline_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_terminal
 * @property string|null $terminal_outcome
 * @property int|null $brand_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pipeline|null $pipeline
 * @property-read Collection<int, Opportunity> $opportunities
 */
class Stage extends Model
{
    use HasBrand;

    protected $table = 'leadhub_stages';

    protected $guarded = [];

    protected $casts = [
        'is_terminal' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const OUTCOME_WON = 'won';
    public const OUTCOME_LOST = 'lost';

    protected static function booted(): void
    {
        static::creating(function (self $stage): void {
            if (empty($stage->uuid)) {
                $stage->uuid = (string) Str::uuid();
            }

            if (empty($stage->slug) && $stage->name) {
                $stage->slug = Str::slug($stage->name);
            }
        });
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return HasMany<Opportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function isWon(): bool
    {
        return $this->is_terminal && $this->terminal_outcome === self::OUTCOME_WON;
    }

    public function isLost(): bool
    {
        return $this->is_terminal && $this->terminal_outcome === self::OUTCOME_LOST;
    }
}
