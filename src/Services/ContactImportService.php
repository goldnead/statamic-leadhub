<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Jobs\ImportContactsJob;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\CustomField;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\Import\ColumnGuesser;
use Goldnead\Leadhub\Services\Import\CsvReader;
use Goldnead\Leadhub\Services\Import\ImportStore;
use Goldnead\Leadhub\Support\BulkWrite;
use Goldnead\Leadhub\Support\ContactDto;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Leadhub\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bringing contacts in from a file: stage, map, preview, run in chunks.
 *
 * The write itself is not new. Every row goes through {@see ContactResolver},
 * the resolve-or-create the form pipeline and the ingestion API share, so a
 * duplicate is merged by the same rules (email, then phone; a field that
 * already has a value is never overwritten) and the same events fire.
 *
 * Three things an import does differently, on purpose:
 *
 * - It runs as a {@see BulkWrite}: no "new lead" alert to staff and no
 *   outbound webhook per row, and nothing is sent to the contact. The people
 *   on the list were not asked just now. Contact events still fire, so
 *   segments and CRM destinations (if enabled) see every contact.
 * - It never overwrites a field that has a value, whatever the site's
 *   `overwrite_existing_fields_from_submissions` says about form submissions.
 * - It never invents consent. A contact comes in as not consented unless the
 *   file has a consent column that says yes or the person ticks the box for
 *   the whole file.
 * - It never touches an address that opted out.
 */
class ContactImportService
{
    public const STATUS_STAGED = 'staged';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> fields a column can be mapped to ('custom:*' aside) */
    public const TARGETS = [
        'email', 'first_name', 'last_name', 'full_name', 'phone', 'company',
        'postal_code', 'country', 'status', 'consent', 'tags',
    ];

    public function __construct(
        protected ImportStore $store,
        protected ContactRepository $contacts,
        protected ContactResolver $resolver,
        protected TagService $tags,
        protected TimelineService $timeline,
        protected CustomFieldService $customFields,
    ) {}

    /**
     * Read the file once for its header, delimiter, encoding and row count and
     * keep it for the next steps.
     *
     * @return array<string, mixed>
     */
    public function stage(string $tmpPath, string $filename, int $brandId, string $userId): array
    {
        $sample = (string) file_get_contents($tmpPath, false, null, 0, 65536);
        $delimiter = CsvReader::detectDelimiter($sample);
        $encoding = CsvReader::detectEncoding($sample);

        $reader = new CsvReader($tmpPath, $delimiter, $encoding);
        ['header' => $header, 'position' => $position] = $reader->header();

        if (count(array_filter($header, static fn ($h) => $h !== '')) === 0) {
            throw new \InvalidArgumentException('empty');
        }

        $total = 0;
        $cursor = $position;
        do {
            $chunk = $reader->read($cursor, 2000);
            $total += count($chunk['rows']);
            $cursor = $chunk['position'];
        } while (! $chunk['eof']);

        $id = $this->store->newId();
        $this->store->putSource($id, $tmpPath);

        $state = [
            'id' => $id,
            'brand_id' => $brandId,
            'user_id' => $userId,
            'filename' => $filename,
            'status' => self::STATUS_STAGED,
            'delimiter' => $delimiter,
            'encoding' => $encoding,
            'header' => $header,
            'start_position' => $position,
            'position' => $position,
            'total_rows' => $total,
            'row' => 0,
            'mapping' => [],
            'options' => [],
            'counts' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0],
            'issues' => [],
            'created_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'message' => null,
        ];

        $this->store->save($state);

