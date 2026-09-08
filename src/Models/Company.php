<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Models\Concerns\ScopesPivotToBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The whole schema of `leadhub_companies`, declared for the analyser.
 *
 * `Contact` declares only its post-baseline columns, because the rest of its
 * noise is frozen in the PHPStan baseline and the entries have to keep
 * matching. This model goes the other way and declares everything, because the
 * partial road is what broke the build: the baseline froze
 * `Company::$name` at "occurs 3 times" and `$domain` at 2, then the companies
 * screen grew two more reads and the counts drifted to 5 and 3. `ignore.count`
 * is non-ignorable, so a controller gaining a line turned the Larastan job red
 * without a single new defect behind it.
 *
 * Declaring the columns removes the cause instead of re-counting it. The seven
 * frozen `Company::$…` entries go with it — the ratchet turning the right way.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $uuid
 * @property string|null $name
 * @property string|null $name_normalized
 * @property string|null $website
 * @property string|null $domain
 * @property string|null $industry
 * @property string|null $employee_range
 * @property string|null $description
 * @property string $status
 * @property string|null $owner_id
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Company extends Model
{
    use HasBrand;
    use ScopesPivotToBrand;

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

    /** @return BelongsToMany<Contact, $this> */
    public function contacts(): BelongsToMany
    {
        return $this->scopePivotToOwnBrand(
            $this->belongsToMany(
                Contact::class,
                'leadhub_contact_company',
                'company_id',
                'contact_id'
            )->withPivot(['relationship_label', 'is_primary', 'brand_id'])->withTimestamps()
        );
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
