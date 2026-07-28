<?php

namespace Goldnead\Leadhub\Repositories\Eloquent;

use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Segment;
use Goldnead\Leadhub\Support\PivotBrand;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Segment membership lives in `leadhub_segment_contact` and is written and read
 * here through raw query-builder calls, not through the Eloquent relation —
 * membership is rebuilt in bulk and the relation is too slow for it.
 *
 * That is also why the pivot's denormalized `brand_id` needs explicit work in
 * this class: `Models\Concerns\ScopesPivotToBrand` closes the same gap for the
 * pivots that *are* reached through a relation, but there is no relation here to
 * hang it on. Every query below therefore stamps the column on write and
 * filters on it on read, using the same resolution (`Support\PivotBrand`) as the
 * relation path, so the two cannot drift apart.
 */
class EloquentSegmentRepository implements SegmentRepository
{
    public function find(int|string $id): ?Segment
    {
        if (is_string($id) && ! ctype_digit($id)) {
            return Segment::query()->where('uuid', $id)->first();
        }

        return Segment::query()->find($id);
    }

    public function findByHandle(string $handle): ?Segment
    {
        return Segment::query()->where('handle', $handle)->first();
    }

    public function create(array $attributes): Segment
    {
        if (empty($attributes['handle']) && ! empty($attributes['name'])) {
            $attributes['handle'] = Str::slug($attributes['name']);
        }

        return Segment::query()->create($attributes);
    }

    public function update(Segment $segment, array $attributes): Segment
    {
        if (isset($attributes['name']) && ! isset($attributes['handle'])) {
            // Keep an existing handle stable; only auto-derive when absent.
            $attributes['handle'] = $segment->handle ?: Str::slug($attributes['name']);
        }

        $segment->fill($attributes);
        $segment->save();

        return $segment;
    }

    public function delete(Segment $segment): void
    {
        $segment->delete();
    }

    public function all(): Collection
    {
        return Segment::query()->orderBy('name')->get();
    }

    public function paginate(int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        return Segment::query()
            ->withCount('contacts as members_count')
            ->orderBy('name')
            ->paginate($perPage, page: $page);
    }

    public function memberIds(Segment $segment): array
    {
        return $segment->contacts()->pluck('leadhub_contacts.uuid')->map(fn ($u) => (string) $u)->all();
    }

    public function membersCount(Segment $segment): int
    {
        return $this->membership($segment)->count();
    }

    public function hasContact(Segment $segment, Contact|int|string $contact): bool
    {
        $contactId = $this->contactId($contact);

        if ($contactId === null) {
            return false;
        }

        return $this->membership($segment)->where('contact_id', $contactId)->exists();
    }

    public function addContact(Segment $segment, Contact|int|string $contact): void
    {
        $contactId = $this->contactId($contact);

        if ($contactId === null) {
            return;
        }

        // The guard is deliberately unfiltered: the pivot's primary key is
        // (segment_id, contact_id), so a row belonging to another brand would
        // make a second insert fail rather than produce a second row. Such a row
        // is left exactly as it is — re-stamping it would launder a cross-brand
        // membership into this brand, which is the thing the column exists to
        // prevent.
        $exists = DB::table('leadhub_segment_contact')
            ->where('segment_id', $segment->id)
            ->where('contact_id', $contactId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('leadhub_segment_contact')->insert([
            'segment_id' => $segment->id,
            'contact_id' => $contactId,
            'entered_at' => now(),
            'brand_id' => $this->brandId($segment),
        ]);
    }

    public function removeContact(Segment $segment, Contact|int|string $contact): void
    {
        $contactId = $this->contactId($contact);

        if ($contactId === null) {
            return;
        }

        // Filtered like the reads: a caller that cannot see a membership row
        // must not be able to delete it either.
        $this->membership($segment)->where('contact_id', $contactId)->delete();
    }

    public function handlesForContact(Contact|int|string $contact): array
    {
        $contactId = $this->contactId($contact);

        if ($contactId === null) {
            return [];
        }

        $query = DB::table('leadhub_segment_contact')
            ->join('leadhub_segments', 'leadhub_segments.id', '=', 'leadhub_segment_contact.segment_id')
            ->where('leadhub_segment_contact.contact_id', $contactId);

        // The contact carries the brand here; there is no segment in hand.
        $brandId = $this->brandId($contact instanceof Contact ? $contact : null);

        if ($brandId !== null) {
            $query->where('leadhub_segment_contact.brand_id', $brandId);
        }

        return $query->pluck('leadhub_segments.handle')->all();
    }

    /**
     * A membership query for one segment, constrained to the segment's brand.
     *
     * Unconstrained only when no brand can be resolved at all (brand-context
     * absent, or a fresh install mid-migration) — filtering on a column nothing
     * has stamped yet would hide every row instead of protecting anything.
     */
    protected function membership(Segment $segment): QueryBuilder
    {
        $query = DB::table('leadhub_segment_contact')->where('segment_id', $segment->id);

        $brandId = $this->brandId($segment);

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        }

        return $query;
    }

    /** The brand of the record owning the pivot row, falling back to the request context. */
    protected function brandId(?Model $owner): ?int
    {
        return PivotBrand::for($owner);
    }

    /**
     * Resolve the integer primary key of a contact (the pivot uses the numeric
     * id). Accepts a Contact, an int id, or a UUID string.
     */
    protected function contactId(Contact|int|string $contact): ?int
    {
        if ($contact instanceof Contact) {
            return (int) $contact->getKey();
        }

        if (is_int($contact) || ctype_digit((string) $contact)) {
            return (int) $contact;
        }

        $id = Contact::query()->where('uuid', $contact)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
