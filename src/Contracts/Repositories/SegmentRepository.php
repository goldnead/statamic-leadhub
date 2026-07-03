<?php

namespace Goldnead\Leadhub\Contracts\Repositories;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Segment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface SegmentRepository
{
    public function find(int|string $id): ?Segment;

    public function findByHandle(string $handle): ?Segment;

    public function create(array $attributes): Segment;

    public function update(Segment $segment, array $attributes): Segment;

    public function delete(Segment $segment): void;

    /** All segments, ordered by name. */
    public function all(): Collection;

    /** Paginated list with members_count attached on each segment. */
    public function paginate(int $perPage = 50, int $page = 1): LengthAwarePaginator;

    /** Materialized member contact ids (UUID strings) for a segment. */
    public function memberIds(Segment $segment): array;

    /** Number of materialized members. */
    public function membersCount(Segment $segment): int;

    /** Is a contact currently a materialized member of the segment? */
    public function hasContact(Segment $segment, Contact|int|string $contact): bool;

    /** Materialize a contact into a segment (idempotent). */
    public function addContact(Segment $segment, Contact|int|string $contact): void;

    /** Remove a contact from a segment's materialized membership (idempotent). */
    public function removeContact(Segment $segment, Contact|int|string $contact): void;

    /** All segment handles a contact currently belongs to. */
    public function handlesForContact(Contact|int|string $contact): array;
}
