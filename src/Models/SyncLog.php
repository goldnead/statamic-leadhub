<?php

namespace Goldnead\Leadhub\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $table = 'leadhub_sync_logs';

    protected $guarded = [];

    protected $casts = [
        'response_code' => 'integer',
    ];

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';
}
