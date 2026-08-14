<?php

namespace Goldnead\Leadhub\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One setting an operator changed from the Control Panel.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 *
 * No `HasBrand`, on purpose — see the migration for why these belong to the
 * installation rather than to a brand. Every other model in this addon is
 * brand-scoped, so the absence is stated here rather than left to be noticed.
 */
class Setting extends Model
{
    protected $table = 'leadhub_settings';

    protected $fillable = ['key', 'value'];

    /** @var array<string, string> */
    protected $casts = [
        // `json`, not `array`: the values are not all lists. A boolean stored
        // through the `array` cast comes back wrapped, and the integers would
        // come back as strings — which matters, because the whole "back to
        // default" rule is a strict comparison against the config file.
        'value' => 'json',
    ];
}
