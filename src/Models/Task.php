<?php

namespace Goldnead\Leadhub\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Task extends Model
{
    protected $table = 'leadhub_tasks';

    protected $guarded = [];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    protected static function booted(): void
    {
        static::creating(function (self $task): void {
            if (empty($task->uuid)) {
                $task->uuid = (string) Str::uuid();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
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

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeDueToday(Builder $query): Builder
    {
        return $query->open()->whereDate('due_at', now()->toDateString());
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->where('due_at', '<', now()->startOfDay());
    }

    public function scopeForAssignee(Builder $query, string $assigneeId): Builder
    {
        return $query->where('assignee_id', $assigneeId);
    }
}
