<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pipeline: the ordered set of stages a deal moves through.
 *
 * Columns spelled out for the same reason as on {@see Opportunity}.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $brand_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Stage> $stages
 * @property-read Collection<int, Opportunity> $opportunities
 */
class Pipeline extends Model
{
    use HasBrand;

    protected $table = 'leadhub_pipelines';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $pipeline): void {
            if (empty($pipeline->uuid)) {
                $pipeline->uuid = (string) Str::uuid();
            }

            if (empty($pipeline->slug) && $pipeline->name) {
                $pipeline->slug = Str::slug($pipeline->name);
            }
        });
    }

    /** @return HasMany<Stage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('sort_order');
    }

    /** @return HasMany<Opportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function defaultStage(): ?Stage
    {
        return $this->stages()->orderBy('sort_order')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
