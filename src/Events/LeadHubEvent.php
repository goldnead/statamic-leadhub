<?php

namespace Goldnead\Leadhub\Events;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class LeadHubEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Contact $contact,
        public ?array $actor = null,
        public array $metadata = []
    ) {}
}
