<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A deal: one contact's chance of business, moving through a pipeline.
 *
 * The columns are spelled out below because the model is `$guarded = []` with no
 * `$fillable`, so nothing else in the file says what a row holds. Static
 * analysis could not see a single attribute either — every `$opportunity->status`
 * in the addon was an "undefined property" carried in the PHPStan baseline, and
 * a typo in a column name looked exactly like a correct one. Written out here,
 * the analyser checks them and the baseline gets shorter.
 *
 * @property int $id
 * @property string $uuid
 * @property int $contact_id
 * @property int|null $company_id
 * @property int $pipeline_id
 * @property int $stage_id
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $title
 * @property string|null $value_estimate
 * @property int $confidence
 * @property string $status
 * @property string|null $outcome
 * @property bool $manual_override
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $won_at
 * @property Carbon|null $lost_at
 * @property string|null $owner_id
 * @property array<string, mixed>|null $metadata_json
 * @property int|null $brand_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read Pipeline|null $pipeline
 * @property-read Stage|null $stage
 * @property-read Collection<int, StageTransition> $transitions
 * @property-read Collection<int, Task> $tasks
 */
class Opportunity extends Model
{
    use HasBrand;

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

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return HasMany<StageTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(StageTransition::class)->orderByDesc('occurred_at');
    }

    /** @return HasMany<Task, $this> */
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
