<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\Leadhub\Casts\SegmentRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Segment extends Model
{
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
        return $this->belongsToMany(
            Contact::class,
            'leadhub_segment_contact',
            'segment_id',
            'contact_id'
        )->withPivot('entered_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
