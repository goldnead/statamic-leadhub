<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Support\ContactDto;
use Goldnead\Leadhub\Support\EmailNormalizer;

class SubmissionMapper
{
    /**
     * Map a raw form submission's data to a ContactDto using the mapping config.
     *
     * @param  array<string, mixed>  $data       The submission's field data
     * @param  FormMapping           $mapping    The mapping configuration
     * @param  string|null           $submissionId Statamic submission ID (microtime)
     */
    public function map(array $data, FormMapping $mapping, ?string $submissionId = null): ContactDto
    {
        $email = $this->extract($data, $mapping->email_field);
        $firstName = $this->extract($data, $mapping->first_name_field);
        $lastName = $this->extract($data, $mapping->last_name_field);
        $fullName = $this->extract($data, $mapping->full_name_field);

        // Heuristic: if full_name is mapped but first/last are not, try to split.
        if ($fullName && empty($firstName) && empty($lastName)) {
            [$firstName, $lastName] = $this->splitFullName($fullName);
        }

        // If no full_name but first+last present, compose it.
        if (empty($fullName) && ($firstName || $lastName)) {
            $fullName = trim(($firstName ?? '').' '.($lastName ?? ''));
            $fullName = $fullName === '' ? null : $fullName;
        }

        $phone = $this->extract($data, $mapping->phone_field);
        $company = $this->extract($data, $mapping->company_field);
        $message = $this->extract($data, $mapping->message_field);
        $consent = $this->extractBoolean($data, $mapping->consent_field);

        $tags = $this->extractTags($data, $mapping);

        $attribution = $this->extractAttribution($data);

        return new ContactDto(
            email: $email ? EmailNormalizer::normalize($email) === null ? null : trim((string) $email) : null,
            firstName: $this->cleanString($firstName),
            lastName: $this->cleanString($lastName),
            fullName: $this->cleanString($fullName),
            phone: $this->cleanString($phone),
            company: $this->cleanString($company),
            message: $this->cleanString($message),
            consent: $consent,
            tags: $tags,
            source: $mapping->default_source,
            sourceForm: $mapping->form_handle,
            defaultStatus: $mapping->default_status ?: config('leadhub.default_status', 'new'),
            rawSubmission: $data,
            submissionId: $submissionId,
            attribution: $attribution,
        );
    }

    /**
     * Capture marketing attribution (UTM params, referrer, landing page) from
     * conventionally-named submission fields. The website form populates these
     * (typically hidden fields filled from the URL query + document.referrer).
     * Returns a contact-column => value map, or [] when the feature is off.
     *
     * @return array<string, string>
     */
    protected function extractAttribution(array $data): array
    {
        if (! config('leadhub.features.attribution', false)) {
            return [];
        }

        $out = [];
        foreach ((array) config('leadhub.attribution.fields', []) as $column => $handle) {
            $value = $this->cleanString($this->extract($data, $handle));
            if ($value !== null) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    protected function extract(array $data, ?string $key): mixed
    {
        if (empty($key)) {
            return null;
        }

        return data_get($data, $key);
    }

    protected function extractBoolean(array $data, ?string $key): bool
    {
        $value = $this->extract($data, $key);

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lowered = mb_strtolower(trim($value));

            return in_array($lowered, ['1', 'true', 'yes', 'on', 'ja'], true);
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_array($value)) {
            return ! empty($value);
        }

        return (bool) $value;
    }

    /**
     * Extract tags from a form field (comma-separated string or array)
     * and merge with any default tags configured on the mapping.
     */
    protected function extractTags(array $data, FormMapping $mapping): array
    {
        $tags = [];

        if ($mapping->tags_field) {
            $value = $this->extract($data, $mapping->tags_field);

            if (is_array($value)) {
                $tags = array_merge($tags, $value);
            } elseif (is_string($value)) {
                $tags = array_merge($tags, array_map('trim', explode(',', $value)));
            }
        }

        // Accept default_tags as either an already-decoded array (the happy
        // path with Eloquent's array cast) or a raw JSON string (defensive —
        // some DB driver / cast combinations leak the unparsed JSON through).
        $defaultTags = $mapping->default_tags;
        if (is_string($defaultTags) && $defaultTags !== '') {
            $decoded = json_decode($defaultTags, true);
            $defaultTags = is_array($decoded) ? $decoded : [];
        }

        if (is_array($defaultTags)) {
            $tags = array_merge($tags, $defaultTags);
        }

        $tags = array_filter(array_map('strval', $tags), fn ($tag) => $tag !== '');

        return array_values(array_unique($tags));
    }

    protected function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName));

        if (! $parts || count($parts) === 0) {
            return [null, null];
        }

        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        $first = array_shift($parts);
        $last = implode(' ', $parts);

        return [$first, $last];
    }

    protected function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = implode(' ', $value);
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
