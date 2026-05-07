<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Events\LeadHubTagAdded;
use Goldnead\Leadhub\Events\LeadHubTagRemoved;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Tag;
use Illuminate\Support\Str;

class TagService
{
    public function __construct(protected TimelineService $timeline)
    {
    }

    /**
     * Find a tag by slug or create it if it doesn't exist.
     */
    public function findOrCreate(string $name): Tag
    {
        $slug = Str::slug($name);
        $name = trim($name);

        $tag = Tag::query()->where('slug', $slug)->first();

        if ($tag) {
            return $tag;
        }

        return Tag::create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    /**
     * Attach a tag to a contact. If already attached, no-op (no duplicate
     * timeline event). Returns true if a new attachment occurred.
     */
    public function attach(Contact $contact, Tag|string $tag, bool $silent = false): bool
    {
        $tag = $tag instanceof Tag ? $tag : $this->findOrCreate($tag);

        $alreadyAttached = $contact->tags()->where('leadhub_tags.id', $tag->id)->exists();

        if ($alreadyAttached) {
            return false;
        }

        $contact->tags()->attach($tag->id);

        if (! $silent) {
            $this->timeline->recordTagAdded($contact, $tag->name);
            event(new LeadHubTagAdded($contact, null, ['tag_id' => $tag->id, 'tag_name' => $tag->name]));
        }

        return true;
    }

    public function detach(Contact $contact, Tag|string $tag): bool
    {
        $tag = $tag instanceof Tag ? $tag : Tag::where('slug', Str::slug($tag))->first();

        if (! $tag) {
            return false;
        }

        $alreadyAttached = $contact->tags()->where('leadhub_tags.id', $tag->id)->exists();

        if (! $alreadyAttached) {
            return false;
        }

        $contact->tags()->detach($tag->id);

        $this->timeline->recordTagRemoved($contact, $tag->name);
        event(new LeadHubTagRemoved($contact, null, ['tag_id' => $tag->id, 'tag_name' => $tag->name]));

        return true;
    }

    /**
     * Bulk-attach tags by name to a contact (silent — used during submission
     * processing where a single timeline entry is not needed per tag).
     */
    public function attachMany(Contact $contact, array $tagNames, bool $silent = true): array
    {
        $attached = [];

        foreach ($tagNames as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $tag = $this->findOrCreate($name);
            if ($this->attach($contact, $tag, silent: $silent)) {
                $attached[] = $tag;
            }
        }

        return $attached;
    }
}
