<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FlatFileContactRepository implements ContactRepository
{
    public function __construct(
        protected FileStore $files,
        protected Index $index,
        protected IndexBuilder $builder,
        protected ModelHydrator $hydrator,
        protected FlatFileTagRepository $tags,
        protected FlatFileFollowupRepository $followups,
    ) {
    }

    public function find(int|string $id): ?Contact
    {
        $this->builder->ensureFreshContacts($this->index);

        $item = $this->index->getItem((string) $id);

        if (! $item) {
            return null;
        }

        return $this->loadFromPath($item['path']);
    }

    public function findByUuid(string $uuid): ?Contact
    {
        return $this->find($uuid);
    }

    public function findByEmailNormalized(string $emailNormalized): ?Contact
    {
        $this->builder->ensureFreshContacts($this->index);

        $id = $this->index->lookup('email_normalized', $emailNormalized);

        return $id ? $this->find($id) : null;
    }

    public function create(array $attributes): Contact
    {
        $uuid = $attributes['uuid'] ?? (string) Str::uuid();
        $attributes['uuid'] = $uuid;
        $attributes['id'] = $uuid;
        $attributes['created_at'] = $attributes['created_at'] ?? now()->toIso8601String();
        $attributes['updated_at'] = $attributes['updated_at'] ?? now()->toIso8601String();
        $attributes['tag_ids'] = $attributes['tag_ids'] ?? [];

        $this->writeYaml($uuid, $attributes);
        $this->builder->rebuildContacts($this->index);

        return $this->hydrateWithRelations($attributes);
    }

    public function save(Contact $contact): Contact
    {
        $uuid = (string) $contact->uuid;

        if (empty($uuid)) {
            throw new \RuntimeException('Cannot save Contact without a UUID via flat-file driver.');
        }

        $existing = $this->files->exists($this->relativePath($uuid))
            ? $this->files->readYaml($this->relativePath($uuid))
            : [];

        $merged = array_merge($existing, $this->serializeContact($contact));
        $merged['updated_at'] = now()->toIso8601String();

        $this->writeYaml($uuid, $merged);
        $this->builder->rebuildContacts($this->index);

        $contact->setRawAttributes($merged, sync: true);
        $contact->exists = true;

        return $contact;
    }

    public function delete(Contact $contact): void
    {
        $uuid = (string) $contact->uuid;
        if (! $uuid) {
            return;
        }

        $this->files->delete($this->relativePath($uuid));
        $this->files->delete('events/'.$uuid.'.jsonl');
        $this->files->delete('followups/'.$uuid.'.jsonl');

        $this->builder->rebuildContacts($this->index);
    }

    public function archive(Contact $contact): void
    {
        $contact->archived_at = now();
        $this->save($contact);
    }

    public function restore(Contact $contact): void
    {
        $contact->archived_at = null;
        $this->save($contact);
    }

    public function touchActivity(Contact $contact): void
    {
        $contact->last_activity_at = now();
        $this->save($contact);
    }

    public function paginate(array $filters = [], int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $this->builder->ensureFreshContacts($this->index);

        $items = $this->index->items();
        $candidates = $this->applyFilters($items, $filters);
        $candidates = $this->applySort($candidates, $filters);

        $total = count($candidates);
        $sliced = array_slice($candidates, ($page - 1) * $perPage, $perPage);

        $contacts = collect($sliced)->map(fn (array $entry) => $this->loadFromPath($entry['path']))->filter()->values();

        return new LaravelLengthAwarePaginator(
            items: $contacts,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()?->url() ?? '/',
                'pageName' => 'page',
            ],
        );
    }

    public function recent(int $limit = 5): Collection
    {
        $this->builder->ensureFreshContacts($this->index);

        $items = $this->index->items();
        $active = array_filter($items, fn ($i) => empty($i['fields']['archived_at']));

        usort($active, fn ($a, $b) => strcmp(
            $b['fields']['created_at'] ?? '',
            $a['fields']['created_at'] ?? '',
        ));

        return collect(array_slice($active, 0, $limit))
            ->map(fn (array $entry) => $this->loadFromPath($entry['path']))
            ->filter()
            ->values();
    }

    public function countNewSince(int $days): int
    {
        $this->builder->ensureFreshContacts($this->index);

        $cutoff = now()->subDays($days)->toIso8601String();
        $items = $this->index->items();

        $count = 0;
        foreach ($items as $item) {
            if (! empty($item['fields']['archived_at'])) {
                continue;
            }
            $created = $item['fields']['created_at'] ?? null;
            if ($created && $created >= $cutoff) {
                $count++;
            }
        }

        return $count;
    }

    public function countByStatus(): array
    {
        $this->builder->ensureFreshContacts($this->index);

        $items = $this->index->items();
        $byStatus = [];

        foreach ($items as $item) {
            if (! empty($item['fields']['archived_at'])) {
                continue;
            }
            $status = $item['fields']['status'] ?? null;
            if (! $status) {
                continue;
            }
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        return $byStatus;
    }

    public function countWithStatus(string $status): int
    {
        return $this->countByStatus()[$status] ?? 0;
    }

    public function distinctSources(): array
    {
        $this->builder->ensureFreshContacts($this->index);

        $sources = [];
        foreach ($this->index->items() as $item) {
            if (! empty($item['fields']['source_form'])) {
                $sources[$item['fields']['source_form']] = true;
            }
        }

        return array_keys($sources);
    }

    /* -------- internals -------- */

    protected function relativePath(string $uuid): string
    {
        return 'contacts/'.$uuid.'.yaml';
    }

    protected function writeYaml(string $uuid, array $data): void
    {
        // Drop relations + transient pivot data — they're managed elsewhere.
        unset($data['tags'], $data['events'], $data['notes'], $data['followups']);
        $this->files->writeYaml($this->relativePath($uuid), $data);
    }

    protected function loadFromPath(string $path): ?Contact
    {
        $data = $this->files->readYaml($path);
        if (empty($data)) {
            return null;
        }

        return $this->hydrateWithRelations($data);
    }

    protected function hydrateWithRelations(array $data): Contact
    {
        $contact = $this->hydrator->contact($data);

        // Eager-load relations so views/controllers don't try to lazy-load.
        $tagIds = array_map('strval', (array) ($data['tag_ids'] ?? []));
        $tags = collect($tagIds)
            ->map(fn (string $id) => $this->tags->find($id))
            ->filter()
            ->values();
        $contact->setRelation('tags', $tags);

        $contact->setRelation('notes', collect($data['notes'] ?? [])->map(
            fn (array $n) => $this->hydrator->note($n)
        ));

        $contact->setRelation('events', collect()); // events loaded lazily via repository

        $followupsRel = collect();
        if (! empty($data['active_followup'])) {
            $af = $data['active_followup'];
            $af['contact_id'] = $contact->id;
            $followupsRel->push($this->hydrator->followup($af));
        }
        $contact->setRelation('followups', $followupsRel);

        return $contact;
    }

    /**
     * Capture all of a Contact's attributes (DB model -> array) but ensure
     * tag_ids and active_followup are mirrored from the relations if loaded.
     */
    protected function serializeContact(Contact $contact): array
    {
        $attrs = $contact->attributesToArray();

        // Mirror loaded relations.
        if ($contact->relationLoaded('tags')) {
            $attrs['tag_ids'] = $contact->getRelation('tags')->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        }

        // Drop columns that don't make sense in flat-file (or rebuild from relations).
        unset($attrs['email_normalized']);

        if (isset($contact->email)) {
            $attrs['email_normalized'] = \Goldnead\Leadhub\Support\EmailNormalizer::normalize($contact->email);
        }

        return $attrs;
    }

    /* -------- filtering -------- */

    protected function applyFilters(array $items, array $filters): array
    {
        $showArchived = ! empty($filters['archived']);

        return array_values(array_filter($items, function (array $item) use ($filters, $showArchived) {
            $f = $item['fields'] ?? [];

            if ($showArchived) {
                if (empty($f['archived_at'])) {
                    return false;
                }
            } else {
                if (! empty($f['archived_at'])) {
                    return false;
                }
            }

            if (! empty($filters['status']) && $f['status'] !== $filters['status']) {
                return false;
            }

            if (! empty($filters['source_form']) && $f['source_form'] !== $filters['source_form']) {
                return false;
            }

            if (! empty($filters['assigned_to'])) {
                $owner = $f['assigned_to'] ?? null;
                if ($filters['assigned_to'] === 'none') {
                    if (! empty($owner)) {
                        return false;
                    }
                } elseif ((string) $owner !== (string) $filters['assigned_to']) {
                    return false;
                }
            }

            if (! empty($filters['tag_id'])) {
                $tagIds = (array) ($f['tag_ids'] ?? []);
                if (! in_array((string) $filters['tag_id'], $tagIds, true)) {
                    return false;
                }
            }

            if (! empty($filters['from'])) {
                if (($f['created_at'] ?? '') < $filters['from']) {
                    return false;
                }
            }
            if (! empty($filters['to'])) {
                if (($f['created_at'] ?? '') > $filters['to']) {
                    return false;
                }
            }

            if (! empty($filters['has_followup'])) {
                $due = $f['active_followup_due_at'] ?? null;

                switch ($filters['has_followup']) {
                    case 'has':
                        if (! $due) {
                            return false;
                        }
                        break;
                    case 'today':
                        if (! $due || ! Carbon::parse($due)->isToday()) {
                            return false;
                        }
                        break;
                    case 'overdue':
                        if (! $due || ! Carbon::parse($due)->isPast() || Carbon::parse($due)->isToday()) {
                            return false;
                        }
                        break;
                }
            }

            if (! empty($filters['search'])) {
                $needle = mb_strtolower($filters['search']);
                $haystacks = [
                    $f['email'] ?? '',
                    $f['first_name'] ?? '',
                    $f['last_name'] ?? '',
                    $f['full_name'] ?? '',
                    $f['phone'] ?? '',
                    $f['company'] ?? '',
                ];
                $haystack = mb_strtolower(implode(' ', array_filter($haystacks)));
                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }

    protected function applySort(array $items, array $filters): array
    {
        $sort = in_array($filters['sort'] ?? null, ['created_at', 'last_activity_at', 'status'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        usort($items, function ($a, $b) use ($sort, $direction) {
            $av = $a['fields'][$sort] ?? '';
            $bv = $b['fields'][$sort] ?? '';
            $cmp = $av <=> $bv;

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        return $items;
    }
}
