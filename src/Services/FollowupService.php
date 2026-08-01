<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Events\LeadHubFollowupCompleted;
use Goldnead\Leadhub\Events\LeadHubFollowupSet;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Followup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FollowupService
{
    public function __construct(
        protected FollowupRepository $followups,
        protected TimelineService $timeline,
    ) {}

    public function set(Contact $contact, Carbon|string $dueAt, ?string $note = null, ?string $createdBy = null): Followup
    {
        $dueAt = $dueAt instanceof Carbon ? $dueAt : Carbon::parse($dueAt);

        $this->followups->removeActiveFor($contact);

        $followup = $this->followups->create($contact, $dueAt, $note, $createdBy);

        $this->timeline->recordFollowupSet($contact, $dueAt);
        event(new LeadHubFollowupSet($contact, null, ['followup_id' => $followup->id]));

        return $followup;
    }

    public function update(Followup $followup, Carbon|string|null $dueAt = null, ?string $note = null): Followup
    {
        $dueAtParsed = $dueAt === null
            ? null
            : ($dueAt instanceof Carbon ? $dueAt : Carbon::parse($dueAt));

        return $this->followups->update($followup, $dueAtParsed, $note);
    }

    public function complete(Followup $followup, ?string $completedBy = null): Followup
    {
        if ($followup->isCompleted()) {
            return $followup;
        }

        $completed = $this->followups->markCompleted($followup, $completedBy);

        $contact = $followup->contact ?? null;
        if ($contact) {
            $this->timeline->recordFollowupCompleted($contact);
            event(new LeadHubFollowupCompleted($contact, null, ['followup_id' => $completed->id]));
        }

        return $completed;
    }

    public function remove(Followup $followup): void
    {
        $contact = $followup->contact ?? null;

        $this->followups->delete($followup);

        if ($contact) {
            $this->timeline->recordFollowupRemoved($contact);
        }
    }

    public function dueToday(?int $limit = null): Collection
    {
        return $this->followups->dueToday($limit);
    }

    public function overdue(?int $limit = null): Collection
    {
        return $this->followups->overdue($limit);
    }

    public function upcoming(?int $limit = null): Collection
    {
        return $this->followups->upcoming($limit);
    }

    public function countDueToday(): int
    {
        return $this->followups->countDueToday();
    }

    public function countOverdue(): int
    {
        return $this->followups->countOverdue();
    }
}
