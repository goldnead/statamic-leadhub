<?php

namespace Goldnead\Leadhub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageTransition extends Model
{
    protected $table = 'leadhub_stage_transitions';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'to_stage_id');
    }
}
