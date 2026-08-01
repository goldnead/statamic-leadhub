<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\SupportsContactRemoval;
use Goldnead\Leadhub\Crm\DestinationManager;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\SyncLog;
use Goldnead\Leadhub\Support\SyncResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Pushes a contact to every CRM destination configured for an event, recording
 * the outcome both on the contact timeline (works in both storage drivers) and
 * in the leadhub_sync_logs table (eloquent — for the CP Sync log page).
 */
class CrmSyncService
{
    public function __construct(
        protected DestinationManager $destinations,
        protected TimelineService $timeline,
    ) {}

    /**
     * @return array<string, SyncResult> keyed by destination key
     */
    public function syncContact(Contact $contact, string $event): array
    {
        // Compliance: never push opted-out contacts to external CRMs.
        if ($contact->do_not_contact) {
            return [];
        }

        $results = [];

        foreach ($this->destinations->for($event) as $key => $destination) {
            try {
                $result = $destination->push($contact);
            } catch (\Throwable $e) {
                $result = SyncResult::fail($e->getMessage());
            }

            $this->record($contact, $key, $destination->driver(), $event, $result);
            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * Remove / unsubscribe a contact from every destination that supports it.
     * Called when a contact opts out (do_not_contact) so external lists stay
     * compliant.
     *
     * @return array<string, SyncResult>
     */
    public function removeContact(Contact $contact): array
    {
        $results = [];

        foreach ($this->destinations->enabled() as $key => $destination) {
            if (! $destination instanceof SupportsContactRemoval) {
                continue;
            }

            try {
                $result = $destination->remove($contact);
            } catch (\Throwable $e) {
                $result = SyncResult::fail($e->getMessage());
            }

            $this->record($contact, $key, $destination->driver(), 'removed', $result);
            $results[$key] = $result;
        }

        return $results;
    }

    protected function record(Contact $contact, string $key, string $driver, string $event, SyncResult $result): void
    {
        // Per-contact timeline event (both drivers).
        $this->timeline->record(
            $contact,
            $result->success ? Event::TYPE_CRM_SYNC_SUCCEEDED : Event::TYPE_CRM_SYNC_FAILED,
            $result->success
                ? __('leadhub::crm.synced', ['destination' => $key])
                : __('leadhub::crm.sync_failed', ['destination' => $key]),
            array_filter([
                'destination' => $key,
                'driver' => $driver,
                'remote_id' => $result->remoteId,
                'code' => $result->code,
                'message' => $result->message,
            ], fn ($v) => $v !== null && $v !== ''),
        );

        // Detailed sync log (eloquent only — guard for flat/no-DB installs).
        try {
            if (Schema::hasTable('leadhub_sync_logs')) {
                SyncLog::create([
                    'contact_uuid' => (string) $contact->uuid,
                    'contact_label' => $contact->displayName(),
                    'destination' => $key,
                    'driver' => $driver,
                    'event' => $event,
                    'status' => $result->success ? SyncLog::STATUS_SUCCESS : SyncLog::STATUS_FAILED,
                    'response_code' => $result->code,
                    'message' => $result->success ? $result->remoteId : $result->message,
                    'remote_id' => $result->remoteId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[LeadHub] Could not write CRM sync log', ['message' => $e->getMessage()]);
        }
    }
}
