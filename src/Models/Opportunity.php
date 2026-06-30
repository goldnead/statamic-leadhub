<?php

namespace Goldnead\Leadhub\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Opportunity extends Model
{
    protected $table = 'leadhub_opportunities';

    protected $guarded = [];

    protected $casts = [
        'value_estimate' => 'decimal:2',
        'confidence' => 'integer',
        'manual_override' => 'boolean',
        'last_activity_at' => 'datetime',
        'closed_at' => 'datetime',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected static function booted(): void
    {
        static::creating(function (self $opportunity): void {
            if (empty($opportunity->uuid)) {
                $opportunity->uuid = (string) Str::uuid();
            }

            if (empty($opportunity->last_activity_at)) {
                $opportunity->last_activity_at = now();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(StageTransition::class)->orderByDesc('occurred_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForPipeline(Builder $query, int $pipelineId): Builder
    {
        return $query->where('pipeline_id', $pipelineId);
    }
}
