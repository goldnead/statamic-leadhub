<?php

namespace Goldnead\Leadhub\Contracts;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\SyncResult;

/**
 * A CRM destination LeadHub can push contacts to (HubSpot, Brevo, a generic
 * webhook, …). Implementations are stateless and constructed by the
 * DestinationManager from a config block.
 */
interface CrmDestination
{
    /**
     * Machine driver name (hubspot, brevo, webhook, …).
     */
    public function driver(): string;

    /**
     * Create or update the contact in the remote system.
     */
    public function push(Contact $contact): SyncResult;
}
