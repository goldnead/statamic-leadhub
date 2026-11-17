<?php

namespace Goldnead\Leadhub\Jobs;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Exceptions\CrmRemovalFailedException;
use Goldnead\Leadhub\Services\CrmSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Retries an opt-out removal for the destinations that did not take it on
 * the first try. Throws while any of them still fails, so the queue retries;
 * once the attempts are used up, failed() puts it on the contact's timeline.
 */
class RemoveContactFromCrmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    /**
     * Identifies this dispatch across its retries, so a destination that took
     * the removal on an earlier attempt is not asked again.
     */
    public string $runId;

    /**
     * @param  list<string>  $destinations  destination keys still to remove from
     */
    public function __construct(public string $contactUuid, public array $destinations)
    {
        $this->runId = (string) Str::uuid();
    }

    public function handle(CrmSyncService $sync, ContactRepository $contacts): void
    {
        $contact = $contacts->find($this->contactUuid);
        if (! $contact) {
            return;
        }

        $done = (array) Cache::get($this->doneKey(), []);
        $results = $sync->removeContact($contact, $this->destinations, $done);

        $failures = [];
        foreach ($results as $key => $result) {
            if ($result->success) {
                $done[] = $key;
            } else {
                $failures[$key] = $result->message;
            }
        }

        if ($failures === []) {
            Cache::forget($this->doneKey());

            return;
        }

        Cache::put($this->doneKey(), array_values(array_unique($done)), now()->addDays(2));

        throw CrmRemovalFailedException::for($this->contactUuid, $failures);
    }

    public function failed(?\Throwable $e = null): void
    {
        $done = (array) Cache::get($this->doneKey(), []);
        Cache::forget($this->doneKey());

        $open = array_values(array_diff($this->destinations, $done));
        if ($open === []) {
            return;
        }

        Log::error('[LeadHub] Opt-out removal gave up', [
            'contact_uuid' => $this->contactUuid,
            'destinations' => $open,
            'message' => $e?->getMessage(),
        ]);

        $contact = app(ContactRepository::class)->find($this->contactUuid);
        if ($contact) {
            app(CrmSyncService::class)->reportRemovalGaveUp($contact, $open, $e?->getMessage());
        }
    }

    protected function doneKey(): string
    {
        return 'leadhub:crm-remove:done:'.$this->runId;
    }
}
