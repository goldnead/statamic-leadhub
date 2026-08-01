<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FlatFileTagRepository implements TagRepository
{
    public function __construct(
        protected FileStore $files,
        protected Index $tagsIndex,
        protected IndexBuilder $builder,
        protected ModelHydrator $hydrator,
    ) {}

    public function find(int|string $id): ?Tag
    {
        $this->builder->ensureFreshTags($this->tagsIndex);
        $entry = $this->tagsIndex->getItem((string) $id);

        if (! $entry) {
            return null;
        }

        return $this->hydrator->tag($this->loadOne((string) $id));
    }

    public function findBySlug(string $slug): ?Tag
    {
        $this->builder->ensureFreshTags($this->tagsIndex);
        $id = $this->tagsIndex->lookup('slug', $slug);

        return $id ? $this->find($id) : null;
    }

    public function findOrCreate(string $name, ?string $color = null): Tag
    {
        $slug = Str::slug($name);
        $existing = $this->findBySlug($slug);

        if ($existing) {
            return $existing;
        }

        return $this->create([
            'name' => trim($name),
            'slug' => $slug,
            'color' => $color,
        ]);
    }

    public function create(array $attributes): Tag
    {
        $now = now()->toIso8601String();
        $uuid = $attributes['uuid'] ?? (string) Str::uuid();

        $record = array_merge([
            'id' => $uuid,
            'uuid' => $uuid,
            'slug' => Str::slug($attributes['name'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes);

        $data = $this->files->readYaml('tags.yaml');
        $data['tags'] = $data['tags'] ?? [];
        $data['tags'][] = $record;
        $this->files->writeYaml('tags.yaml', $data);

        $this->builder->rebuildTags($this->tagsIndex);

        return $this->hydrator->tag($record);
    }

    public function update(Tag $tag, array $attributes): Tag
    {
        $data = $this->files->readYaml('tags.yaml');

        foreach (($data['tags'] ?? []) as $i => $row) {
            if (($row['id'] ?? null) === $tag->id || ($row['uuid'] ?? null) === $tag->uuid) {
                if (isset($attributes['name'])) {
                    $row['name'] = $attributes['name'];
                    $row['slug'] = Str::slug($attributes['name']);
                }
                if (array_key_exists('color', $attributes)) {
                    $row['color'] = $attributes['color'];
                }
                $row['updated_at'] = now()->toIso8601String();
                $data['tags'][$i] = $row;
                $this->files->writeYaml('tags.yaml', $data);
                $this->builder->rebuildTags($this->tagsIndex);

                return $this->hydrator->tag($row);
            }
        }

        return $tag;
    }

    public function delete(Tag $tag): void
    {
        $data = $this->files->readYaml('tags.yaml');
        $data['tags'] = array_values(array_filter(
            $data['tags'] ?? [],
            fn ($row) => ($row['id'] ?? null) !== $tag->id && ($row['uuid'] ?? null) !== $tag->uuid,
        ));
        $this->files->writeYaml('tags.yaml', $data);

        // Detach this tag from all contacts.
        foreach ($this->files->glob('contacts/*.yaml') as $relative) {
            $contactData = $this->files->readYaml($relative);
            $tagIds = (array) ($contactData['tag_ids'] ?? []);
            if (in_array($tag->id, $tagIds, false)) {
                $contactData['tag_ids'] = array_values(array_filter(
                    $tagIds,
                    fn ($id) => (string) $id !== (string) $tag->id,
                ));
                $this->files->writeYaml($relative, $contactData);
            }
        }

        $this->builder->rebuildTags($this->tagsIndex);
    }

    public function all(): Collection
    {
        $data = $this->files->readYaml('tags.yaml');
        $rows = $data['tags'] ?? [];

        usort($rows, fn ($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        return collect($rows)->map(fn ($r) => $this->hydrator->tag($r));
    }

    public function paginate(int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        $tags = $this->all();

        // Compute contacts_count by scanning contact files. Cached per-call.
        $tagCounts = $this->computeContactsPerTag();

        $tags = $tags->map(function (Tag $t) use ($tagCounts) {
            $t->setAttribute('contacts_count', $tagCounts[(string) $t->id] ?? 0);

            return $t;
        });

        $sliced = $tags->slice(($page - 1) * $perPage, $perPage)->values();

        return new LaravelLengthAwarePaginator(
            items: $sliced,
            total: $tags->count(),
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => request()?->url() ?? '/'],
        );
    }

    public function attach(Contact $contact, Tag $tag): bool
    {
        $contactPath = 'contacts/'.$contact->id.'.yaml';
        $data = $this->files->readYaml($contactPath);
        $tagIds = array_map('strval', (array) ($data['tag_ids'] ?? []));

        if (in_array((string) $tag->id, $tagIds, true)) {
            return false;
        }

        $tagIds[] = (string) $tag->id;
        $data['tag_ids'] = $tagIds;
        $this->files->writeYaml($contactPath, $data);

        return true;
    }

    public function detach(Contact $contact, Tag $tag): bool
    {
        $contactPath = 'contacts/'.$contact->id.'.yaml';
        $data = $this->files->readYaml($contactPath);
        $tagIds = array_map('strval', (array) ($data['tag_ids'] ?? []));

        if (! in_array((string) $tag->id, $tagIds, true)) {
            return false;
        }

        $data['tag_ids'] = array_values(array_filter($tagIds, fn ($id) => $id !== (string) $tag->id));
        $this->files->writeYaml($contactPath, $data);

        return true;
    }

    public function isAttached(Contact $contact, Tag $tag): bool
    {
        $data = $this->files->readYaml('contacts/'.$contact->id.'.yaml');
        $tagIds = array_map('strval', (array) ($data['tag_ids'] ?? []));

        return in_array((string) $tag->id, $tagIds, true);
    }

    public function forContact(Contact|int|string $contact): Collection
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $data = $this->files->readYaml('contacts/'.$contactId.'.yaml');
        $tagIds = array_map('strval', (array) ($data['tag_ids'] ?? []));

        return collect($tagIds)
            ->map(fn (string $id) => $this->find($id))
            ->filter()
            ->values();
    }

    public function detachAllFor(Contact|int|string $contact): void
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $contactPath = 'contacts/'.$contactId.'.yaml';
        $data = $this->files->readYaml($contactPath);
        $data['tag_ids'] = [];
        $this->files->writeYaml($contactPath, $data);
    }

    protected function loadOne(string $id): array
    {
        $data = $this->files->readYaml('tags.yaml');
        foreach ($data['tags'] ?? [] as $row) {
            if (($row['id'] ?? null) === $id || ($row['uuid'] ?? null) === $id) {
                return $row;
            }
        }

        return [];
    }

    protected function computeContactsPerTag(): array
    {
        $counts = [];

        foreach ($this->files->glob('contacts/*.yaml') as $relative) {
            $data = $this->files->readYaml($relative);
            foreach ((array) ($data['tag_ids'] ?? []) as $id) {
                $id = (string) $id;
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
