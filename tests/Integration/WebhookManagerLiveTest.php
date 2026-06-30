<?php

use Goldnead\Leadhub\Integrations\WebhookManager\LeadhubTrigger;
use Goldnead\Leadhub\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Tests\Integration\WebhookManagerTestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Live LeadHub ↔ goldnead/statamic-webhook-manager integration.
 *
 * These tests exercise the real both-addons path that the no-op contract in
 * Feature/WebhookManagerBridgeTest cannot: with the webhook-manager addon
 * actually booted, do LeadHub lifecycle events register as triggers, fire the
 * addon's TriggerDetected event, and ultimately drive an outbound delivery?
 *
 * They run only when the optional peer addon is installed (see
 * scripts/test-webhook-manager.sh / the `webhook-manager-integration` CI job).
 * When it is absent the whole file skips, so the default suite is unaffected.
 */
uses(WebhookManagerTestCase::class);

beforeEach(function (): void {
    if (! WebhookManagerBridge::available()) {
        $this->markTestSkipped('goldnead/statamic-webhook-manager is not installed.');
    }
});

it('registers every LeadHub event as a selectable webhook-manager trigger', function (): void {
    $registry = \Goldnead\WebhookManager\Facades\WebhookManager::triggers();

    foreach (WebhookManagerBridge::TRIGGERS as [$handle, $label]) {
        $trigger = $registry->get($handle);

        expect($trigger)->toBeInstanceOf(LeadhubTrigger::class);
        expect($trigger->label())->toBe($label);
        // Appears in the CP <select> of available triggers.
        expect($registry->options())->toHaveKey($handle, $label);
    }
});

it('re-emits a LeadHub event as a TriggerDetected with a normalized payload', function (): void {
    // Fake ONLY the addon's event so LeadHub's own event + the bridge listener
    // still run for real; the bridge's output is what we capture.
    Event::fake([\Goldnead\WebhookManager\Events\TriggerDetected::class]);

    $contact = Contact::factory()->create();
    $actor = ['id' => 7, 'name' => 'Sales Rep'];
    $metadata = ['from' => 'new', 'to' => 'qualified'];

    event(new \Goldnead\Leadhub\Events\LeadHubStatusChanged($contact, $actor, $metadata));

    Event::assertDispatched(
        \Goldnead\WebhookManager\Events\TriggerDetected::class,
        function ($event) use ($contact, $actor, $metadata): bool {
            $te = $event->trigger;

            return $te->triggerHandle === 'leadhub.status.changed'
                && $te->sourceType === 'leadhub_contact'
                && $te->sourceReference === $contact->uuid
                && $te->payload['leadhub_event'] === 'leadhub.status.changed'
                && $te->payload['uuid'] === $contact->uuid
                && $te->payload['actor'] === $actor
                && $te->payload['metadata'] === $metadata
                && ($te->payload['email'] ?? null) === $contact->email;
        },
    );
});

it('delivers a configured outbound webhook end-to-end when a LeadHub event fires', function (): void {
    Queue::fake();

    // Configure an outbound webhook in the addon for one of LeadHub's triggers.
    \Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'LeadHub status → CRM',
        'handle' => 'leadhub-status-crm',
        'enabled' => true,
        'trigger_type' => 'leadhub.status.changed',
        'url' => 'https://example.test/hook',
        'method' => 'POST',
        'payload_type' => 'raw_json',
        'queue_enabled' => true,
    ]);

    $contact = Contact::factory()->create();

    event(new \Goldnead\Leadhub\Events\LeadHubStatusChanged($contact));

    // The full path ran: bridge listener → TriggerDetected → DispatchTriggerListener
    // → TriggerDispatcher → resolve-by-handle → snapshot + queued delivery.
    Queue::assertPushed(\Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob::class);

    $this->assertDatabaseHas('webhook_deliveries', [
        'trigger_type' => 'leadhub.status.changed',
        'trigger_reference' => $contact->uuid,
    ]);
});

it('does not deliver when a different trigger has no matching webhook', function (): void {
    Queue::fake();

    \Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'Only status changes',
        'handle' => 'only-status',
        'enabled' => true,
        'trigger_type' => 'leadhub.status.changed',
        'url' => 'https://example.test/hook',
        'method' => 'POST',
        'payload_type' => 'raw_json',
        'queue_enabled' => true,
    ]);

    $contact = Contact::factory()->create();

    // A note-added event must NOT match the status-changed webhook.
    event(new \Goldnead\Leadhub\Events\LeadHubNoteAdded($contact));

    Queue::assertNotPushed(\Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob::class);
});
