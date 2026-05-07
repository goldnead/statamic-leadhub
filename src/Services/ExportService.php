<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Jobs\ExportContactsJob;
use Goldnead\Leadhub\Models\Contact;
use Illuminate\Support\Facades\Storage;

class ExportService
{
    public function __construct(protected ContactRepository $contacts)
    {
    }

    public function run(array $filters, ?string $userId = null): array
    {
        $threshold = (int) config('leadhub.exports.queue_threshold', 1000);

        // Cheap count — get the paginator total without loading rows.
        $count = $this->contacts->paginate($filters, perPage: 1, page: 1)->total();

        if ($count >= $threshold) {
            dispatch(new ExportContactsJob($filters, $userId));

            return ['queued' => true, 'count' => $count];
        }

        $path = $this->generateCsv($filters);
        $filename = 'leadhub-contacts-'.now()->format('Y-m-d-His').'.csv';

        return ['queued' => false, 'path' => $path, 'filename' => $filename, 'count' => $count];
    }

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

        fputcsv($handle, [
            'id', 'name', 'email', 'phone', 'company', 'status',
            'tags', 'source', 'created_at', 'last_activity_at',
            'followup_due_at', 'consent',
        ]);

        $page = 1;
        do {
            $paginator = $this->contacts->paginate($filters, perPage: 500, page: $page);

            foreach ($paginator->items() as $contact) {
                /** @var Contact $contact */
                $tags = $contact->getRelation('tags') ?? collect();
                $followups = $contact->getRelation('followups') ?? collect();
                $active = $followups instanceof \Illuminate\Support\Collection
                    ? $followups->whereNull('completed_at')->sortBy('due_at')->first()
                    : null;

                fputcsv($handle, [
                    $contact->id,
                    $contact->displayName(),
                    $contact->email,
                    $contact->phone,
                    $contact->company,
                    $contact->status,
                    $tags->pluck('name')->implode(','),
                    $contact->source_form,
                    $contact->created_at?->toIso8601String(),
                    $contact->last_activity_at?->toIso8601String(),
                    $active?->due_at?->toIso8601String(),
                    $contact->consent ? '1' : '0',
                ]);
            }

            $page++;
        } while ($paginator->hasMorePages());

        fclose($handle);

        return $path;
    }

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
}
