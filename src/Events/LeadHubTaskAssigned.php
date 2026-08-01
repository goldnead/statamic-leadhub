<?php

namespace Goldnead\Leadhub\Events;

use Goldnead\Leadhub\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A task changed hands.
 *
 * Fires on every reassignment, including one that clears the assignee, and
 * including one on a task that has no contact — the timeline entry needs a
 * contact, this event does not.
 *
 * `$from` and `$to` are the raw assignee ids, never the display labels: an id
 * is what a consumer can act on, and a label is what a screen shows.
 */
class LeadHubTaskAssigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Task $task,
        public ?string $from = null,
        public ?string $to = null,
        public ?array $actor = null,
        public array $metadata = [],
    ) {}
}
