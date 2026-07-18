<?php

namespace Goldnead\Leadhub\Services\EmailTemplates;

use Goldnead\Leadhub\Support\EmailTemplates\EmailTemplateData;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Resolves an email template by slug. A managed `email_templates` entry always
 * wins; the old file-based template is only used as a fallback, so migrating a
 * template into the CP overrides the file without anything breaking, and a
 * not-yet-migrated slug keeps resolving to its file template.
 *
 * The fallback is a caller-supplied callable so this stays decoupled from the
 * consuming addon (automations passes its inline body; marketing passes its
 * EmailTemplateRepository lookup). That keeps leadhub free of a hard
 * dependency on either addon.
 */
class EmailTemplateResolver
{
    public function __construct(
        protected EmailTemplateCollectionManager $collection,
    ) {
    }

    /**
     * @param  (callable(string):(EmailTemplateData|array<string,mixed>|null))|null  $fallback
     */
    public function resolve(string $slug, ?callable $fallback = null): ?EmailTemplateData
    {
        $entry = $this->collection->findBySlug($slug);

        if ($entry) {
            return $this->fromEntry($slug, $entry);
        }

        if ($fallback === null) {
            return null;
        }

        $result = $fallback($slug);

        if ($result instanceof EmailTemplateData) {
            return $result;
        }

        if (is_array($result)) {
            $data = EmailTemplateData::fromArray($result + ['slug' => $slug]);
            $data->source = $data->source === 'entry' ? 'fallback' : $data->source;

            return $data;
        }

        return null;
    }

    protected function fromEntry(string $slug, EntryContract $entry): EmailTemplateData
    {
        return new EmailTemplateData(
            slug: $slug,
            title: (string) ($entry->value('title') ?? $slug),
            subject: (string) ($entry->value('subject') ?? ''),
            body: (string) ($entry->value('body') ?? ''),
            plainText: $entry->value('plain_text') !== null ? (string) $entry->value('plain_text') : null,
            description: $entry->value('description') !== null ? (string) $entry->value('description') : null,
            source: 'entry',
        );
    }
}
