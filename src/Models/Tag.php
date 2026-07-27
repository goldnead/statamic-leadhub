<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Database\Factories\TagFactory;
use Goldnead\Leadhub\Models\Concerns\ScopesPivotToBrand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use HasBrand;

    use HasFactory;
    use ScopesPivotToBrand;

    protected $table = 'leadhub_tags';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $tag): void {
            if (empty($tag->uuid)) {
                $tag->uuid = (string) Str::uuid();
            }

            if (empty($tag->slug) && $tag->name) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function contacts(): BelongsToMany
    {
        return $this->scopePivotToOwnBrand(
            $this->belongsToMany(
                Contact::class,
                'leadhub_contact_tag',
                'tag_id',
                'contact_id'
            )->withPivot('brand_id')->withTimestamps()
        );
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}
