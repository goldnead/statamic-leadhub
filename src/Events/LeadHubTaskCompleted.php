<?php

namespace Goldnead\Leadhub\Events;

use Goldnead\Leadhub\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadHubTaskCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Task $task,
        public ?array $actor = null,
        public array $metadata = [],
    ) {}
}
