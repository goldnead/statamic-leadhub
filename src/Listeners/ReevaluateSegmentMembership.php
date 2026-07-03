<?php

namespace Goldnead\Leadhub\Listeners;

use Goldnead\Leadhub\Events\LeadHubEvent;
use Goldnead\Leadhub\Services\SegmentService;
use Illuminate\Support\Facades\Log;

/**
 * Reactively re-evaluates a contact's segment membership whenever the contact
 * is mutated (created, updated, status changed, tag added/removed, source
 * ingested). Fail-safe: a segment error must never break the host pipeline.
 *
 * The SegmentService's per-contact depth guard prevents infinite cascades when
 * a consumer reacts to the resulting enter/leave events by mutating the same
 * contact again.
 */
class ReevaluateSegmentMembership
{
    public function __construct(protected SegmentService $segments)
    {
    }

    public function handle(LeadHubEvent $event): void
    {
        // Skip the segment events themselves — they are the reactive OUTPUT of
        // this listener; re-entrancy is additionally capped by the depth guard.
        if ($event instanceof \Goldnead\Leadhub\Events\LeadHubContactEnteredSegment
            || $event instanceof \Goldnead\Leadhub\Events\LeadHubContactLeftSegment) {
            return;
        }

        try {
            $this->segments->reevaluateContact($event->contact);
        } catch (\Throwable $e) {
            Log::warning('LeadHub segment re-evaluation failed: '.$e->getMessage());
        }
    }
}
