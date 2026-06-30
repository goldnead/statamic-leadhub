<?php

namespace Goldnead\Leadhub\Events;

use Goldnead\Leadhub\Models\Opportunity;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadHubOpportunityWon
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Opportunity $opportunity,
        public ?array $actor = null,
        public array $metadata = [],
    ) {
    }
}
