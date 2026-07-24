<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('sort_order');
    }

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
