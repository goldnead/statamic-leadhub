<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FlatFileEventRepository implements EventRepository
{
    public function __construct(
        protected FileStore $files,
        protected ModelHydrator $hydrator,
    ) {}

    public function record(
        Contact $contact,
        string $type,
        ?string $summary = null,
        array $payload = [],
        ?string $actorType = null,
        ?string $actorId = null,
    ): Event {
        $uuid = (string) Str::uuid();
        $now = now()->toIso8601String();

        $record = [
            'id' => $uuid,
            'uuid' => $uuid,
            'contact_id' => (string) $contact->id,
            'type' => $type,
            'summary' => $summary,
            'payload' => $payload,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->files->appendJsonLine($this->pathFor($contact->id), $record);

        return $this->hydrator->event($record);
    }

    public function recordSource(Contact $contact, string $type, array $attributes = []): Event
    {
        $uuid = (string) Str::uuid();
        $now = now()->toIso8601String();
        $occurredAt = $attributes['occurred_at'] ?? null;

        $record = [
            'id' => $uuid,
            'uuid' => $uuid,
            'contact_id' => (string) $contact->id,
            'type' => $type,
            'summary' => $attributes['summary'] ?? null,
            'payload' => $attributes['payload'] ?? [],
            'actor_type' => $attributes['actor_type'] ?? null,
            'actor_id' => $attributes['actor_id'] ?? null,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => isset($attributes['source_id']) ? (string) $attributes['source_id'] : null,
            'dedupe_key' => $attributes['dedupe_key'] ?? null,
            'occurred_at' => $occurredAt instanceof \DateTimeInterface
                ? $occurredAt->format(\DATE_ATOM)
                : $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->files->appendJsonLine($this->pathFor($contact->id), $record);

        return $this->hydrator->event($record);
    }

    public function findByDedupeKey(string $dedupeKey): ?Event
    {
        foreach ($this->files->glob('events/*.jsonl') as $relative) {
            foreach ($this->files->readJsonLines($relative) as $record) {
                if (($record['dedupe_key'] ?? null) === $dedupeKey) {
                    return $this->hydrator->event($record);
                }
            }
        }

        return null;
    }

    public function find(int|string $id): ?Event
    {
        // Scan all event files. Acceptable at flat-file scale.
        foreach ($this->files->glob('events/*.jsonl') as $relative) {
            foreach ($this->files->readJsonLines($relative) as $record) {
                if (($record['id'] ?? null) === (string) $id || ($record['uuid'] ?? null) === (string) $id) {
                    return $this->hydrator->event($record);
                }
            }
        }

        return null;
    }

    public function forContact(Contact|int|string $contact, int $perPage = 30, int $page = 1): LengthAwarePaginator
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        $records = $this->files->readJsonLines($this->pathFor($contactId));

        // Newest first.
        usort($records, fn ($a, $b) => strcmp(
            $b['created_at'] ?? '',
            $a['created_at'] ?? '',
        ));

        $total = count($records);
        $sliced = array_slice($records, ($page - 1) * $perPage, $perPage);

        $items = collect($sliced)->map(fn ($r) => $this->hydrator->event($r));

        return new LaravelLengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => request()?->url() ?? '/'],
        );
    }

    public function latest(int $limit = 8): Collection
    {
        $all = [];

        foreach ($this->files->glob('events/*.jsonl') as $relative) {
            foreach ($this->files->readJsonLines($relative) as $record) {
                $all[] = $record;
            }
        }

        usort($all, fn ($a, $b) => strcmp(
            $b['created_at'] ?? '',
            $a['created_at'] ?? '',
        ));

        return collect(array_slice($all, 0, $limit))
            ->map(fn ($r) => $this->hydrator->event($r));
    }

    public function countOfType(Contact|int|string $contact, string $type): int
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $records = $this->files->readJsonLines($this->pathFor($contactId));

        return count(array_filter($records, fn ($r) => ($r['type'] ?? null) === $type));
    }

    public function deleteForContact(Contact|int|string $contact): void
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $this->files->delete($this->pathFor($contactId));
    }

    protected function pathFor(int|string $contactId): string
    {
        return 'events/'.$contactId.'.jsonl';
    }
}
