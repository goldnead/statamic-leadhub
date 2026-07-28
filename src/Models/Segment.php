<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Casts\SegmentRules;
use Goldnead\Leadhub\Models\Concerns\ScopesPivotToBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Segment extends Model
{
    use HasBrand, ScopesPivotToBrand;

    protected $table = 'leadhub_segments';

    protected $guarded = [];

    protected $casts = [
        'rules' => SegmentRules::class,
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $segment): void {
            if (empty($segment->uuid)) {
                $segment->uuid = (string) Str::uuid();
            }

            if (empty($segment->handle) && $segment->name) {
                $segment->handle = Str::slug($segment->name);
            }
        });
    }

    public function contacts(): BelongsToMany
    {
        // Membership is brand-scoped on the pivot itself, not only through the
        // two models: cross-brand reporting and per-brand console runs turn the
        // models' BrandScope off deliberately, and this filter is what still
        // holds then. Membership is written by EloquentSegmentRepository, which
        // stamps the same column on insert.
        return $this->scopePivotToOwnBrand(
            $this->belongsToMany(
                Contact::class,
                'leadhub_segment_contact',
                'segment_id',
                'contact_id'
            )->withPivot('entered_at')
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
