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
        'managed_by' => 'array',
    ];

    /**
     * Who maintains this segment, if not the people editing it in the CP.
     *
     * Set by another addon through `SegmentRepository::create()` / `update()`:
     *
     *     ['source' => 'statamic-marketing', 'label' => 'Serie: …', 'url' => 'https://…']
     *
     * `source` is required (an owner nobody can name is no owner), `label` and
     * `url` are optional. Anything else is dropped. A managed segment's name
     * and rule are read-only in the CP, because the owner's next sync would
     * write them back.
     *
     * @return array{source: string, label: string|null, url: string|null}|null
     */
    public function managedBy(): ?array
    {
        $value = $this->getAttribute('managed_by');

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return null;
        }

        $source = trim((string) ($value['source'] ?? ''));

        if ($source === '') {
            return null;
        }

        $label = trim((string) ($value['label'] ?? ''));
        $url = trim((string) ($value['url'] ?? ''));

        return [
            'source' => $source,
            'label' => $label === '' ? null : $label,
            'url' => $url === '' ? null : $url,
        ];
    }

    public function isManaged(): bool
    {
        return $this->managedBy() !== null;
    }

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
