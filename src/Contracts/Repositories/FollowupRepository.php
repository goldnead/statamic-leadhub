<?php

namespace Goldnead\Leadhub\Contracts\Repositories;

use DateTimeInterface;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Followup;
use Illuminate\Support\Collection;

interface FollowupRepository
{
    public function find(int|string $id): ?Followup;

    public function create(
        Contact $contact,
        DateTimeInterface $dueAt,
        ?string $note = null,
        ?string $createdBy = null,
    ): Followup;

    public function update(Followup $followup, ?DateTimeInterface $dueAt = null, ?string $note = null): Followup;

    public function markCompleted(Followup $followup, ?string $completedBy = null): Followup;

    public function delete(Followup $followup): void;

    /** Active follow-ups (not completed) for a contact, ordered by due_at. */
    public function activeFor(Contact|int|string $contact): Collection;

    public function activeForOne(Contact|int|string $contact): ?Followup;

    /** Remove (hard delete) all active follow-ups for a contact. */
    public function removeActiveFor(Contact|int|string $contact): int;

    /** Active + due today, with contact eager-loaded, only non-archived contacts. */
    public function dueToday(?int $limit = null): Collection;

    /** Active + due before today, contact eager-loaded, only non-archived. */
    public function overdue(?int $limit = null): Collection;

    /** Active + due after today (upcoming), contact eager-loaded, only non-archived. */
    public function upcoming(?int $limit = null): Collection;

    public function countDueToday(): int;

    public function countOverdue(): int;

    public function deleteForContact(Contact|int|string $contact): void;
}
