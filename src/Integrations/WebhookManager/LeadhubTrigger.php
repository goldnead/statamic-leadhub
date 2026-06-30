<?php

namespace Goldnead\Leadhub\Integrations\WebhookManager;

use Goldnead\Leadhub\Events\LeadHubEvent;
use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;

/**
 * Exposes a single LeadHub lifecycle event as a webhook-manager trigger.
 *
 * One instance is registered per LeadHub event (see {@see WebhookManagerBridge}),
 * which makes the event selectable in the Webhook Manager Control Panel and lets
 * it fire outbound webhooks like any of the addon's built-in triggers.
 *
 * This class is only ever loaded when goldnead/statamic-webhook-manager is
 * installed — the bridge guards instantiation behind a class_exists() check —
 * so referencing the addon's contracts here is safe.
 */
class LeadhubTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $handle,
        private readonly string $label,
    ) {
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sourceType(): string
    {
        return 'leadhub_contact';
    }

    /**
     * Build a normalized TriggerEvent from a LeadHub event.
     *
     * @param  mixed  $source  The dispatched LeadHubEvent instance.
     */
    public function build(mixed $source, array $context = []): TriggerEvent
    {
        $contact = $source instanceof LeadHubEvent ? $source->contact : $source;
        $reference = (string) ($contact->uuid ?? $contact->id ?? '');

        $payload = array_merge(
            method_exists($contact, 'toArray') ? $contact->toArray() : (array) $contact,
            [
                'uuid' => $reference !== '' ? $reference : null,
                'leadhub_event' => $this->handle,
                'actor' => $source instanceof LeadHubEvent ? $source->actor : null,
                'metadata' => $source instanceof LeadHubEvent ? $source->metadata : [],
            ],
        );

        return new TriggerEvent(
            triggerHandle: $this->handle,
            sourceType: $this->sourceType(),
            sourceReference: $reference !== '' ? $reference : null,
            payload: $payload,
            site: null,
            locale: null,
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: new \DateTimeImmutable(),
        );
    }
}
