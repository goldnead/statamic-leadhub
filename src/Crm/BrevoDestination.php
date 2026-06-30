<?php

namespace Goldnead\Leadhub\Crm;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\SyncResult;
use Illuminate\Support\Facades\Http;

/**
 * Brevo (formerly Sendinblue) contacts API v3. Auth via an `api-key` header.
 * Uses updateEnabled=true so a single call upserts by email. Optionally adds
 * the contact to a list (config: list_id).
 */
class BrevoDestination extends AbstractDestination
{
    protected string $base = 'https://api.brevo.com/v3';

    public function driver(): string
    {
        return 'brevo';
    }

    public function push(Contact $contact): SyncResult
    {
        $key = (string) $this->config('api_key');
        if ($key === '') {
            return SyncResult::fail('No Brevo API key configured.');
        }
        if (! $contact->email) {
            return SyncResult::fail('Contact has no email.');
        }

        $payload = [
            'email' => $contact->email,
            'attributes' => array_filter([
                'FIRSTNAME' => $contact->first_name,
                'LASTNAME' => $contact->last_name,
                'SMS' => $contact->phone,
                'COMPANY' => $contact->company,
            ], fn ($v) => $v !== null && $v !== ''),
            'updateEnabled' => true,
        ];

        if ($listId = $this->config('list_id')) {
            $payload['listIds'] = [(int) $listId];
        }

        try {
            $response = Http::withHeaders(['api-key' => $key, 'accept' => 'application/json'])
                ->timeout((int) $this->config('timeout', 10))
                ->post("{$this->base}/contacts", $payload);

            // 201 created, 204 updated.
            return in_array($response->status(), [201, 204], true)
                ? SyncResult::ok((string) ($response->json('id') ?? ''), $response->status())
                : SyncResult::fail('HTTP '.$response->status().': '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300), $response->status());
        } catch (\Throwable $e) {
            return SyncResult::fail($e->getMessage());
        }
    }
}
