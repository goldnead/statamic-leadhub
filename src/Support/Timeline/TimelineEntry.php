<?php

namespace Goldnead\Leadhub\Support\Timeline;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * One line on the merged contact timeline.
 *
 * Deliberately a value object with a fixed shape rather than "whatever the
 * source returned": the Vue side renders every entry the same way, and a
 * source that could hand over markup or a component name would make its build
 * a dependency of LeadHub's screen.
 *
 * `kind` is a dotted vocabulary the view keys colours and icons on:
 * `payment.paid`, `payment.open`, `payment.failed`, `payment.refunded`,
 * `entitlement.granted`, `entitlement.expired`, `entitlement.revoked`,
 * `booking.<status>`, `consent.decided`, and `leadhub.<event type>` for
 * LeadHub's own events.
 */
final class TimelineEntry
{
    /**
     * @param  array<int, array{label: string, value: string}>  $detail
     * @param  array{text: string, color: string}|null  $badge
     * @param  array{cent: int, currency: string}|null  $amount
     */
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $kind,
        public readonly ?DateTimeInterface $at,
        public readonly string $summary,
        public readonly ?string $url = null,
        public readonly ?array $badge = null,
        public readonly ?array $amount = null,
        public readonly array $detail = [],
        public readonly ?string $actor = null,
        /** Raw data behind the line, shown folded. Empty for most sources. */
        public readonly array $payload = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'kind' => $this->kind,
            'at' => $this->at?->format(DateTimeInterface::ATOM),
            'at_human' => $this->at ? Carbon::instance($this->at)->diffForHumans() : null,
            'summary' => $this->summary,
            'url' => $this->url,
            'badge' => $this->badge,
            'amount' => $this->amount ? [
                'cent' => $this->amount['cent'],
                'currency' => $this->amount['currency'],
                'formatted' => Amount::format($this->amount['cent'], $this->amount['currency']),
            ] : null,
            'detail' => array_values(array_filter(
                $this->detail,
                fn ($line) => is_array($line) && isset($line['label'], $line['value']) && $line['value'] !== '',
            )),
            'actor' => $this->actor,
            'payload' => $this->payload,
        ];
    }
}
