<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Segment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Flat-file segments.
 *
 * Segment definitions live in a single `segments.yaml` (mirrors tags.yaml).
 * Materialized membership is mirrored onto each contact's YAML as a
 * `segment_handles` array — exactly like `tag_ids` — so the flat path never
 * needs an Eloquent relation to answer membership questions.
 */
class FlatFileSegmentRepository implements SegmentRepository
{
    public function __construct(
        protected FileStore $files,
        protected ModelHydrator $hydrator,
    ) {}

    public function find(int|string $id): ?Segment
    {
        $row = $this->loadOne((string) $id);

        return $row ? $this->hydrator->segment($row) : null;
    }

    public function findByHandle(string $handle): ?Segment
    {
        foreach ($this->rows() as $row) {
            if (($row['handle'] ?? null) === $handle) {
                return $this->hydrator->segment($row);
            }
        }

        return null;
    }

    public function create(array $attributes): Segment
    {
        $now = now()->toIso8601String();
        $uuid = $attributes['uuid'] ?? (string) Str::uuid();

        $record = array_merge([
            'id' => $uuid,
            'uuid' => $uuid,
            'handle' => Str::slug($attributes['name'] ?? $attributes['handle'] ?? ''),
            'description' => null,
            'rules' => [],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes);

        // Normalize rules to an array on disk (never a JSON string).
        $record['rules'] = $this->normalizeRules($record['rules']);

        $data = $this->files->readYaml('segments.yaml');
        $data['segments'] = $data['segments'] ?? [];
        $data['segments'][] = $record;
        $this->files->writeYaml('segments.yaml', $data);

        return $this->hydrator->segment($record);
    }

    public function update(Segment $segment, array $attributes): Segment
    {
        $data = $this->files->readYaml('segments.yaml');

        foreach (($data['segments'] ?? []) as $i => $row) {
            if (($row['id'] ?? null) === $segment->id || ($row['uuid'] ?? null) === $segment->uuid) {
                if (array_key_exists('name', $attributes)) {
                    $row['name'] = $attributes['name'];
                    $row['handle'] = $row['handle'] ?? Str::slug($attributes['name']);
                }
                if (array_key_exists('handle', $attributes)) {
                    $row['handle'] = $attributes['handle'];
                }
                if (array_key_exists('description', $attributes)) {
                    $row['description'] = $attributes['description'];
                }
                if (array_key_exists('rules', $attributes)) {
                    $row['rules'] = $this->normalizeRules($attributes['rules']);
                }
                if (array_key_exists('is_active', $attributes)) {
                    $row['is_active'] = (bool) $attributes['is_active'];
                }
                $row['updated_at'] = now()->toIso8601String();
                $data['segments'][$i] = $row;
                $this->files->writeYaml('segments.yaml', $data);

                return $this->hydrator->segment($row);
            }
        }

        return $segment;
    }

    public function delete(Segment $segment): void
    {
        $data = $this->files->readYaml('segments.yaml');
        $data['segments'] = array_values(array_filter(
            $data['segments'] ?? [],
            fn ($row) => ($row['id'] ?? null) !== $segment->id && ($row['uuid'] ?? null) !== $segment->uuid,
        ));
        $this->files->writeYaml('segments.yaml', $data);

        // Detach this segment handle from all contacts.
        foreach ($this->files->glob('contacts/*.yaml') as $relative) {
            $contactData = $this->files->readYaml($relative);
            $handles = (array) ($contactData['segment_handles'] ?? []);
            if (in_array($segment->handle, $handles, true)) {
                $contactData['segment_handles'] = array_values(array_filter(
                    $handles,
                    fn ($h) => (string) $h !== (string) $segment->handle,
                ));
                $this->files->writeYaml($relative, $contactData);
            }
        }
    }

    public function all(): Collection
    {
        $rows = $this->rows();
        usort($rows, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        return collect($rows)->map(fn ($r) => $this->hydrator->segment($r));
    }

    public function paginate(int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        $counts = $this->computeMembersPerHandle();

        $segments = $this->all()->map(function (Segment $s) use ($counts) {
            $s->setAttribute('members_count', $counts[(string) $s->handle] ?? 0);

            return $s;
        });

        $sliced = $segments->slice(($page - 1) * $perPage, $perPage)->values();

        return new LaravelLengthAwarePaginator(
            items: $sliced,
            total: $segments->count(),
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => request()?->url() ?? '/'],
        );
    }

    public function memberIds(Segment $segment): array
    {
        $ids = [];

        foreach ($this->files->glob('contacts/*.yaml') as $relative) {
            $data = $this->files->readYaml($relative);
            $handles = (array) ($data['segment_handles'] ?? []);
            if (in_array($segment->handle, $handles, true) && ! empty($data['uuid'])) {
                $ids[] = (string) $data['uuid'];
            }
        }

        return $ids;
    }

    public function membersCount(Segment $segment): int
    {
        return count($this->memberIds($segment));
    }

    public function hasContact(Segment $segment, Contact|int|string $contact): bool
    {
        $handles = $this->handlesForContact($contact);

        return in_array($segment->handle, $handles, true);
    }

    public function addContact(Segment $segment, Contact|int|string $contact): void
    {
        $path = $this->contactPath($contact);
        if ($path === null) {
            return;
        }

        $data = $this->files->readYaml($path);
        $handles = array_map('strval', (array) ($data['segment_handles'] ?? []));

        if (in_array((string) $segment->handle, $handles, true)) {
            return;
        }

        $handles[] = (string) $segment->handle;
        $data['segment_handles'] = $handles;
        $this->files->writeYaml($path, $data);
    }

    public function removeContact(Segment $segment, Contact|int|string $contact): void
    {
        $path = $this->contactPath($contact);
        if ($path === null) {
            return;
        }

        $data = $this->files->readYaml($path);
        $handles = array_map('strval', (array) ($data['segment_handles'] ?? []));

        if (! in_array((string) $segment->handle, $handles, true)) {
            return;
        }

        $data['segment_handles'] = array_values(array_filter($handles, fn ($h) => $h !== (string) $segment->handle));
        $this->files->writeYaml($path, $data);
    }

    public function handlesForContact(Contact|int|string $contact): array
    {
        $path = $this->contactPath($contact);
        if ($path === null) {
            return [];
        }

        $data = $this->files->readYaml($path);

        return array_map('strval', (array) ($data['segment_handles'] ?? []));
    }

    /* -------- internals -------- */

    protected function rows(): array
    {
        $data = $this->files->readYaml('segments.yaml');

        return $data['segments'] ?? [];
    }

    protected function loadOne(string $id): ?array
    {
        foreach ($this->rows() as $row) {
            if (($row['id'] ?? null) === $id || ($row['uuid'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    protected function contactPath(Contact|int|string $contact): ?string
    {
        // Flat-file contacts are addressed by UUID. Reading $contact->id would
        // truncate the UUID string to 0 (the Contact model int-casts its key),
        // so prefer the uuid attribute — the same fix the CP layer applied in
        // v0.3.1 for contact-detail URLs.
        if ($contact instanceof Contact) {
            $id = $contact->uuid ?: $contact->getAttribute('id');
        } else {
            $id = $contact;
        }

        if (empty($id)) {
            return null;
        }

        $path = 'contacts/'.$id.'.yaml';

        return $this->files->exists($path) ? $path : null;
    }

    protected function computeMembersPerHandle(): array
    {
        $counts = [];

        foreach ($this->files->glob('contacts/*.yaml') as $relative) {
            $data = $this->files->readYaml($relative);
            foreach ((array) ($data['segment_handles'] ?? []) as $handle) {
                $handle = (string) $handle;
                $counts[$handle] = ($counts[$handle] ?? 0) + 1;
            }
        }

        return $counts;
    }

    protected function normalizeRules(mixed $rules): array
    {
        if (is_array($rules)) {
            return $rules;
        }

        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
