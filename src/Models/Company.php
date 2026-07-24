<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasBrand;

    protected $table = 'leadhub_companies';

    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $company): void {
            if (empty($company->uuid)) {
                $company->uuid = (string) Str::uuid();
            }

            if ($company->name && empty($company->name_normalized)) {
                $company->name_normalized = static::normalizeName($company->name);
            }

            if ($company->website && empty($company->domain)) {
                $company->domain = static::deriveDomain($company->website);
            }
        });

        static::updating(function (self $company): void {
            if ($company->isDirty('name') && $company->name) {
                $company->name_normalized = static::normalizeName($company->name);
            }

            if ($company->isDirty('website')) {
                $company->domain = static::deriveDomain($company->website);
            }
        });
    }

    /** Normalize a company name for deduplication (lowercase, collapsed spaces). */
    public static function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $value = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));

        return $value === '' ? null : $value;
    }

    /** Extract a bare host (no scheme/www) from a website URL. */
    public static function deriveDomain(?string $website): ?string
    {
        if (! $website) {
            return null;
        }

        $candidate = $website;
        if (! preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://'.$candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        return mb_strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
    }

    public function displayName(): string
    {
        return $this->name ?? ($this->domain ?? __('leadhub::companies.unnamed'));
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            'leadhub_contact_company',
            'company_id',
            'contact_id'
        )->withPivot(['relationship_label', 'is_primary'])->withTimestamps();
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('name', 'like', $like)
                ->orWhere('domain', 'like', $like)
                ->orWhere('industry', 'like', $like);
        });
    }
}
