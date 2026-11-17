<?php

namespace Goldnead\Leadhub\Jobs;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Exceptions\CrmSyncRetryableException;
use Goldnead\Leadhub\Services\CrmSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SyncContactToCrmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Identifies this dispatch across its retries: the queue hands back the
     * same serialized job, so destinations that already took the contact can
     * be skipped on the next attempt instead of receiving it twice. Null on a
     * job queued before this property existed: it then retries every
     * destination, as it would have before.
     */
    public ?string $runId = null;

    /**
     * Pass the UUID (not the model) so the job is safe for both the eloquent
     * and flat-file drivers — the contact is re-resolved on handle.
     */
    public function __construct(public string $contactUuid, public string $event)
    {
        $this->runId = (string) Str::uuid();
    }

    public function handle(CrmSyncService $sync, ContactRepository $contacts): void
    {
        $contact = $contacts->find($this->contactUuid);
        if (! $contact) {
            return;
        }

        $done = $this->runId !== null ? (array) Cache::get($this->doneKey(), []) : [];
        $results = $sync->syncContact($contact, $this->event, $done);

        $retryable = [];
        foreach ($results as $key => $result) {
            if ($result->success) {
                $done[] = $key;
            } elseif ($result->isRetryable()) {
                $retryable[$key] = $result->code;
            }
        }

        if ($retryable === []) {
            if ($this->runId !== null) {
                Cache::forget($this->doneKey());
            }

            return;
        }

        // Without the throw the queue counts the attempt as a success and
        // `tries` never comes into play: the sync failed in silence.
        if ($this->runId !== null) {
            Cache::put($this->doneKey(), array_values(array_unique($done)), now()->addDay());
        }

        throw CrmSyncRetryableException::for($this->contactUuid, $retryable);
    }

    protected function doneKey(): string
    {
        return 'leadhub:crm-sync:done:'.$this->runId;
    }
}
