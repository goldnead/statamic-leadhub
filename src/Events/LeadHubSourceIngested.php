<?php

namespace Goldnead\Leadhub\Events;

/**
 * Fired after a generic (non-form) source event has been ingested into a
 * contact's timeline. Metadata carries: type, source_type, source_id,
 * dedupe_key, was_created, event_id.
 */
class LeadHubSourceIngested extends LeadHubEvent
{
}
