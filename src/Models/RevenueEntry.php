<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contributed fact about money a contact paid.
 *
 * Written by whoever knows — a checkout, an invoice, an import, a human. This
 * addon never asks where it came from beyond the `source` label, and it never
 * computes it: LeadHub does not know what a product is.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $contact_id
 * @property string $reference
 * @property string|null $source
 * @property int $amount_cent
 * @property int $refunded_cent
 * @property string $currency
 * @property Carbon $occurred_at
 * @property array|null $meta
 */
class RevenueEntry extends Model
{
    use HasBrand;

    protected $table = 'leadhub_contact_revenue';

    protected $guarded = [];

    protected $casts = [
        'amount_cent' => 'integer',
        'refunded_cent' => 'integer',
        'occurred_at' => 'datetime',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** What is left after refunds. Never below zero, even if more went back than came in. */
    public function netCent(): int
    {
        return max(0, $this->amount_cent - $this->refunded_cent);
    }
}
