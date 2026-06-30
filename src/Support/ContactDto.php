<?php

namespace Goldnead\Leadhub\Support;

/**
 * A normalized representation of a lead extracted from a Statamic
 * form submission. Lives between SubmissionMapper and ContactResolver.
 */
class ContactDto
{
    public function __construct(
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $fullName = null,
        public ?string $phone = null,
        public ?string $company = null,
        public ?string $message = null,
        public bool $consent = false,
        public array $tags = [],
        public ?string $source = null,
        public ?string $sourceForm = null,
        public ?string $defaultStatus = null,
        public array $rawSubmission = [],
        public ?string $submissionId = null,
        /** Attribution column => value pairs (utm_source, referrer, …). */
        public array $attribution = [],
    ) {
    }

    public function hasEmail(): bool
    {
        return ! empty($this->email);
    }

    /**
     * Returns a partial array of contact fields that have non-empty values.
     * Used by ContactResolver to perform conditional updates.
     */
    public function toContactAttributes(): array
    {
        return array_filter(array_merge([
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->fullName,
            'phone' => $this->phone,
            'company' => $this->company,
            'source' => $this->source,
            'source_form' => $this->sourceForm,
        ], $this->attribution), fn ($value) => $value !== null && $value !== '');
    }
}
