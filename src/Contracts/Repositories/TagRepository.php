<?php

namespace Goldnead\Leadhub\Contracts\Repositories;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface TagRepository
{
    public function find(int|string $id): ?Tag;

    public function findBySlug(string $slug): ?Tag;

    public function findOrCreate(string $name, ?string $color = null): Tag;

    public function create(array $attributes): Tag;

    public function update(Tag $tag, array $attributes): Tag;

    public function delete(Tag $tag): void;

    public function all(): Collection;

    /** Paginated list with contacts_count attached on each tag. */
    public function paginate(int $perPage = 50, int $page = 1): LengthAwarePaginator;

    public function attach(Contact $contact, Tag $tag): bool;

    public function detach(Contact $contact, Tag $tag): bool;

    public function isAttached(Contact $contact, Tag $tag): bool;

    /** Tags attached to a given contact. */
    public function forContact(Contact|int|string $contact): Collection;

    /** Detach all tags from a contact (used during deletion). */
    public function detachAllFor(Contact|int|string $contact): void;
}
