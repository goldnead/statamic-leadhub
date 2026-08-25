<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Jobs\ExportContactsJob;
use Goldnead\Leadhub\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ExportService
{
    public function __construct(protected ContactRepository $contacts) {}

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
                $tags = $contact->relationLoaded('tags') ? $contact->getRelation('tags') : collect();
                $followups = $contact->relationLoaded('followups') ? $contact->getRelation('followups') : collect();
                $active = $followups instanceof Collection
                    ? $followups->whereNull('completed_at')->sortBy('due_at')->first()
                    : null;

                fputcsv($handle, array_map($this->zelle(...), [
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
                ]));
            }

            $page++;
        } while ($paginator->hasMorePages());

        fclose($handle);

        return $path;
    }

    /**
     * A cell, as a string. `null` is an empty cell, not the word "null".
     *
     * A leading `=`, `+`, `-`, `@`, tab or carriage return is neutralised with
     * a leading apostrophe, because Excel and LibreOffice execute such a cell
     * as a formula the moment the file is opened — and the person opening it
     * is whoever holds `export leadhub contacts`.
     *
     * This export is the one that needed it most and was the one without it.
     * Every name, company and tag in here can come from a stranger filling in
     * a public form, so an attacker picks the content of a cell that a
     * colleague later opens on their own machine. The BOM written a few lines
     * up is what makes a spreadsheet treat the file as a table rather than
     * plain text, which is what turns the route from theoretical into
     * reliable.
     *
     * The apostrophe is the spreadsheet's own "this is text" marker: it is
     * consumed on import and does not become part of the value.
     */
    protected function zelle(mixed $wert): string
    {
        if ($wert === null) {
            return '';
        }

        if (is_bool($wert)) {
            return $wert ? '1' : '0';
        }

        $wert = (string) $wert;

        if ($wert !== '' && str_contains("=+-@\t\r", $wert[0])) {
            return "'".$wert;
        }

        return $wert;
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
