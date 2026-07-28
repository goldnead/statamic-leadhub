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
        // Not every LeadHub event extends LeadHubEvent. LeadHubContactScoreChanged
        // carries a score-specific payload (old/new/delta/reason) instead of the
        // generic actor/metadata shape, but it does carry the contact — and
        // without this branch it would be handed to the payload builder as if
        // the event itself were the contact, producing a webhook with no
        // reference and a body full of nothing.
        $contact = match (true) {
            $source instanceof LeadHubEvent => $source->contact,
            is_object($source) && property_exists($source, 'contact') => $source->contact,
            default => $source,
        };

        $reference = (string) ($contact->uuid ?? $contact->id ?? '');

        $payload = array_merge(
            method_exists($contact, 'toArray') ? $contact->toArray() : (array) $contact,
            [
                'uuid' => $reference !== '' ? $reference : null,
                'leadhub_event' => $this->handle,
                'actor' => $source instanceof LeadHubEvent ? $source->actor : null,
                // For events with their own payload shape, that payload is the
                // metadata — a score webhook without old/new/delta would say
                // only that something changed.
                'metadata' => match (true) {
                    $source instanceof LeadHubEvent => $source->metadata,
                    is_object($source) && method_exists($source, 'toArray') => $source->toArray(),
                    default => [],
                },
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
