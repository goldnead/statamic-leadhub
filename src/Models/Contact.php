<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\Leadhub\Database\Factories\ContactFactory;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Leadhub\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $table = 'leadhub_contacts';

    protected $guarded = [];

    protected $casts = [
        'consent' => 'boolean',
        'consent_at' => 'datetime',
        'do_not_contact' => 'boolean',
        'engagement_score' => 'integer',
        'metadata_json' => 'array',
        'last_activity_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $contact): void {
            if (empty($contact->uuid)) {
                $contact->uuid = (string) \Illuminate\Support\Str::uuid();
            }

            if ($contact->email && empty($contact->email_normalized)) {
                $contact->email_normalized = EmailNormalizer::normalize($contact->email);
            }

            if ($contact->phone && empty($contact->phone_normalized)) {
                $contact->phone_normalized = PhoneNormalizer::normalize($contact->phone);
            }
        });

        static::updating(function (self $contact): void {
            if ($contact->isDirty('email') && $contact->email) {
                $contact->email_normalized = EmailNormalizer::normalize($contact->email);
            }

            if ($contact->isDirty('phone')) {
                $contact->phone_normalized = PhoneNormalizer::normalize($contact->phone);
            }
        });
    }

    /**
     * Display name preference: full_name → "first last" → email.
     */
    public function displayName(): string
    {
        if ($this->full_name) {
            return $this->full_name;
        }

        $combined = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        if ($combined !== '') {
            return $combined;
        }

        return $this->email ?? __('leadhub::contacts.unnamed');
    }

    public function isArchived(): bool
    {
        return ! is_null($this->archived_at);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class)->orderByDesc('created_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->orderByDesc('created_at');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            Tag::class,
            'leadhub_contact_tag',
            'contact_id',
            'tag_id'
        )->withTimestamps();
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            'leadhub_contact_company',
            'contact_id',
            'company_id'
        )->withPivot(['relationship_label', 'is_primary'])->withTimestamps();
    }

    /** The surviving contact this one was merged into (if any). */
    public function mergedInto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_contact_id');
    }

    /** Contacts that were merged into this one. */
    public function mergedContacts(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_contact_id');
    }

    public function isMerged(): bool
    {
        return ! is_null($this->merged_into_contact_id);
    }

    /** Only contacts that have not been merged away. */
    public function scopeUnmerged(Builder $query): Builder
    {
        return $query->whereNull('merged_into_contact_id');
    }

    /** Contacts that are reachable (not opted out). */
    public function scopeContactable(Builder $query): Builder
    {
        return $query->where('do_not_contact', false);
    }

    public function activeFollowup()
    {
        return $this->followups()
            ->whereNull('completed_at')
            ->orderBy('due_at')
            ->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeWithStatus(Builder $query, string|array $status): Builder
    {
        return $query->whereIn('status', (array) $status);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('full_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('company', 'like', $like);
        });
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }
}
