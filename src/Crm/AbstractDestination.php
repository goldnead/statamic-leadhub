<?php

namespace Goldnead\Leadhub\Crm;

use Goldnead\Leadhub\Contracts\CrmDestination;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Models\Contact;

abstract class AbstractDestination implements CrmDestination
{
    /**
     * @param  string  $key  The destination's config key (label).
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $key,
        protected array $config = [],
    ) {}

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'webhook');
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * A normalized, provider-agnostic representation of the contact. Each
     * destination maps the keys it cares about onto its own API shape.
     *
     * @return array<string, mixed>
     */
    protected function contactArray(Contact $contact): array
    {
        $tags = app(TagRepository::class)->forContact($contact)
            ->pluck('name')->values()->all();

        return array_filter([
            'uuid' => $contact->uuid,
            'email' => $contact->email,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'full_name' => $contact->displayName(),
            'phone' => $contact->phone,
            'company' => $contact->company,
            'status' => $contact->status,
            'source' => $contact->source,
            'source_form' => $contact->source_form,
            'tags' => $tags,
            'utm_source' => $contact->utm_source,
            'utm_medium' => $contact->utm_medium,
            'utm_campaign' => $contact->utm_campaign,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
