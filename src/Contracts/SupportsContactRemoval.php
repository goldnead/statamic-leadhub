<?php

namespace Goldnead\Leadhub\Contracts;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\SyncResult;

/**
 * Optional capability for a CRM destination that can actively remove (or
 * unsubscribe) a contact — used when a contact opts out (do_not_contact).
 */
interface SupportsContactRemoval
{
    public function remove(Contact $contact): SyncResult;
}
