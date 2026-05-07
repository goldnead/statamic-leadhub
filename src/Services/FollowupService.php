<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Events\LeadHubFollowupCompleted;
use Goldnead\Leadhub\Events\LeadHubFollowupSet;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Followup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class FollowupService
{
    public function __construct(protected TimelineService $timeline)
    {
    }

    /**
     * Set the active followup for a contact. If one already exists,
     * it is replaced (PRD §7: "Kontakt kann genau einen aktiven Follow-up haben").
     */
    public function set(Contact $contact, Carbon|string $dueAt, ?string $note = null, ?string $createdBy = null): Followup
    {
        $dueAt = $dueAt instanceof Carbon ? $dueAt : Carbon::parse($dueAt);

        // Mark any active followups as removed.
        $contact->followups()
            ->whereNull('completed_at')
            ->get()
            ->each(function (Followup $existing): void {
                $existing->delete();
            });

        $followup = $contact->followups()->create([
            'due_at' => $dueAt,
            'note' => $note,
            'created_by' => $createdBy,
        ]);

        $this->timeline->recordFollowupSet($contact, $dueAt);
        event(new LeadHubFollowupSet($contact, null, ['followup_id' => $followup->id]));

        return $followup->fresh();
    }

    public function update(Followup $followup, Carbon|string|null $dueAt = null, ?string $note = null): Followup
    {
        if ($dueAt !== null) {
            $followup->due_at = $dueAt instanceof Carbon ? $dueAt : Carbon::parse($dueAt);
        }

        if ($note !== null) {
            $followup->note = $note;
        }

        $followup->save();

        return $followup->fresh();
    }

    public function complete(Followup $followup, ?string $completedBy = null): Followup
    {
        if ($followup->isCompleted()) {
            return $followup;
        }

        $followup->completed_at = now();
        $followup->completed_by = $completedBy;
        $followup->save();

        $this->timeline->recordFollowupCompleted($followup->contact);
        event(new LeadHubFollowupCompleted($followup->contact, null, ['followup_id' => $followup->id]));

        return $followup->fresh();
    }

    public function remove(Followup $followup): void
    {
        $contact = $followup->contact;

        $followup->delete();

        $this->timeline->recordFollowupRemoved($contact);
    }

    public function dueToday(): Builder
    {
        return Followup::query()
            ->dueToday()
            ->whereHas('contact', fn (Builder $q) => $q->whereNull('archived_at'));
    }

    public function overdue(): Builder
    {
        return Followup::query()
            ->overdue()
            ->whereHas('contact', fn (Builder $q) => $q->whereNull('archived_at'));
    }
}
