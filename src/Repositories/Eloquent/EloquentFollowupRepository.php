<?php

namespace Goldnead\Leadhub\Repositories\Eloquent;

use DateTimeInterface;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Followup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EloquentFollowupRepository implements FollowupRepository
{
    public function find(int|string $id): ?Followup
    {
        return Followup::query()->find($id);
    }

    public function create(Contact $contact, DateTimeInterface $dueAt, ?string $note = null, ?string $createdBy = null): Followup
    {
        return $contact->followups()->create([
            'due_at' => Carbon::instance($dueAt instanceof Carbon ? $dueAt : Carbon::parse($dueAt->format('Y-m-d H:i:s'))),
            'note' => $note,
            'created_by' => $createdBy,
        ]);
    }

    public function update(Followup $followup, ?DateTimeInterface $dueAt = null, ?string $note = null): Followup
    {
        if ($dueAt !== null) {
            $followup->due_at = Carbon::instance($dueAt instanceof Carbon ? $dueAt : Carbon::parse($dueAt->format('Y-m-d H:i:s')));
        }
        if ($note !== null) {
            $followup->note = $note;
        }
        $followup->save();

        return $followup->fresh();
    }

    public function markCompleted(Followup $followup, ?string $completedBy = null): Followup
    {
        $followup->completed_at = now();
        $followup->completed_by = $completedBy;
        $followup->save();

        return $followup->fresh();
    }

    public function delete(Followup $followup): void
    {
        $followup->delete();
    }

    public function activeFor(Contact|int|string $contact): Collection
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        return Followup::query()
            ->where('contact_id', $contactId)
            ->whereNull('completed_at')
            ->orderBy('due_at')
            ->get();
    }

    public function activeForOne(Contact|int|string $contact): ?Followup
    {
        return $this->activeFor($contact)->first();
    }

    public function removeActiveFor(Contact|int|string $contact): int
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        return Followup::query()
            ->where('contact_id', $contactId)
            ->whereNull('completed_at')
            ->delete();
    }

    public function dueToday(?int $limit = null): Collection
    {
        $query = Followup::query()
            ->dueToday()
            ->with('contact')
            ->whereHas('contact', fn (Builder $q) => $q->whereNull('archived_at'))
            ->orderBy('due_at');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function overdue(?int $limit = null): Collection
    {
        $query = Followup::query()
            ->overdue()
            ->with('contact')
            ->whereHas('contact', fn (Builder $q) => $q->whereNull('archived_at'))
            ->orderBy('due_at');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function upcoming(?int $limit = null): Collection
    {
        $query = Followup::query()
            ->active()
            ->where('due_at', '>', now()->endOfDay())
            ->with('contact')
            ->whereHas('contact', fn (Builder $q) => $q->whereNull('archived_at'))
            ->orderBy('due_at');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function countDueToday(): int
    {
        return Followup::query()
            ->dueToday()
            ->whereHas('contact', fn (Builder $q) => $q->whereNull('archived_at'))
            ->count();
    }

    public function countOverdue(): int
    {
        return Followup::query()
            ->overdue()
            ->whereHas('contact', fn (Builder $q) => $q->whereNull('archived_at'))
            ->count();
    }

    public function deleteForContact(Contact|int|string $contact): void
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        Followup::query()->where('contact_id', $contactId)->delete();
    }
}
