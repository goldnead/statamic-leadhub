<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\FollowupService;
use Goldnead\Leadhub\Services\LeadHubNotifier;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Console\Command;

/**
 * Runs once per brand. Without a brand context the multi-brand scope fails
 * closed, no follow-up is visible, and the digest reports "No due or overdue
 * follow-ups" — indistinguishable from a genuinely empty day, and silent for
 * as long as nobody checks.
 *
 * Owners are per brand too, so bucketing recipients across brands in one pass
 * would be wrong even if the scope allowed it.
 */
class SendFollowupDigestCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'leadhub:followups:digest
        {--brand= : Restrict to a single brand (handle or id)}';

    protected $description = 'Email each owner a digest of their due and overdue LeadHub follow-ups.';

    public function handle(): int
    {
        if (! config('leadhub.notifications.enabled', false) || ! config('leadhub.notifications.digest.enabled', true)) {
            $this->info('Follow-up digest is disabled.');

            return self::SUCCESS;
        }

        return $this->forEachBrand(fn () => $this->digestCurrentBrand());
    }

    protected function digestCurrentBrand(): int
    {
        $followups = app(FollowupService::class);
        $contacts = app(ContactRepository::class);
        $users = app(UserDirectory::class);
        $notifier = app(LeadHubNotifier::class);

        // Bucket items per recipient (owner email, or the configured fallback
        // recipients for unassigned contacts).
        $fallback = $this->fallbackRecipients($notifier);
        $buckets = [];

        $collect = function ($followup, bool $overdue) use ($contacts, $users, $fallback, &$buckets) {
            // A follow-up with no contact cannot be reported to anybody. The
            // repository contract types `find()` as string|int, so passing the
            // null straight through was a fatal TypeError rather than a
            // skipped row — one malformed record took the whole digest down.
            if (blank($followup->contact_id)) {
                return;
            }

            $contact = $contacts->find($followup->contact_id);
            if (! $contact instanceof Contact) {
                return;
            }

            $item = [
                'name' => $contact->displayName(),
                'due_at' => optional($followup->due_at)->format('Y-m-d H:i') ?? (string) $followup->due_at,
                'overdue' => $overdue,
                'url' => cp_route('leadhub.contacts.show', $contact->uuid),
                'note' => $followup->note,
            ];

            $ownerEmail = $users->email($contact->assigned_to);
            $targets = $ownerEmail ? [$ownerEmail] : $fallback;

            foreach ($targets as $email) {
                $buckets[$email]['items'][] = $item;
                $buckets[$email]['overdue'] = ($buckets[$email]['overdue'] ?? 0) + ($overdue ? 1 : 0);
                $buckets[$email]['today'] = ($buckets[$email]['today'] ?? 0) + ($overdue ? 0 : 1);
            }
        };

        foreach ($followups->overdue() as $f) {
            $collect($f, true);
        }
        foreach ($followups->dueToday() as $f) {
            $collect($f, false);
        }

        if (empty($buckets)) {
            $this->info('No due or overdue follow-ups — nothing to send.');

            return self::SUCCESS;
        }

        // Asked before the loop, not discovered inside it. A brand that
        // declares `settings.mail` without a from-address, or names a mailer
        // `config/mail.php` does not define, sends nothing at all — and this
        // command is the one place that afterwards claims a number of digests
        // as delivered. Finding out per recipient would leave that claim
        // standing while every send failed into the log. The refusal itself is
        // logged at error level by the resolver; the line here is so that
        // whoever ran the command sees it too.
        //
        // SUCCESS on purpose: `forEachBrand()` stops at the first non-zero exit,
        // and one unconfigured brand must not cost the others their digest.
        if (! $notifier->maySend()) {
            $this->warn('This brand has no usable sender identity — no follow-up digest was sent. See the log.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($buckets as $email => $bucket) {
            $sent += $notifier->digest(
                [$email],
                $bucket['items'],
                (int) ($bucket['overdue'] ?? 0),
                (int) ($bucket['today'] ?? 0),
            ) ? 1 : 0;
        }

        // The number that went out, not the number that was assembled.
        $this->info('Sent '.$sent.' follow-up digest(s).');

        return self::SUCCESS;
    }

    protected function fallbackRecipients(LeadHubNotifier $notifier): array
    {
        $configured = (array) config('leadhub.notifications.digest.fallback_recipients', []);
        $configured = array_values(array_filter(array_map('trim', $configured)));

        return $configured ?: $notifier->defaultRecipients();
    }
}