        return $state;
    }

    /** @return list<string> */
    public function suggest(array $state): array
    {
        return (new ColumnGuesser)->guess($state['header']);
    }

    /**
     * Targets the mapping step offers. Custom fields only exist on the
     * eloquent driver.
     *
     * @return list<string>
     */
    public function allowedTargets(): array
    {
        $targets = self::TARGETS;

        foreach ($this->customFields->definitions() as $field) {
            $targets[] = 'custom:'.$field->handle;
        }

        return $targets;
    }

    /**
     * @param  array<int, mixed>  $mapping
     * @return array<string, string> error messages keyed for the form
     */
    public function validateMapping(array $state, array $mapping): array
    {
        $allowed = array_merge($this->allowedTargets(), $this->isEloquent() ? ['custom:new'] : []);
        $seen = [];

        foreach ($mapping as $index => $target) {
            if (! is_string($target) || ($target !== '' && ! in_array($target, $allowed, true)) || $index >= count($state['header'])) {
                return ['mapping' => __('leadhub::imports.errors.bad_target')];
            }

            if ($target === '' || $target === 'custom:new') {
                continue;
            }

            if (isset($seen[$target])) {
                return ['mapping' => __('leadhub::imports.errors.duplicate_target')];
            }

            $seen[$target] = true;
        }

        if (! isset($seen['email'])) {
            return ['mapping' => __('leadhub::imports.errors.email_required')];
        }

        return [];
    }

    /**
     * What the first rows would do, without writing anything.
     *
     * @param  array<int, string>  $mapping
     * @param  array<string, mixed>  $options
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, total: int}
     */
    public function preview(array $state, array $mapping, array $options): array
    {
        $reader = new CsvReader($this->store->absoluteSource($state['id']), $state['delimiter'], $state['encoding']);
        $read = $reader->read((int) $state['start_position'], (int) config('leadhub.imports.preview_rows', 8));

        $columns = [];
        foreach ($mapping as $index => $target) {
            if ($target === '') {
                continue;
            }

            $columns[] = [
                'key' => (string) $index,
                'label' => $target === 'custom:new' ? ($state['header'][$index] ?? '') : $this->targetLabel($target),
            ];
        }

        $rows = [];
        foreach ($read['rows'] as $offset => $cells) {
            $parsed = $this->parseRow($mapping, $cells, $options);
            $existing = $parsed['error'] === null ? $this->findExisting($parsed['email'], $parsed['phone']) : null;

            $outcome = match (true) {
                $parsed['error'] !== null => 'invalid',
                $existing?->do_not_contact => 'skipped',
                $existing !== null => 'update',
                default => 'create',
            };

            $rows[] = [
                'row' => $offset + 2,
                'cells' => collect($columns)->mapWithKeys(fn ($c) => [$c['key'] => $cells[(int) $c['key']] ?? ''])->all(),
                'outcome' => $outcome,
                'reason' => $parsed['error'] ? __('leadhub::imports.reasons.'.$parsed['error']) : ($outcome === 'skipped' ? __('leadhub::imports.reasons.opted_out') : null),
            ];
        }

        return ['columns' => $columns, 'rows' => $rows, 'total' => (int) $state['total_rows']];
    }

    /**
     * Fix the mapping, create the custom fields the person asked for, and hand
     * the file to the queue.
     *
     * @param  array<int, string>  $mapping
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function start(array $state, array $mapping, array $options): array
    {
        $mapping = array_pad(array_values($mapping), count($state['header']), '');

        foreach ($mapping as $index => $target) {
            if ($target === 'custom:new') {
                $mapping[$index] = 'custom:'.$this->createCustomField($state['header'][$index] ?? '');
            }
        }

        $state['mapping'] = $mapping;
        $state['options'] = [
            'tag' => trim((string) ($options['tag'] ?? '')),
            'status' => (string) ($options['status'] ?? ''),
            'consent_given' => (bool) ($options['consent_given'] ?? false),
        ];
        $state['status'] = self::STATUS_QUEUED;
        $state['queued_at'] = now()->toIso8601String();
        $this->store->save($state);

        dispatch(new ImportContactsJob($state['id']));

        return $state;
    }

    /**
     * Process one chunk. Returns true when there is nothing left to do, false
     * when the next chunk has to be queued.
     */
    public function processChunk(string $id): bool
    {
        $state = $this->store->find($id);

        if (! $state || ! in_array($state['status'], [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return true;
        }

        return (bool) BrandContext::runFor(
            (int) $state['brand_id'],
            fn () => BulkWrite::run(fn () => $this->withoutOverwriting(fn () => $this->runChunk($state))),
        );
    }

    /**
     * The overwrite switch is for form submissions: a visitor correcting their
     * own details. A list in a file is not that, and an old export must never
     * replace what a contact has told us since. Forced off for the run, put
     * back as it was.
     */
    protected function withoutOverwriting(\Closure $callback): mixed
    {
        $key = 'leadhub.overwrite_existing_fields_from_submissions';
        $before = config($key);
        config([$key => false]);

        try {
            return $callback();
        } finally {
            config([$key => $before]);
        }
    }

    /** @param  array<string, mixed>  $state */
    protected function runChunk(array $state): bool
    {
        $state['status'] = self::STATUS_RUNNING;
        $state['started_at'] ??= now()->toIso8601String();

        $reader = new CsvReader($this->store->absoluteSource($state['id']), $state['delimiter'], $state['encoding']);
        $read = $reader->read((int) $state['position'], max(1, (int) config('leadhub.imports.chunk_size', 500)));
        $limit = (int) config('leadhub.imports.issue_limit', 1000);

        foreach ($read['rows'] as $cells) {
            $state['row']++;
            $sheetRow = $state['row'] + 1;

            try {
                $result = $this->processRow($state, $cells, $sheetRow);
            } catch (\Throwable $e) {
                // One bad row must not take the other ten thousand with it.
                // The message can carry SQL and table names; the report goes to
                // whoever may import, so it gets a fixed text and the log the cause.
                Log::warning('[LeadHub] Import row failed', [
                    'import' => $state['id'], 'row' => $sheetRow, 'message' => $e->getMessage(),
                ]);
                $result = ['outcome' => 'error', 'reason' => 'exception'];
            }

            $bucket = ['create' => 'created', 'update' => 'updated', 'skipped' => 'skipped', 'error' => 'errors'][$result['outcome']];
            $state['counts'][$bucket]++;

            if (in_array($result['outcome'], ['skipped', 'error'], true) && count($state['issues']) < $limit) {
                $state['issues'][] = [
                    'row' => $sheetRow,
                    'type' => $result['outcome'],
                    'reason' => $result['reason'],
                    'detail' => $result['detail'] ?? null,
                    'cells' => $cells,
                ];
            }
        }

        $state['position'] = $read['position'];

        if ($read['eof']) {
            $state['status'] = self::STATUS_COMPLETED;
            $state['finished_at'] = now()->toIso8601String();
        }

        $this->store->save($state);

        return $read['eof'];
    }

    public function markFailed(string $id, string $message): void
    {
        $state = $this->store->find($id);

        if ($state) {
            $state['status'] = self::STATUS_FAILED;
            $state['message'] = $message;
            $state['finished_at'] = now()->toIso8601String();
            $this->store->save($state);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $cells
     * @return array{outcome: string, reason?: string, detail?: string}
     */
    protected function processRow(array $state, array $cells, int $sheetRow): array
    {
        $parsed = $this->parseRow($state['mapping'], $cells, $state['options']);

        if ($parsed['error'] !== null) {
            return ['outcome' => 'error', 'reason' => $parsed['error']];
        }

        $existing = $this->findExisting($parsed['email'], $parsed['phone']);

        // An opt-out is final. The file may be a year older than the click on
        // "unsubscribe", and nothing in it is a fresher statement than that.
        if ($existing?->do_not_contact) {
            return ['outcome' => 'skipped', 'reason' => 'opted_out'];
        }

        $dto = new ContactDto(
            email: $parsed['email'],
            firstName: $parsed['first_name'],
            lastName: $parsed['last_name'],
            fullName: $parsed['full_name'],
            phone: $parsed['phone'],
            company: $parsed['company'],
            consent: $parsed['consent'],
            // Only a contact the import creates is "from the import"; an existing one keeps its origin.
            source: $existing ? null : 'import',
            defaultStatus: $parsed['status'],
            attribution: array_filter([
                'postal_code' => $parsed['postal_code'],
                'country' => $parsed['country'],
            ]),
        );

        [$contact, $created] = $this->resolver->resolveOrCreate($dto);

        $this->applyCustomFields($contact, $parsed['custom'], $created);

        $tagNames = array_values(array_filter(array_merge(
            $state['options']['tag'] !== '' ? [$state['options']['tag']] : [],
            $parsed['tags'],
        )));

        if ($tagNames !== []) {
            $this->tags->attachMany($contact, $tagNames, silent: true);
        }

        $this->timeline->recordSource(
            $contact,
            Event::TYPE_SOURCE_INGESTED,
            __('leadhub::imports.timeline_summary', ['file' => $state['filename']]),
            [],
            'import',
            $state['id'],
            'import:'.$state['id'].':'.$sheetRow,
        );

        return ['outcome' => $created ? 'create' : 'update'];
    }

    /**
     * @param  array<int, string>  $mapping
     * @param  list<string>  $cells
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> parsed values plus 'error' (a reason key or null)
     */
    protected function parseRow(array $mapping, array $cells, array $options): array
    {
        $row = [
            'email' => null, 'first_name' => null, 'last_name' => null, 'full_name' => null,
            'phone' => null, 'company' => null, 'postal_code' => null, 'country' => null,
            'status' => ($options['status'] ?? '') !== '' ? $options['status'] : null,
            'consent' => (bool) ($options['consent_given'] ?? false),
            'tags' => [], 'custom' => [], 'error' => null,
        ];

        foreach ($mapping as $index => $target) {
            $value = trim((string) ($cells[$index] ?? ''));

            if ($target === '' || $value === '') {
                continue;
            }

            if (str_starts_with($target, 'custom:')) {
                $row['custom'][substr($target, 7)] = $value;

                continue;
            }

            match ($target) {
                'tags' => $row['tags'] = array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $value) ?: []))),
                'consent' => $row['consent'] = $row['consent'] || $this->truthy($value),
                'status' => $row['status'] = $this->resolveStatus($value) ?? ($row['error'] = 'unknown_status'),
                'country' => preg_match('/^[A-Za-z]{2}$/', $value) ? $row['country'] = strtoupper($value) : $row['error'] = 'invalid_country',
                'postal_code' => mb_strlen($value) <= 16 ? $row['postal_code'] = $value : $row['error'] = 'invalid_postal_code',
                default => $row[$target] = $value,
            };
        }

        if ($row['error'] === null) {
            if ($row['email'] === null) {
                $row['error'] = 'missing_email';
            } elseif (! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $row['error'] = 'invalid_email';
            }
        }

        return $row;
    }

    protected function truthy(string $value): bool
    {
        return in_array(mb_strtolower($value), ['1', 'ja', 'j', 'yes', 'y', 'true', 'wahr', 'x', 'subscribed', 'opt-in', 'optin'], true);
    }

    protected function resolveStatus(string $value): ?string
    {
        $statuses = (array) config('leadhub.statuses', []);

        foreach ($statuses as $key => $label) {
            if (mb_strtolower($value) === mb_strtolower((string) $key) || mb_strtolower($value) === mb_strtolower((string) $label)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** Same lookup order as {@see ContactResolver::resolveOrCreate()}. */
    protected function findExisting(?string $email, ?string $phone): ?Contact
    {
        $existing = $email ? $this->contacts->findByEmailNormalized(EmailNormalizer::normalize($email)) : null;

        if (! $existing && $phone) {
            $normalized = PhoneNormalizer::normalize($phone);
            $existing = $normalized ? $this->contacts->findByPhoneNormalized($normalized) : null;
        }

        return $existing;
    }

    /**
     * An existing contact keeps what it already has, here as everywhere else:
     * only a value for a field that is still empty is written.
     *
     * @param  array<string, string>  $values
     */
    protected function applyCustomFields(Contact $contact, array $values, bool $created): void
    {
        if ($values === [] || ! $this->isEloquent()) {
            return;
        }

        if (! $created) {
            $current = is_array($contact->custom_fields) ? $contact->custom_fields : [];
            $values = array_diff_key($values, $current);
        }

        if ($values === []) {
            return;
        }

        $this->customFields->apply($contact, $values);
        $this->contacts->save($contact);
    }

    protected function createCustomField(string $header): string
    {
        $base = Str::of(Str::ascii($header))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
        $base = $base === '' || ! ctype_alpha($base[0]) ? 'feld_'.$base : $base;
        $base = rtrim(substr($base, 0, 56), '_');

        $taken = $this->customFields->definitions()->pluck('handle')->all();
        $handle = $base;

        for ($n = 2; in_array($handle, $taken, true); $n++) {
            $handle = $base.'_'.$n;
        }

        CustomField::create(['handle' => $handle, 'label' => $header !== '' ? $header : $handle, 'type' => CustomField::TYPE_TEXT]);

        return $handle;
    }

    protected function targetLabel(string $target): string
    {
        if (str_starts_with($target, 'custom:')) {
            return substr($target, 7);
        }

        return __('leadhub::imports.targets.'.$target);
    }

    protected function isEloquent(): bool
    {
        return config('leadhub.storage.driver', 'eloquent') === 'eloquent';
    }
}
