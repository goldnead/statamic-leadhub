<?php

namespace Goldnead\Leadhub\Repositories\Eloquent;

use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EloquentTagRepository implements TagRepository
{
    public function find(int|string $id): ?Tag
    {
        if (is_string($id) && ! ctype_digit($id)) {
            return Tag::query()->where('uuid', $id)->first();
        }

        return Tag::query()->find($id);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return Tag::query()->where('slug', $slug)->first();
    }

    public function findOrCreate(string $name, ?string $color = null): Tag
    {
        $slug = Str::slug($name);
        $existing = $this->findBySlug($slug);

        if ($existing) {
            return $existing;
        }

        return Tag::create([
            'name' => trim($name),
            'slug' => $slug,
            'color' => $color,
        ]);
    }

    public function create(array $attributes): Tag
    {
        if (! isset($attributes['slug']) && ! empty($attributes['name'])) {
            $attributes['slug'] = Str::slug($attributes['name']);
        }

        return Tag::create($attributes);
    }

    public function update(Tag $tag, array $attributes): Tag
    {
        if (isset($attributes['name']) && ! isset($attributes['slug'])) {
            $attributes['slug'] = Str::slug($attributes['name']);
        }

        $tag->fill($attributes);
        $tag->save();

        return $tag;
    }

    public function delete(Tag $tag): void
    {
        $tag->delete();
    }

    public function all(): Collection
    {
        return Tag::query()->orderBy('name')->get();
    }

    public function paginate(int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        return Tag::query()
            ->withCount('contacts')
            ->orderBy('name')
            ->paginate($perPage, page: $page);
    }

    public function attach(Contact $contact, Tag $tag): bool
    {
        if ($this->isAttached($contact, $tag)) {
            return false;
        }

        $contact->tags()->attach($tag->id);

        return true;
    }

    public function detach(Contact $contact, Tag $tag): bool
    {
        if (! $this->isAttached($contact, $tag)) {
            return false;
        }

        $contact->tags()->detach($tag->id);

        return true;
    }

    public function isAttached(Contact $contact, Tag $tag): bool
    {
        return $contact->tags()->where('leadhub_tags.id', $tag->id)->exists();
    }

    public function forContact(Contact|int|string $contact): Collection
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        return Tag::query()
            ->whereHas('contacts', fn ($q) => $q->where('leadhub_contacts.id', $contactId))
            ->orderBy('name')
            ->get();
    }

    public function detachAllFor(Contact|int|string $contact): void
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        \DB::table('leadhub_contact_tag')->where('contact_id', $contactId)->delete();
    }
}
