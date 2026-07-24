<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

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
