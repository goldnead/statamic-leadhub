<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Database\Factories\FollowupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Followup extends Model
{
    use HasBrand;

    use HasFactory;

    protected $table = 'leadhub_followups';

    protected $guarded = [];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $followup): void {
            if (empty($followup->uuid)) {
                $followup->uuid = (string) Str::uuid();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isCompleted(): bool
    {
        return ! is_null($this->completed_at);
    }

    public function isOverdue(): bool
    {
        return ! $this->isCompleted()
            && $this->due_at instanceof Carbon
            && $this->due_at->isPast();
    }

    public function isDueToday(): bool
    {
        return ! $this->isCompleted()
            && $this->due_at instanceof Carbon
            && $this->due_at->isToday();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeDueToday(Builder $query): Builder
    {
        return $query->active()
            ->whereDate('due_at', now()->toDateString());
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->active()
            ->where('due_at', '<', now()->startOfDay());
    }

    protected static function newFactory(): FollowupFactory
    {
        return FollowupFactory::new();
    }
}
