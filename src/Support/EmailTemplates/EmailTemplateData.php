<?php

namespace Goldnead\Leadhub\Support\EmailTemplates;

use Illuminate\Support\Str;

/**
 * Normalized, driver-agnostic shape of an email template as it flows through
 * the import pipeline and out of the resolver. Sibling addons (automations,
 * marketing) and host apps only ever see this shape — never the underlying
 * Statamic entry or a foreign addon's DTO.
 */
class EmailTemplateData
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $subject = '',
        public string $body = '',
        public ?string $plainText = null,
        public ?string $description = null,
        public string $source = 'entry',
    ) {
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $slug = (string) ($data['slug'] ?? $data['handle'] ?? '');
        $title = (string) ($data['title'] ?? $data['name'] ?? $slug);

        return new self(
            slug: $slug !== '' ? $slug : Str::slug($title),
            title: $title !== '' ? $title : $slug,
            subject: (string) ($data['subject'] ?? ''),
            body: (string) ($data['body'] ?? $data['html'] ?? ''),
            plainText: isset($data['plain_text']) ? (string) $data['plain_text'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            source: (string) ($data['source'] ?? 'entry'),
        );
    }

    /**
     * Entry data payload — the keys map to the email_template blueprint.
     *
     * @return array<string,mixed>
     */
    public function toEntryData(): array
    {
        return array_filter([
            'title' => $this->title,
            'subject' => $this->subject,
            'body' => $this->body,
            'plain_text' => $this->plainText,
            'description' => $this->description,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'subject' => $this->subject,
            'body' => $this->body,
            'plain_text' => $this->plainText,
            'description' => $this->description,
            'source' => $this->source,
        ];
    }
}
