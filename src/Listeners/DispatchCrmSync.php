<?php

namespace Goldnead\Leadhub\Listeners;

use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubContactUpdated;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Jobs\SyncContactToCrmJob;

/**
 * Queues a CRM sync when a relevant lead event fires. Registered against the
 * created / updated / status-changed events in the service provider.
 */
class DispatchCrmSync
{
    protected array $map = [
        LeadHubContactCreated::class => 'created',
        LeadHubContactUpdated::class => 'updated',
        LeadHubStatusChanged::class => 'status_changed',
    ];

    public function handle(object $event): void
    {
        if (! config('leadhub.features.crm_destinations', false)) {
            return;
        }

        $trigger = $this->map[$event::class] ?? null;
        if (! $trigger || empty($event->contact?->uuid)) {
            return;
        }

        SyncContactToCrmJob::dispatch((string) $event->contact->uuid, $trigger);
    }
}
