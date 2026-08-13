<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Sending\SaidRecently;
use Goldnead\Leadhub\Contracts\SenderIdentityResolver;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Notifications\FollowupDigestNotification;
use Goldnead\Leadhub\Notifications\LeadAssignedNotification;
use Goldnead\Leadhub\Notifications\NewLeadNotification;
use Goldnead\Leadhub\Sending\BrandMailer;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Resolves recipients and dispatches LeadHub e-mail notifications. All sends
 * are fail-safe — a mail/transport error must never break a form submission
 * or a CP action.
 *
 * **Every one of them leaves as the brand it belongs to** (13.08.2026). Until
 * then this class called `Notification::route('mail', …)->notify()` directly,
 * which is the process-wide default mailer with the process-wide From, the same
 * for every brand. On a host where each brand's sending domain is verified in
 * its own relay account — Scaleway TEM, Postmark, SES with a verified identity
 * — that pairs one brand's transport with another brand's address, and the
 * relay either refuses it or rewrites it. These are internal mails to staff, so
 * no customer sees the wrong name; what a customer does see is a lead that
 * nobody followed up, because the alert never arrived and nothing said so.
 *
 * The identity comes from `brands.settings.mail` through
 * {@see SenderIdentityResolver}, and
 * {@see BrandMailer} is the one door. An installation whose brands declare no
 * mail settings — every single-brand install — resolves the config identity and
 * sends exactly as it did before.
 */
class LeadHubNotifier
{
    public function __construct(protected UserDirectory $users, protected BrandMailer $mailer) {}

    public function newLead(Contact $contact): bool
    {
        if (! $this->enabled() || ! config('leadhub.notifications.new_lead', true)) {
            return false;
        }

        // An owner (if already assigned) takes precedence, otherwise the
        // configured default recipients receive the new-lead alert.
        $ownerEmail = $this->users->email($contact->assigned_to);
        $recipients = $ownerEmail ? [$ownerEmail] : $this->defaultRecipients();

        return $this->send($recipients, new NewLeadNotification($contact), $this->brandOf($contact));
    }

    public function assigned(Contact $contact): bool
    {
        if (! $this->enabled() || ! config('leadhub.notifications.on_assignment', true)) {
            return false;
        }

        $email = $this->users->email($contact->assigned_to);

        return $this->send($email ? [$email] : [], new LeadAssignedNotification($contact), $this->brandOf($contact));
    }

    /**
     * The digest of one brand's follow-ups.
     *
     * No brand id: the command that calls this runs inside `forEachBrand()`, so
     * the brand in context is the brand whose follow-ups were just collected.
     * Passing the contacts' own brand ids here would be the same answer taken
     * the long way round, and wrong the moment the scope ever let a foreign
     * contact through.
     */
    public function digest(array $recipients, array $items, int $overdueCount, int $todayCount): bool
    {
        return $this->send($recipients, new FollowupDigestNotification($items, $overdueCount, $todayCount));
    }

    /**
     * May the brand in context send at all?
     *
     * For callers that decide before they act rather than after they failed —
     * the follow-up digest reports how many digests it sent, and a run that
     * reports "Sent 12" while twelve refusals went to the log is worse than a
     * run that says it could not send.
     */
    public function maySend(?int $brandId = null): bool
    {
        return $this->mailer->maySend($brandId);
    }

    public function defaultRecipients(): array
    {
        return $this->cleanEmails((array) config('leadhub.notifications.recipients', []));
    }

    protected function enabled(): bool
    {
        return (bool) config('leadhub.notifications.enabled', false);
    }

    /**
     * @return bool whether it went out
     */
    protected function send(array $recipients, LaravelNotification $notification, ?int $brandId = null): bool
    {
        $recipients = $this->cleanEmails($recipients);
        if (empty($recipients)) {
            return false;
        }

        try {
            return $this->mailer->notify($brandId, $recipients, $notification);
        } catch (LogicException $e) {
            // NOT swallowed with the transport errors. This one is the door
            // saying a notification class cannot carry a brand identity, which
            // is a programming error and means that class will never send
            // anything again. The fail-safe below exists so a dead SMTP host
            // cannot roll back a form submission; hiding a permanent, silent
            // outage behind the same warning is the opposite of fail-safe. It
            // still does not propagate — the submission must survive — but it
            // is reported at the level the rest of this layer refuses at.
            report($e);
            Log::error('[LeadHub] '.$e->getMessage());

            return false;
        } catch (\Throwable $e) {
            Log::warning('[LeadHub] Notification failed', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The brand a contact belongs to, or null on an install that has no brands.
     *
     * Explicit rather than "whatever is in context": a new lead is created by a
     * form submission, and a queued listener or a console import may have no
     * brand in context by the time this runs. The contact row knows.
     */
    protected function brandOf(Contact $contact): ?int
    {
        $brandId = $contact->brand_id ?? null;

        if (is_numeric($brandId)) {
            return (int) $brandId;
        }

        // On a multi-brand install this is a half-migrated row, and it is
        // exactly the state `leadhub:brands:integrity` reports. Falling through
        // to the host identity is the right thing to do — an alert under the
        // wrong name still beats no alert — but it must not be silent, because
        // the resolver's own "brand declares no mail settings" warning cannot
        // fire for a brand it never got. Once per window, like everything else
        // in this layer.
        $this->sayUnbranded($contact);

        return null;
    }

    protected function sayUnbranded(Contact $contact): void
    {
        try {
            if (! BrandContext::multiBrandEnabled()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        if (! SaidRecently::shouldSay('leadhub:unbranded-contact')) {
            return;
        }

        Log::warning(
            '[LeadHub] A contact carries no brand_id on a multi-brand install, so its staff alert '
            .'goes out under the host-wide from-address — on this host, another brand\'s identity. '
            .'Run `leadhub:brands:integrity`.',
            ['contact' => $contact->getAttribute('uuid')],
        );
    }

    protected function cleanEmails(array $emails): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($e) => is_string($e) ? trim($e) : null,
            $emails,
        ), fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL))));
    }
}
