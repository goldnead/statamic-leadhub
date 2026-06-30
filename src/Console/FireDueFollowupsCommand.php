<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Events\LeadHubFollowupDue;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\FollowupService;
use Illuminate\Console\Command;

/**
 * Fires a LeadHubFollowupDue event for every active follow-up that is due
 * today or overdue. Drives the automations `leadhub.lead_follow_up_due`
 * trigger and any outbound webhooks bound to that event.
 */
class FireDueFollowupsCommand extends Command
{
    protected $signature = 'leadhub:followups:due';

    protected $description = 'Fire a LeadHubFollowupDue event for each due or overdue follow-up.';

    public function handle(FollowupService $followups, ContactRepository $contacts): int
    {
        $fired = 0;

        $emit = function ($followup, string $state) use ($contacts, &$fired): void {
            $contact = $contacts->find($followup->contact_id);

            if (! $contact instanceof Contact) {
                return;
            }

            event(new LeadHubFollowupDue($contact, null, [
                'followup_id' => $followup->id,
                'due_at' => optional($followup->due_at)->toIso8601String(),
                'state' => $state,
            ]));

            $fired++;
        };

        foreach ($followups->overdue() as $followup) {
            $emit($followup, 'overdue');
        }

        foreach ($followups->dueToday() as $followup) {
            $emit($followup, 'due_today');
        }

        $this->info("Fired {$fired} follow-up due event(s).");

        return self::SUCCESS;
    }
}
