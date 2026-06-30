<?php

namespace Goldnead\Leadhub\Crm;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\SyncResult;
use Illuminate\Support\Facades\Http;

/**
 * Generic outbound webhook — POSTs the contact as JSON to a URL. Doubles as the
 * integration point for a webhook addon / Zapier / Make / n8n. Optionally signs
 * the body with an HMAC-SHA256 signature header.
 */
class WebhookDestination extends AbstractDestination
{
    public function driver(): string
    {
        return 'webhook';
    }

    public function push(Contact $contact): SyncResult
    {
        $url = (string) $this->config('url');
        if ($url === '') {
            return SyncResult::fail('No webhook URL configured.');
        }

        $payload = [
            'event' => 'contact.synced',
            'contact' => $this->contactArray($contact),
        ];
        $body = json_encode($payload);

        $headers = ['Content-Type' => 'application/json'];
        if ($secret = (string) $this->config('secret')) {
            $headers['X-LeadHub-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }
        foreach ((array) $this->config('headers', []) as $name => $value) {
            $headers[$name] = $value;
        }

        try {
            $response = Http::timeout((int) $this->config('timeout', 10))
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($url);

            return $response->successful()
                ? SyncResult::ok(null, $response->status())
                : SyncResult::fail('HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300), $response->status());
        } catch (\Throwable $e) {
            return SyncResult::fail($e->getMessage());
        }
    }
}
