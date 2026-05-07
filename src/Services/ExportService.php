<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Jobs\ExportContactsJob;
use Goldnead\Leadhub\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class ExportService
{
    /**
     * Run an export. If the matching contact count exceeds the configured
     * threshold, the export is queued; otherwise it's generated immediately.
     *
     * Returns either:
     *   ['queued' => true, 'job_id' => string|null]
     * or
     *   ['queued' => false, 'path' => string, 'filename' => string]
     */
    public function run(array $filters, ?string $userId = null): array
    {
        $threshold = (int) config('leadhub.exports.queue_threshold', 1000);

        $count = $this->buildQuery($filters)->count();

        if ($count >= $threshold) {
            $job = new ExportContactsJob($filters, $userId);
            dispatch($job);

            return ['queued' => true, 'job_id' => null, 'count' => $count];
        }

        $path = $this->generateCsv($filters);
        $filename = 'leadhub-contacts-'.now()->format('Y-m-d-His').'.csv';

        return ['queued' => false, 'path' => $path, 'filename' => $filename, 'count' => $count];
    }

    /**
     * Generate the CSV file synchronously and return the absolute path.
     * Caller is responsible for cleaning up the file (e.g. via deleteFileAfterSend).
     */
    public function generateCsv(array $filters): string
    {
        $tmpDir = sys_get_temp_dir();
        $path = $tmpDir.'/leadhub-contacts-'.uniqid().'.csv';

        $handle = fopen($path, 'w');
        if (! $handle) {
            throw new \RuntimeException('Could not open temp file for export: '.$path);
        }

        // BOM for Excel UTF-8 compatibility.
        fwrite($handle, "\xEF\xBB\xBF");

        $columns = [
            'id', 'name', 'email', 'phone', 'company', 'status',
            'tags', 'source', 'created_at', 'last_activity_at',
            'followup_due_at', 'consent',
        ];
        fputcsv($handle, $columns);

        $this->buildQuery($filters)
            ->with(['tags', 'followups'])
            ->orderBy('id')
            ->chunk(500, function ($contacts) use ($handle): void {
                foreach ($contacts as $contact) {
                    /** @var Contact $contact */
                    $active = $contact->followups->whereNull('completed_at')->sortBy('due_at')->first();

                    fputcsv($handle, [
                        $contact->id,
                        $contact->displayName(),
                        $contact->email,
                        $contact->phone,
                        $contact->company,
                        $contact->status,
                        $contact->tags->pluck('name')->implode(','),
                        $contact->source_form,
                        $contact->created_at?->toIso8601String(),
                        $contact->last_activity_at?->toIso8601String(),
                        $active?->due_at?->toIso8601String(),
                        $contact->consent ? '1' : '0',
                    ]);
                }
            });

        fclose($handle);

        return $path;
    }

    /**
     * Persist a CSV to the configured disk and return the storage path.
     * Used by the queued ExportContactsJob.
     */
    public function persistCsv(array $filters): string
    {
        $tmp = $this->generateCsv($filters);
        $disk = config('leadhub.exports.disk', 'local');
        $directory = trim((string) config('leadhub.exports.directory', 'leadhub/exports'), '/');
        $relative = $directory.'/leadhub-contacts-'.now()->format('Y-m-d-His').'.csv';

        Storage::disk($disk)->put($relative, file_get_contents($tmp));
        @unlink($tmp);

        return $relative;
    }

    protected function buildQuery(array $filters): Builder
    {
        $query = Contact::query();

        $query = (! empty($filters['archived']))
            ? $query->archived()
            : $query->active();

        if (! empty($filters['status'])) {
            $query->withStatus($filters['status']);
        }

        if (! empty($filters['source'])) {
            $query->where('source_form', $filters['source']);
        }

        if (! empty($filters['tag'])) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('leadhub_tags.id', $filters['tag']));
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        if (! empty($filters['followup'])) {
            switch ($filters['followup']) {
                case 'has':
                    $query->whereHas('followups', fn (Builder $q) => $q->whereNull('completed_at'));
                    break;
                case 'today':
                    $query->whereHas('followups', fn (Builder $q) => $q->dueToday());
                    break;
                case 'overdue':
                    $query->whereHas('followups', fn (Builder $q) => $q->overdue());
                    break;
            }
        }

        if (! empty($filters['q'])) {
            $query->search($filters['q']);
        }

        return $query;
    }
}
