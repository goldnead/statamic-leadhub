<?php

namespace Goldnead\Leadhub\Support;

/**
 * A normalized signal from any source (a purchase, booking, login, content
 * download, inbound webhook, …) that should resolve to a LeadHub contact and
 * leave a timeline entry. This is the generic counterpart to ContactDto, which
 * is specific to Statamic form submissions.
 */
class SourceEvent
{
    /**
     * @param  array<string,mixed>  $contact  Optional upsert fields for the contact
     *                                        (first_name, last_name, full_name, phone, company).
     * @param  array<int,string>  $tags  Tag names to attach.
     * @param  array<string,mixed>  $payload  Arbitrary event payload stored on the timeline (redacted).
     */
    public function __construct(
        public ?string $email = null,
        public string $type = 'source_ingested',
        public ?string $summary = null,
        public ?string $sourceType = null,
        public int|string|null $sourceId = null,
        public ?string $dedupeKey = null,
        public ?\DateTimeInterface $occurredAt = null,
        public array $payload = [],
        public array $contact = [],
        public array $tags = [],
        public ?string $source = null,
        public ?string $phone = null,
        public ?string $defaultStatus = null,
        /**
         * Attribution column => value pairs (utm_source, referrer, …), seeded
         * onto the contact when this signal is the first thing we know about
         * them. A contributor that knows which campaign produced a purchase is
         * the only participant that can answer that question later.
         */
        public array $attribution = [],
    ) {}

    public function hasEmail(): bool
    {
        return ! empty($this->email);
    }

    public function hasPhone(): bool
    {
        return ! empty($this->phone) || ! empty($this->contact['phone'] ?? null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'] ?? null,
            type: $data['type'] ?? 'source_ingested',
            summary: $data['summary'] ?? null,
            sourceType: $data['source_type'] ?? null,
            sourceId: $data['source_id'] ?? null,
            dedupeKey: $data['dedupe_key'] ?? null,
            occurredAt: $data['occurred_at'] ?? null,
            payload: $data['payload'] ?? [],
            contact: $data['contact'] ?? [],
            tags: $data['tags'] ?? [],
            source: $data['source'] ?? null,
            phone: $data['phone'] ?? ($data['contact']['phone'] ?? null),
            defaultStatus: $data['default_status'] ?? null,
            attribution: $data['attribution'] ?? [],
        );
    }

    /**
     * Build a ContactDto from this source event for resolve-or-create.
     */
    public function toContactDto(): ContactDto
    {
        return new ContactDto(
            email: $this->email,
            firstName: $this->contact['first_name'] ?? null,
            lastName: $this->contact['last_name'] ?? null,
            fullName: $this->contact['full_name'] ?? null,
            phone: $this->phone ?? ($this->contact['phone'] ?? null),
            company: $this->contact['company'] ?? null,
            tags: $this->tags,
            source: $this->source,
            defaultStatus: $this->defaultStatus,
            // The host's switch, honoured here as it is for form submissions
            // (`SubmissionMapper`). A site that turned attribution off must not
            // get it back through a side door because a sibling addon happens
            // to know the campaign.
            attribution: config('leadhub.features.attribution', true) ? $this->attribution : [],
        );
    }
}
