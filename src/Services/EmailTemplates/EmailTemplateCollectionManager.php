<?php

namespace Goldnead\Leadhub\Services\EmailTemplates;

use Goldnead\Leadhub\Support\EmailTemplates\EmailTemplateBlueprint;
use Goldnead\Leadhub\Support\EmailTemplates\EmailTemplateData;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Owns the native Statamic `email_templates` collection: creating it (and its
 * blueprint) if absent, and reading/writing template entries by slug.
 *
 * The collection is a first-class Statamic collection, so it shows up in the
 * CP with the native listing + publish form, revisions, and localization for
 * free. The slug is the stable cross-addon reference and is never rewritten.
 */
class EmailTemplateCollectionManager
{
    public const HANDLE = 'email_templates';

    /**
     * Ensure the collection and its blueprint exist. Idempotent and cheap to
     * call on every boot — both writes are skipped once present.
     */
    public function ensure(): void
    {
        if (! Collection::findByHandle(self::HANDLE)) {
            Collection::make(self::HANDLE)
                ->title(__('leadhub::email_templates.collection_title'))
                ->routes(null)
                ->revisionsEnabled(false)
                ->save();
        }

        if (! Blueprint::find(EmailTemplateBlueprint::NAMESPACE.'.'.EmailTemplateBlueprint::HANDLE)) {
            EmailTemplateBlueprint::make()->save();
        }
    }

    /** Find a template entry by slug (or null). */
    public function findBySlug(string $slug): ?EntryContract
    {
        return Entry::query()
            ->where('collection', self::HANDLE)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Create or update a template entry from normalized data, keyed by slug.
     * Returns [$entry, $created] where $created is false when an existing entry
     * was updated.
     *
     * @return array{0:EntryContract,1:bool}
     */
    public function upsert(EmailTemplateData $data): array
    {
        $existing = $this->findBySlug($data->slug);

        if ($existing) {
            $existing->merge($data->toEntryData());
            $existing->save();

            return [$existing, false];
        }

        $entry = Entry::make()
            ->collection(self::HANDLE)
            ->slug($data->slug)
            ->data($data->toEntryData());

        $entry->save();

        return [$entry, true];
    }
}
