<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasBrand;

    use HasFactory;

    protected $table = 'leadhub_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const TYPE_CONTACT_CREATED = 'contact_created';
    public const TYPE_SUBMISSION_RECEIVED = 'submission_received';
    public const TYPE_NOTE_ADDED = 'note_added';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_CONTACT_ASSIGNED = 'contact_assigned';
    public const TYPE_TAG_ADDED = 'tag_added';
    public const TYPE_TAG_REMOVED = 'tag_removed';
    public const TYPE_FOLLOWUP_SET = 'followup_set';
    public const TYPE_FOLLOWUP_COMPLETED = 'followup_completed';
    public const TYPE_FOLLOWUP_REMOVED = 'followup_removed';
    public const TYPE_WEBHOOK_SENT = 'webhook_sent';
    public const TYPE_WEBHOOK_FAILED = 'webhook_failed';
    public const TYPE_CRM_SYNC_QUEUED = 'crm_sync_queued';
    public const TYPE_CRM_SYNC_SUCCEEDED = 'crm_sync_succeeded';
    public const TYPE_CRM_SYNC_FAILED = 'crm_sync_failed';
    public const TYPE_CONTACT_ARCHIVED = 'contact_archived';
    public const TYPE_CONTACT_DELETED = 'contact_deleted';
    public const TYPE_CONTACTS_MERGED = 'contacts_merged';
    public const TYPE_SOURCE_INGESTED = 'source_ingested';
    public const TYPE_TASK_CREATED = 'task_created';
    public const TYPE_TASK_COMPLETED = 'task_completed';
    public const TYPE_TASK_ASSIGNED = 'task_assigned';
    public const TYPE_OPPORTUNITY_CREATED = 'opportunity_created';
    public const TYPE_OPPORTUNITY_STAGE_CHANGED = 'opportunity_stage_changed';
    public const TYPE_OPPORTUNITY_WON = 'opportunity_won';
    public const TYPE_OPPORTUNITY_LOST = 'opportunity_lost';
    public const TYPE_SCORE_CHANGED = 'score_changed';

    /** A user granted access from the contact screen (through entitlements). */
    public const TYPE_ACCESS_GRANTED = 'access_granted';

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
