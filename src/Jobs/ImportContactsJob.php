<?php

namespace Goldnead\Leadhub\Jobs;

use Goldnead\Leadhub\Services\ContactImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One chunk of a contact import. When the file has more rows than the chunk
 * size, the job queues its own successor, so a large list is many short jobs
 * rather than one that has to survive a worker timeout.
 *
 * Carries the import's id and nothing else: the mapping, the position and the
 * counts live in the import's state file, so a job that is retried or run
 * twice cannot disagree with the one before it.
 */
class ImportContactsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    // A chunk is not safe to run twice: the counts it adds would be added
    // twice. A failure is recorded on the import instead and shown there.
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $importId) {}

    public function handle(ContactImportService $import): void
    {
        if (! $import->processChunk($this->importId)) {
            dispatch(new self($this->importId));
        }
    }

    public function failed(\Throwable $e): void
    {
        app(ContactImportService::class)->markFailed($this->importId, $e->getMessage());
    }
}
