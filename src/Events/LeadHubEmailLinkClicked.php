<?php

namespace Goldnead\Leadhub\Events;

/**
 * Fired when a contact clicks a tracked link in a marketing email.
 *
 * Dispatched by the public tracking controller AFTER consent + dedupe gates
 * pass. Carries `metadata['type'] => self::TYPE` so ScoringService maps it to
 * the configured point value (leadhub.scoring.events.email_link_clicked).
 *
 * Only ever dispatched for contacts with marketing consent — the GDPR gate
 * lives in the controller, never here.
 */
class LeadHubEmailLinkClicked extends LeadHubEvent
{
    /** Canonical activity/event type string used for scoring + timeline. */
    public const TYPE = 'email_link_clicked';
}
