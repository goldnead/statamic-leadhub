<?php

namespace Goldnead\Leadhub\Jobs;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Services\CrmSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncContactToCrmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Pass the UUID (not the model) so the job is safe for both the eloquent
     * and flat-file drivers — the contact is re-resolved on handle.
     */
    public function __construct(public string $contactUuid, public string $event)
    {
    }

    public function handle(CrmSyncService $sync, ContactRepository $contacts): void
    {
        $contact = $contacts->find($this->contactUuid);
        if ($contact) {
            $sync->syncContact($contact, $this->event);
        }
    }
}
