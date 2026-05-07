<?php

namespace Goldnead\Leadhub\Jobs;

use Goldnead\Leadhub\Services\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExportContactsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public array $filters,
        public ?string $userId = null,
    ) {
    }

    public function handle(ExportService $exporter): void
    {
        try {
            $path = $exporter->persistCsv($this->filters);

            Log::info('[LeadHub] Export completed', [
                'path' => $path,
                'user_id' => $this->userId,
                'filters' => $this->filters,
            ]);
        } catch (\Throwable $e) {
            Log::error('[LeadHub] Export failed', [
                'message' => $e->getMessage(),
                'user_id' => $this->userId,
                'filters' => $this->filters,
            ]);
            throw $e;
        }
    }
}
