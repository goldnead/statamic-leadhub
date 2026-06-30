<?php

namespace Goldnead\Leadhub\Crm;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\SyncResult;
use Illuminate\Support\Facades\Http;

/**
 * HubSpot CRM (v3 contacts). Auth via a Private App token.
 * Creates the contact; on a 409 "already exists" it updates the existing one.
 */
class HubSpotDestination extends AbstractDestination
{
    protected string $base = 'https://api.hubapi.com';

    public function driver(): string
    {
        return 'hubspot';
    }

    public function push(Contact $contact): SyncResult
    {
        $token = (string) $this->config('token');
        if ($token === '') {
            return SyncResult::fail('No HubSpot token configured.');
        }

        $properties = array_filter([
            'email' => $contact->email,
            'firstname' => $contact->first_name,
            'lastname' => $contact->last_name,
            'phone' => $contact->phone,
            'company' => $contact->company,
            'hs_lead_status' => $contact->status,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $response = Http::withToken($token)
                ->timeout((int) $this->config('timeout', 10))
                ->post("{$this->base}/crm/v3/objects/contacts", ['properties' => $properties]);

            if ($response->successful()) {
                return SyncResult::ok((string) $response->json('id'), $response->status());
            }

            // 409 → already exists. HubSpot returns the existing id in the
            // message ("Existing ID: 12345"); update that record instead.
            if ($response->status() === 409
                && preg_match('/Existing ID:\s*(\d+)/', (string) $response->json('message'), $m)) {
                $update = Http::withToken($token)
                    ->timeout((int) $this->config('timeout', 10))
                    ->patch("{$this->base}/crm/v3/objects/contacts/{$m[1]}", ['properties' => $properties]);

                return $update->successful()
                    ? SyncResult::ok($m[1], $update->status())
                    : SyncResult::fail('Update failed: HTTP '.$update->status(), $update->status());
            }

            return SyncResult::fail('HTTP '.$response->status().': '.mb_substr((string) $response->json('message'), 0, 300), $response->status());
        } catch (\Throwable $e) {
            return SyncResult::fail($e->getMessage());
        }
    }
}
