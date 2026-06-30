<?php

namespace Goldnead\Leadhub\Repositories\Eloquent;

use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentEventRepository implements EventRepository
{
    public function record(
        Contact $contact,
        string $type,
        ?string $summary = null,
        array $payload = [],
        ?string $actorType = null,
        ?string $actorId = null,
    ): Event {
        $event = new Event([
            'type' => $type,
            'summary' => $summary,
            'payload' => $payload,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ]);

        $event->contact_id = $contact->id;
        $event->save();

        return $event;
    }

    public function recordSource(Contact $contact, string $type, array $attributes = []): Event
    {
        $event = new Event([
            'type' => $type,
            'summary' => $attributes['summary'] ?? null,
            'payload' => $attributes['payload'] ?? [],
            'actor_type' => $attributes['actor_type'] ?? null,
            'actor_id' => $attributes['actor_id'] ?? null,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => isset($attributes['source_id']) ? (string) $attributes['source_id'] : null,
            'dedupe_key' => $attributes['dedupe_key'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);

        $event->contact_id = $contact->id;
        $event->save();

        return $event;
    }

    public function findByDedupeKey(string $dedupeKey): ?Event
    {
        return Event::query()->where('dedupe_key', $dedupeKey)->first();
    }

    public function find(int|string $id): ?Event
    {
        return Event::query()->find($id);
    }

    public function forContact(Contact|int|string $contact, int $perPage = 30, int $page = 1): LengthAwarePaginator
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        return Event::query()
            ->where('contact_id', $contactId)
            ->orderByDesc('created_at')
            ->paginate($perPage, page: $page);
    }

    public function latest(int $limit = 8): Collection
    {
        return Event::query()
            ->with('contact')
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function countOfType(Contact|int|string $contact, string $type): int
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        return Event::query()
            ->where('contact_id', $contactId)
            ->where('type', $type)
            ->count();
    }

    public function deleteForContact(Contact|int|string $contact): void
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        Event::query()->where('contact_id', $contactId)->delete();
    }
}
