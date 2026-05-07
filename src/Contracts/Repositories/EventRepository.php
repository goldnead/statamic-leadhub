<?php

namespace Goldnead\Leadhub\Contracts\Repositories;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface EventRepository
{
    public function record(
        Contact $contact,
        string $type,
        ?string $summary = null,
        array $payload = [],
        ?string $actorType = null,
        ?string $actorId = null,
    ): Event;

    public function find(int|string $id): ?Event;

    /**
     * Events for a contact, newest first, paginated.
     */
    public function forContact(Contact|int|string $contact, int $perPage = 30, int $page = 1): LengthAwarePaginator;

    /** Most recent events across all contacts (dashboard). */
    public function latest(int $limit = 8): Collection;

    public function countOfType(Contact|int|string $contact, string $type): int;

    /** Delete all events tied to a contact. Used during contact deletion. */
    public function deleteForContact(Contact|int|string $contact): void;
}
