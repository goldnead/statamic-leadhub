<?php

namespace Goldnead\Leadhub\Repositories\Eloquent;

use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Segment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        return DB::table('leadhub_segment_contact')->where('segment_id', $segment->id)->count();
    }

    public function hasContact(Segment $segment, Contact|int|string $contact): bool
    {
        $contactId = $this->contactId($contact);

        if ($contactId === null) {
            return false;
        }

        return DB::table('leadhub_segment_contact')
            ->where('segment_id', $segment->id)
            ->where('contact_id', $contactId)
            ->exists();
    }

    public function addContact(Segment $segment, Contact|int|string $contact): void
    {
        $contactId = $this->contactId($contact);

        if ($contactId === null || $this->hasContact($segment, $contactId)) {
            return;
        }

        DB::table('leadhub_segment_contact')->insert([
            'segment_id' => $segment->id,
            'contact_id' => $contactId,
            'entered_at' => now(),
        ]);
    }

    public function removeContact(Segment $segment, Contact|int|string $contact): void
    {
        $contactId = $this->contactId($contact);

        if ($contactId === null) {
            return;
        }

        DB::table('leadhub_segment_contact')
            ->where('segment_id', $segment->id)
            ->where('contact_id', $contactId)
            ->delete();
    }

    public function handlesForContact(Contact|int|string $contact): array
    {
        $contactId = $this->contactId($contact);

        if ($contactId === null) {
            return [];
        }

        return DB::table('leadhub_segment_contact')
            ->join('leadhub_segments', 'leadhub_segments.id', '=', 'leadhub_segment_contact.segment_id')
            ->where('leadhub_segment_contact.contact_id', $contactId)
            ->pluck('leadhub_segments.handle')
            ->all();
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
