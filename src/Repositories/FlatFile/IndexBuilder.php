<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

/**
 * Builds (and rebuilds) the JSON indexes that back the flat-file driver.
 *
 * The index for each entity is stored on the configured disk and contains:
 *   - items: { id => { path, mtime, fields } }
 *   - by_*: lookup buckets ({ value => id | [ids] })
 *
 * Auto-rebuild trigger: if the on-disk index does not exist OR if any source
 * file's mtime is newer than `rebuilt_at`, the next call to `ensureFresh()`
 * will rebuild it.
 */
class IndexBuilder
{
    public function __construct(protected FileStore $files) {}

    public function ensureFreshContacts(Index $index): void
    {
        if ($this->isContactsIndexStale($index)) {
            $this->rebuildContacts($index);
        }
    }

    public function ensureFreshTags(Index $index): void
    {
        if ($this->isTagsIndexStale($index)) {
            $this->rebuildTags($index);
        }
    }

    public function ensureFreshFormMappings(Index $index): void
    {
        if ($this->isFormMappingsIndexStale($index)) {
            $this->rebuildFormMappings($index);
        }
    }

    /** Rebuild the contacts index by scanning content/leadhub/contacts/*.yaml. */
    public function rebuildContacts(Index $index): void
    {
        $items = [];
        $byEmail = [];
        $byPhone = [];
        $byStatus = [];
        $bySourceForm = [];

        foreach ($this->files->glob('contacts/*.yaml') as $relative) {
            $data = $this->files->readYaml($relative);

            if (empty($data['uuid'])) {
                continue;
            }

            $id = (string) $data['uuid'];
            $emailNormalized = $data['email_normalized'] ?? null;
            $phoneNormalized = $data['phone_normalized'] ?? null;
            $status = $data['status'] ?? null;
            $sourceForm = $data['source_form'] ?? null;

            $items[$id] = [
                'id' => $id,
                'path' => $relative,
                'mtime' => $this->files->mtime($relative),
                'fields' => [
                    'email' => $data['email'] ?? null,
                    'email_normalized' => $emailNormalized,
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'full_name' => $data['full_name'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'phone_normalized' => $phoneNormalized,
                    'company' => $data['company'] ?? null,
                    'status' => $status,
                    'source_form' => $sourceForm,
                    'assigned_to' => $data['assigned_to'] ?? null,
                    'archived_at' => $data['archived_at'] ?? null,
                    'created_at' => $data['created_at'] ?? null,
                    'last_activity_at' => $data['last_activity_at'] ?? null,
                    'tag_ids' => array_map('strval', (array) ($data['tag_ids'] ?? [])),
                    'active_followup_due_at' => $data['active_followup']['due_at'] ?? null,
                ],
            ];

            if ($emailNormalized) {
                $byEmail[$emailNormalized] = $id;
            }

            // FlatFileContactRepository::findByPhoneNormalized() has queried
            // this bucket since 1.0 and nothing ever built it, so a phone-only
            // contact could never be found by phone — the lookup returned null
            // every time and the caller created a duplicate.
            if ($phoneNormalized) {
                $byPhone[$phoneNormalized] = $id;
            }

            if ($status) {
                $byStatus[$status] = $byStatus[$status] ?? [];
                $byStatus[$status][] = $id;
            }

            if ($sourceForm) {
                $bySourceForm[$sourceForm] = $bySourceForm[$sourceForm] ?? [];
                $bySourceForm[$sourceForm][] = $id;
            }
        }

        $index->setData([
            'items' => $items,
            'by_email_normalized' => $byEmail,
            'by_phone_normalized' => $byPhone,
            'by_status' => $byStatus,
            'by_source_form' => $bySourceForm,
        ]);

        $index->save();
    }

    public function rebuildTags(Index $index): void
    {
        $data = $this->files->readYaml('tags.yaml');
        $tags = $data['tags'] ?? [];

        $items = [];
        $bySlug = [];

        foreach ($tags as $tag) {
            if (empty($tag['id'])) {
                continue;
            }

            $id = (string) $tag['id'];
            $items[$id] = [
                'id' => $id,
                'fields' => [
                    'name' => $tag['name'] ?? null,
                    'slug' => $tag['slug'] ?? null,
                    'color' => $tag['color'] ?? null,
                ],
            ];

            if (! empty($tag['slug'])) {
                $bySlug[$tag['slug']] = $id;
            }
        }

        $index->setData([
            'items' => $items,
            'by_slug' => $bySlug,
        ]);

        $index->save();
    }

    public function rebuildFormMappings(Index $index): void
    {
        $data = $this->files->readYaml('form-mappings.yaml');
        $mappings = $data['mappings'] ?? [];

        $items = [];
        $byHandle = [];

        foreach ($mappings as $m) {
            $handle = $m['form_handle'] ?? null;
            if (! $handle) {
                continue;
            }

            $id = $handle;
            $items[$id] = [
                'id' => $id,
                'fields' => [
                    'form_handle' => $handle,
                    'enabled' => (bool) ($m['enabled'] ?? false),
                    'last_processed_at' => $m['last_processed_at'] ?? null,
                ],
            ];
            $byHandle[$handle] = $id;
        }

        $index->setData([
            'items' => $items,
            'by_form_handle' => $byHandle,
        ]);

        $index->save();
    }

    protected function isContactsIndexStale(Index $index): bool
    {
        if (! $index->exists()) {
            return true;
        }

        $rebuiltAt = $index->rebuiltAt() ?? 0;

        // Cheap heuristic: compare the directory's mtime to the index's rebuild
        // time. Across every readable segment, because before the brand
        // migration the contacts are still in the pre-brand root.
        $dirMtime = $this->files->directoryMtime('contacts');
        if ($dirMtime !== null && $dirMtime > $rebuiltAt) {
            return true;
        }

        return false;
    }

    protected function isTagsIndexStale(Index $index): bool
    {
        if (! $index->exists()) {
            return true;
        }

        $fileMtime = $this->files->mtime('tags.yaml') ?? 0;

        return $fileMtime > ($index->rebuiltAt() ?? 0);
    }

    protected function isFormMappingsIndexStale(Index $index): bool
    {
        if (! $index->exists()) {
            return true;
        }

        $fileMtime = $this->files->mtime('form-mappings.yaml') ?? 0;

        return $fileMtime > ($index->rebuiltAt() ?? 0);
    }
}
