<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Events\LeadHubTagAdded;
use Goldnead\Leadhub\Events\LeadHubTagRemoved;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Tag;
use Illuminate\Support\Str;

class TagService
{
    public function __construct(
        protected TagRepository $tags,
        protected TimelineService $timeline,
    ) {}

    public function findOrCreate(string $name): Tag
    {
        return $this->tags->findOrCreate($name);
    }

    public function attach(Contact $contact, Tag|string $tag, bool $silent = false): bool
    {
        $tag = $tag instanceof Tag ? $tag : $this->tags->findOrCreate($tag);

        $attached = $this->tags->attach($contact, $tag);

        if ($attached && ! $silent) {
            $this->timeline->recordTagAdded($contact, $tag->name);
            event(new LeadHubTagAdded($contact, null, ['tag_id' => $tag->id, 'tag_name' => $tag->name]));
        }

        return $attached;
    }

    public function detach(Contact $contact, Tag|string $tag): bool
    {
        $tag = $tag instanceof Tag ? $tag : $this->tags->findBySlug(Str::slug($tag));

        if (! $tag) {
            return false;
        }

        $detached = $this->tags->detach($contact, $tag);

        if ($detached) {
            $this->timeline->recordTagRemoved($contact, $tag->name);
            event(new LeadHubTagRemoved($contact, null, ['tag_id' => $tag->id, 'tag_name' => $tag->name]));
        }

        return $detached;
    }

    public function attachMany(Contact $contact, array $tagNames, bool $silent = true): array
    {
        $attached = [];

        foreach ($tagNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $tag = $this->tags->findOrCreate($name);
            if ($this->attach($contact, $tag, silent: $silent)) {
                $attached[] = $tag;
            }
        }

        return $attached;
    }
}
