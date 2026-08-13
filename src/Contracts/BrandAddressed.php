<?php

namespace Goldnead\Leadhub\Contracts;

use Goldnead\BrandContext\Sending\BrandMailer as BrandContextMailer;
use Goldnead\BrandContext\Sending\SenderIdentity;
use Goldnead\Leadhub\Notifications\Concerns\SendsAsBrand;
use Goldnead\Leadhub\Sending\BrandMailer;

/**
 * A Laravel notification that can carry a brand's sender identity.
 *
 * LeadHub's staff mail is not a `Mailable` but a `Notification`, so it cannot
 * go through {@see BrandContextMailer::send()}, which takes the identity and
 * puts it on a mailable. The `MailMessage` a notification returns only exists
 * inside its own `toMail()`, and that is the only place the address and the
 * mailer can be set.
 *
 * Hence this interface: {@see BrandMailer::notify()} hands the resolved
 * identity to the notification before dispatching it, and refuses outright to
 * dispatch one that cannot take it. Without that refusal a
 * new notification class that forgets to stamp its message would send under
 * the host-wide From and nothing would turn red — which is the exact failure
 * this whole layer exists to prevent.
 *
 * Implement with {@see SendsAsBrand}, and end `toMail()` with
 * `return $this->asBrand($message);`.
 */
interface BrandAddressed
{
    /**
     * Take the identity this notification is to go out under.
     *
     * Never resolves anything itself: the identity arrives from the one door,
     * already checked, or not at all.
     */
    public function sendAs(SenderIdentity $identity): static;
}
