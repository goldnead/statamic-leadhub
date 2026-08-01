<?php

namespace Goldnead\Leadhub\Listeners;

use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Services\LeadHubNotifier;

class SendNewLeadNotification
{
    public function __construct(protected LeadHubNotifier $notifier) {}

    public function handle(LeadHubContactCreated $event): void
    {
        $this->notifier->newLead($event->contact);
    }
}
