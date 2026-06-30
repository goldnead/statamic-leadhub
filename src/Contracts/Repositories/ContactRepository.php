<?php

namespace Goldnead\Leadhub\Contracts\Repositories;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ContactRepository
{
    public function find(int|string $id): ?Contact;

    public function findByUuid(string $uuid): ?Contact;

    public function findByEmailNormalized(string $emailNormalized): ?Contact;

    public function findByPhoneNormalized(string $phoneNormalized): ?Contact;

    public function create(array $attributes): Contact;

    public function save(Contact $contact): Contact;

    public function delete(Contact $contact): void;

    public function archive(Contact $contact): void;

    public function restore(Contact $contact): void;

    public function touchActivity(Contact $contact): void;

    /**
     * Paginated, filtered contact list for the CP index page.
     *
     * Supported filter keys: status, source_form, tag_id, from, to,
     * has_followup ('has'|'today'|'overdue'), search, archived (bool),
     * sort ('created_at'|'last_activity_at'|'status'), direction ('asc'|'desc').
     */
    public function paginate(array $filters = [], int $perPage = 25, int $page = 1): LengthAwarePaginator;

    /** Recent active contacts for the dashboard. */
    public function recent(int $limit = 5): Collection;

    /** Total contacts created within the last $days, excluding archived. */
    public function countNewSince(int $days): int;

    /** Total active contacts grouped by status. Returns [statusKey => count]. */
    public function countByStatus(): array;

    public function countWithStatus(string $status): int;

    /** Available source_form values, drawn from enabled FormMappings or contact data. */
    public function distinctSources(): array;
}
