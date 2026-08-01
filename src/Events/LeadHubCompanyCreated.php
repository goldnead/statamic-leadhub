<?php

namespace Goldnead\Leadhub\Events;

use Goldnead\Leadhub\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadHubCompanyCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Company $company,
        public ?array $actor = null,
        public array $metadata = [],
    ) {}
}
