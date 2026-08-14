<?php

namespace Goldnead\Leadhub\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded move of a deal from one stage to the next.
 *
 * `from_stage_id` and `to_stage_id` are raw integers with no foreign key on
 * purpose: the history has to survive the deletion of a stage it names, and a
 * constraint would either block that deletion or erase the row. Readers must
 * therefore treat a stage id here as possibly dangling.
 *
 * @property int $id
 * @property int $opportunity_id
 * @property int|null $from_stage_id
 * @property int $to_stage_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $note
 * @property Carbon $occurred_at
 * @property int|null $brand_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Opportunity|null $opportunity
 * @property-read Stage|null $fromStage
 * @property-read Stage|null $toStage
 */
class StageTransition extends Model
{
    use HasBrand;

    protected $table = 'leadhub_stage_transitions';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    /** @return BelongsTo<Opportunity, $this> */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /** @return BelongsTo<Stage, $this> */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'from_stage_id');
    }

    /** @return BelongsTo<Stage, $this> */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'to_stage_id');
    }
}
