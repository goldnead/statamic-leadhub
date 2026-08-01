<?php

namespace Goldnead\Leadhub\Listeners;

use Goldnead\Leadhub\Events\LeadHubEvent;
use Goldnead\Leadhub\Services\ScoringService;

/**
 * Bumps a contact's engagement_score whenever a scored activity occurs
 * (source ingestion, form submission attached). No-op unless scoring is on.
 */
class ScoreContactOnActivity
{
    public function __construct(protected ScoringService $scoring) {}

    public function handle(LeadHubEvent $event): void
    {
        if (! $this->scoring->enabled()) {
            return;
        }

        // Prefer the granular activity type carried in metadata; fall back to
        // the event class short name.
        $type = $event->metadata['type'] ?? class_basename($event);

        $this->scoring->award($event->contact, (string) $type);
    }
}
