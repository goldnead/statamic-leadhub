<?php

namespace Goldnead\Leadhub\Events;

/**
 * Fired (by the leadhub:followups:due scheduled command) when an active
 * follow-up is due today or overdue. Metadata carries: followup_id, due_at,
 * state ('due_today' | 'overdue'). Drives the automations
 * `leadhub.lead_follow_up_due` trigger and outbound webhooks.
 */
class LeadHubFollowupDue extends LeadHubEvent
{
}
