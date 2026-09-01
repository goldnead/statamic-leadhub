<?php

namespace Goldnead\Leadhub\Integrations\Timeline;

use Carbon\Carbon;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\Timeline\TimelineEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What this person may open — read from `goldnead/statamic-entitlements`.
 *
 * A grant is addressed to a subject pair, not to a contact. The family's
 * convention for a buyer is `('email', lower(trim(address)))`, and a grant made
 * to the contact record itself is `(Contact morph class, id)`; both are
 * matched. One entry when access was granted, another when it was revoked,
 * another when it ran out — each dated by the column that records it, and
 * the state badge always says what is true *now*.
 *
 * The state comes from the entitlements manager, never recomputed here:
 * "expired but inside the grace period" is their rule to get right, not ours.
 */
class EntitlementsSource extends NeighbourSource
{
    protected const FACADE = '\Goldnead\Entitlements\Facades\Entitlements';

    public function key(): string
    {
        return 'entitlements';
    }

    public function available(): bool
    {
        return $this->installed([ltrim(static::FACADE, '\\')], 'entitlements');
    }

    public function entries(Contact $contact, array $emails): array
    {
        $out = [];

        foreach ($this->grants($contact, $emails) as $grant) {
            $product = (string) $grant->product_slug;
            $state = $this->state($grant);
            $stateLabel = $this->label('entitlement_state', $state, $state);
            $url = $this->cpLink('entitlements.show', [$grant->getKey()]);
            $meta = is_array($grant->meta) ? $grant->meta : [];

            $detail = [
                ['label' => __('leadhub::timeline.detail.source'), 'value' => trim((string) $grant->source.' '.(string) ($grant->source_ref ?? ''))],
                ['label' => __('leadhub::timeline.detail.expires'), 'value' => $grant->expires_at ? $this->when($grant->expires_at) : ''],
                ['label' => __('leadhub::timeline.detail.note'), 'value' => (string) ($meta['note'] ?? '')],
                ['label' => __('leadhub::timeline.detail.granted_by'), 'value' => (string) ($meta['granted_by_label'] ?? $meta['granted_by'] ?? '')],
            ];

            $out[] = new TimelineEntry(
                id: 'entitlement:'.$grant->getKey(),
                source: $this->key(),
                kind: 'entitlement.granted',
                at: $grant->starts_at ?? $grant->created_at,
                summary: __('leadhub::timeline.entitlement_granted', ['product' => $product]),
                url: $url,
                badge: ['text' => $stateLabel, 'color' => $this->color($state)],
                detail: $detail,
            );

            if ($grant->revoked_at) {
                $out[] = new TimelineEntry(
                    id: 'entitlement:'.$grant->getKey().':revoked',
                    source: $this->key(),
                    kind: 'entitlement.revoked',
                    at: $grant->revoked_at,
                    summary: __('leadhub::timeline.entitlement_revoked', ['product' => $product]),
                    url: $url,
                    badge: ['text' => $this->label('entitlement_state', 'revoked'), 'color' => 'red'],
                    detail: [
                        ['label' => __('leadhub::timeline.detail.reason'), 'value' => (string) ($grant->revoked_reason ?? '')],
                    ],
                );
            } elseif ($state === 'expired' && $grant->expires_at) {
                $out[] = new TimelineEntry(
                    id: 'entitlement:'.$grant->getKey().':expired',
                    source: $this->key(),
                    kind: 'entitlement.expired',
                    at: $grant->expires_at,
                    summary: __('leadhub::timeline.entitlement_expired', ['product' => $product]),
                    url: $url,
                    badge: ['text' => $stateLabel, 'color' => 'default'],
                );
            }
        }

        return $out;
    }

    public function stats(Contact $contact, array $emails): array
    {
        $active = 0;

        foreach ($this->grants($contact, $emails) as $grant) {
            if (in_array($this->state($grant), ['active', 'grace_period'], true)) {
                $active++;
            }
        }

        return ['active_access' => $active];
    }

    /**
     * @param  list<string>  $emails
     * @return iterable<int, object>
     */
    protected function grants(Contact $contact, array $emails): iterable
    {
        return $this->remember($contact, $emails, fn () => $this->loadGrants($contact, $emails));
    }

    /**
     * @param  list<string>  $emails
     * @return iterable<int, object>
     */
    protected function loadGrants(Contact $contact, array $emails): iterable
    {
        $query = $this->query();

        $query->where(function ($q) use ($contact, $emails) {
            if ($emails !== []) {
                $q->where(function ($byEmail) use ($emails) {
                    $byEmail->where('subject_type', 'email')
                        ->whereIn(DB::raw('LOWER(TRIM(subject_id))'), $emails);
                });
            }

            $key = $contact->getKey();
            if ($key !== null && $key !== '') {
                $q->orWhere(function ($byContact) use ($contact, $key) {
                    $byContact->where('subject_type', $contact->getMorphClass())
                        ->where('subject_id', (string) $key);
                });
            }
        });

        return $query->orderByDesc('created_at')->limit(500)->get();
    }

    /** @return Builder<Model> */
    protected function query()
    {
        $facade = static::FACADE;

        return $facade::query();
    }

    /** The entitlement manager's verdict, as its enum's string value. */
    protected function state(object $grant): string
    {
        $facade = static::FACADE;
        $state = $facade::stateOf($grant);

        return is_object($state) && property_exists($state, 'value') ? (string) $state->value : (string) $state;
    }

    protected function color(string $state): string
    {
        return match ($state) {
            'active' => 'green',
            'grace_period' => 'amber',
            'scheduled' => 'blue',
            'pending' => 'amber',
            'revoked' => 'red',
            default => 'default',
        };
    }

    protected function when(mixed $date): string
    {
        try {
            return Carbon::parse($date)->locale((string) app()->getLocale())->isoFormat('L');
        } catch (\Throwable) {
            return (string) $date;
        }
    }
}
