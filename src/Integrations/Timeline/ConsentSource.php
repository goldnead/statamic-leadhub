<?php

namespace Goldnead\Leadhub\Integrations\Timeline;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\Timeline\TimelineEntry;

/**
 * What this person agreed to — read from `goldnead/statamic-consent`.
 *
 * Consent records carry **no address**, by design: the only identifier is the
 * random `consent_id` the browser holds, so that the table can never be
 * turned into a list of people. This source therefore only has something to
 * say when the contact carries that id — `metadata_json.consent_id` or
 * `custom_fields.consent_id`, put there by whatever captured the form. A
 * contact without one has no consent timeline, and that is correct, not
 * missing.
 */
class ConsentSource extends NeighbourSource
{
    protected const MODEL = '\Goldnead\StatamicConsent\Records\ConsentRecord';

    public function key(): string
    {
        return 'consent';
    }

    public function available(): bool
    {
        return $this->installed([ltrim(static::MODEL, '\\')], 'consent_records');
    }

    public function entries(Contact $contact, array $emails): array
    {
        $consentId = $this->consentIdOf($contact);

        if ($consentId === null) {
            return [];
        }

        $model = static::MODEL;
        $out = [];

        $records = $model::query()
            ->where('consent_id', $consentId)
            ->orderByDesc('decided_at')
            ->limit(200)
            ->get();

        foreach ($records as $record) {
            $granted = array_values(array_filter(array_map('strval', (array) ($record->granted ?? []))));

            $out[] = new TimelineEntry(
                id: 'consent:'.$record->getKey(),
                source: $this->key(),
                kind: 'consent.decided',
                at: $record->decided_at ?? $record->created_at,
                summary: __('leadhub::timeline.consent_decided', [
                    'version' => (string) $record->version,
                    'how' => (string) $record->how,
                    'granted' => $granted !== [] ? implode(', ', $granted) : __('leadhub::timeline.consent_none'),
                ]),
                badge: ['text' => $granted !== [] ? (string) count($granted) : '0', 'color' => $granted !== [] ? 'green' : 'default'],
                detail: [
                    ['label' => __('leadhub::timeline.detail.site'), 'value' => (string) ($record->site ?? '')],
                ],
            );
        }

        return $out;
    }

    protected function consentIdOf(Contact $contact): ?string
    {
        foreach (['metadata_json', 'custom_fields'] as $column) {
            $bag = $contact->getAttribute($column);
            if (is_array($bag) && isset($bag['consent_id']) && is_scalar($bag['consent_id'])) {
                $id = trim((string) $bag['consent_id']);

                return $id !== '' ? $id : null;
            }
        }

        return null;
    }
}
