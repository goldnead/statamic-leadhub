<?php

namespace Goldnead\Leadhub\Events;

/**
 * Fired after one contact has been merged into another. The event's $contact
 * is the surviving winner; metadata carries merged_contact_id (the loser) and
 * merged_contact_uuid.
 */
class LeadHubContactsMerged extends LeadHubEvent {}
