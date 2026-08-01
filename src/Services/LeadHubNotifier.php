<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Notifications\FollowupDigestNotification;
use Goldnead\Leadhub\Notifications\LeadAssignedNotification;
use Goldnead\Leadhub\Notifications\NewLeadNotification;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Resolves recipients and dispatches LeadHub e-mail notifications. All sends
 * are fail-safe — a mail/transport error must never break a form submission
 * or a CP action.
 */
class LeadHubNotifier
{
    public function __construct(protected UserDirectory $users) {}

    public function newLead(Contact $contact): void
    {
        if (! $this->enabled() || ! config('leadhub.notifications.new_lead', true)) {
            return;
        }

        // An owner (if already assigned) takes precedence, otherwise the
        // configured default recipients receive the new-lead alert.
        $ownerEmail = $this->users->email($contact->assigned_to);
        $recipients = $ownerEmail ? [$ownerEmail] : $this->defaultRecipients();

        $this->send($recipients, new NewLeadNotification($contact));
    }

    public function assigned(Contact $contact): void
    {
        if (! $this->enabled() || ! config('leadhub.notifications.on_assignment', true)) {
            return;
        }

        $email = $this->users->email($contact->assigned_to);
        $this->send($email ? [$email] : [], new LeadAssignedNotification($contact));
    }

    public function digest(array $recipients, array $items, int $overdueCount, int $todayCount): void
    {
        $this->send($recipients, new FollowupDigestNotification($items, $overdueCount, $todayCount));
    }

    public function defaultRecipients(): array
    {
        return $this->cleanEmails((array) config('leadhub.notifications.recipients', []));
    }

    protected function enabled(): bool
    {
        return (bool) config('leadhub.notifications.enabled', false);
    }

    protected function send(array $recipients, LaravelNotification $notification): void
    {
        $recipients = $this->cleanEmails($recipients);
        if (empty($recipients)) {
            return;
        }

        try {
            Notification::route('mail', $recipients)->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('[LeadHub] Notification failed', ['message' => $e->getMessage()]);
        }
    }

    protected function cleanEmails(array $emails): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($e) => is_string($e) ? trim($e) : null,
            $emails,
        ), fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL))));
    }
}
