<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A piece of work somebody owes a contact, optionally hanging on a deal.
 *
 * Columns spelled out for the same reason as on {@see Opportunity}.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $contact_id
 * @property int|null $opportunity_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property string|null $assignee_id
 * @property string|null $created_by
 * @property string|null $completed_by
 * @property array<string, mixed>|null $metadata_json
 * @property int|null $brand_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Contact|null $contact
 * @property-read Opportunity|null $opportunity
 */
class Task extends Model
{
    use HasBrand;

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

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Opportunity, $this> */
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
