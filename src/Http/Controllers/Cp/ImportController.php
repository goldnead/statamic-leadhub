<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\Leadhub\Services\ContactImportService;
use Goldnead\Leadhub\Services\Import\ImportStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importing contacts from a CSV file or from text pasted out of Excel.
 *
 * Every action needs `import leadhub contacts`, and an import is only ever
 * reachable from the brand it was made in: the state file carries the brand
 * id, and an id from another brand answers 404 like a missing one.
 */
class ImportController extends Controller
{
    protected const PERMISSION = 'import leadhub contacts';

    public function __construct(
        protected ImportStore $store,
        protected ContactImportService $import,
    ) {}

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        $history = collect($this->store->history(BrandContext::currentId()))
            ->map(fn (array $s): array => $this->summary($s))
            ->all();

        return Inertia::render('leadhub::Imports/Create', [
            'disclaimer' => $this->disclaimer(),
            'uploadUrl' => cp_route('leadhub.import.upload'),
            'cancelUrl' => cp_route('leadhub.contacts.index'),
            'maxKb' => (int) config('leadhub.imports.max_upload_kb', 10240),
            'history' => $history,
        ]);
    }

    public function upload(Request $request)
    {
        $this->authorizeOrFail($request, self::PERMISSION);

        $request->validate([
            'file' => ['nullable', 'required_without:text', 'file', 'extensions:csv,txt,tsv', 'max:'.(int) config('leadhub.imports.max_upload_kb', 10240)],
            'text' => ['nullable', 'required_without:file', 'string', 'max:2000000'],
        ]);

        $cleanup = null;

        if ($request->hasFile('file')) {
            $path = $request->file('file')->getRealPath();
            $name = $request->file('file')->getClientOriginalName();
        } else {
            $path = $cleanup = tempnam(sys_get_temp_dir(), 'leadhub-paste-');
            file_put_contents($path, (string) $request->input('text'));
            $name = __('leadhub::imports.pasted_name');
        }

        try {
            $state = $this->import->stage($path, $name, BrandContext::currentId(), $this->userId($request));
        } catch (\InvalidArgumentException) {
            return back()->withErrors([$request->hasFile('file') ? 'file' : 'text' => __('leadhub::imports.errors.empty_file')]);
        } finally {
            if ($cleanup) {
                @unlink($cleanup);
            }
        }

        return redirect()->to(cp_route('leadhub.import.show', $state['id']));
    }

    public function show(Request $request, string $leadhubImport)
    {
        $this->authorizeOrFail($request, self::PERMISSION);
        $state = $this->load($leadhubImport);

        if ($state['status'] === ContactImportService::STATUS_STAGED) {
            return $this->mapPage($state, null);
        }

        return Inertia::render('leadhub::Imports/Show', [
            'batch' => $this->summary($state) + [
                'issues' => collect($state['issues'])->take(50)->map(fn (array $i): array => [
                    'row' => $i['row'],
                    'type' => $i['type'],
                    'reason' => $this->reason($i),
                    'cells' => implode(' · ', array_filter($i['cells'])),
                ])->all(),
                'issues_total' => $state['counts']['skipped'] + $state['counts']['errors'],
                'errors_url' => $state['issues'] ? cp_route('leadhub.import.errors', $state['id']) : null,
            ],
            'backUrl' => cp_route('leadhub.import.create'),
            'contactsUrl' => cp_route('leadhub.contacts.index'),
            'refreshUrl' => cp_route('leadhub.import.show', $state['id']),
        ]);
    }

    public function preview(Request $request, string $leadhubImport)
    {
        $this->authorizeOrFail($request, self::PERMISSION);
        $state = $this->load($leadhubImport);
        abort_unless($state['status'] === ContactImportService::STATUS_STAGED, 404);

        [$mapping, $options] = $this->input($request);

        if ($errors = $this->import->validateMapping($state, $mapping)) {
            return back()->withErrors($errors);
        }

        return $this->mapPage($state, $this->import->preview($state, $mapping, $options));
    }

    public function run(Request $request, string $leadhubImport)
    {
        $this->authorizeOrFail($request, self::PERMISSION);
        $state = $this->load($leadhubImport);
        $show = cp_route('leadhub.import.show', $state['id']);

        // Already started (a double click, a second tab): show it, do not start it again.
        if ($state['status'] !== ContactImportService::STATUS_STAGED) {
            return redirect()->to($show);
        }

        [$mapping, $options] = $this->input($request);

        if ($errors = $this->import->validateMapping($state, $mapping)) {
            return back()->withErrors($errors);
        }

        // staged -> queued has to happen once. The lock covers the gap between
        // reading the state above and writing "queued" in start(): two requests
        // in that gap would both see "staged" and queue the file twice.
        $started = Cache::lock('leadhub:import:'.$state['id'], 30)->get(function () use ($state, $mapping, $options): bool {
            $fresh = $this->store->find($state['id']);

            if (! $fresh || $fresh['status'] !== ContactImportService::STATUS_STAGED) {
                return false;
            }

            $this->import->start($fresh, $mapping, $options);

            return true;
        });

        if (! $started) {
            return redirect()->to($show);
        }

        return redirect()->to(cp_route('leadhub.import.show', $state['id']))
            ->with('success', __('leadhub::imports.flashes.started'));
    }

    public function errors(Request $request, string $leadhubImport): StreamedResponse
    {
        $this->authorizeOrFail($request, self::PERMISSION);
        $state = $this->load($leadhubImport);

        $callback = function () use ($state): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // The header is the uploaded file's own first row: as untrusted as any cell below it.
            fputcsv($out, array_map($this->safeCell(...), array_merge([__('leadhub::imports.report.row'), __('leadhub::imports.report.type'), __('leadhub::imports.report.reason')], $state['header'])), ',', '"', '');

            foreach ($state['issues'] as $issue) {
                fputcsv($out, array_map($this->safeCell(...), array_merge(
                    [$issue['row'], __('leadhub::imports.report.types.'.$issue['type']), $this->reason($issue)],
                    $issue['cells'],
                )), ',', '"', '');
            }

            fclose($out);
        };

        return response()->streamDownload($callback, 'leadhub-import-'.substr($state['id'], 0, 8).'-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    protected function load(string $id): array
    {
        $state = $this->store->find($id);

        abort_unless($state && (int) $state['brand_id'] === BrandContext::currentId(), 404);

        return $state;
    }

    /** @return array{0: array<int, string>, 1: array<string, mixed>} */
    protected function input(Request $request): array
    {
        $statuses = array_keys((array) config('leadhub.statuses', []));

        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:80'],
            'tag' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', $statuses)],
            'consent_given' => ['nullable', 'boolean'],
        ]);

        return [
            array_map(static fn ($t) => (string) $t, array_values($data['mapping'])),
            [
                'tag' => $data['tag'] ?? '',
                'status' => $data['status'] ?? '',
                'consent_given' => (bool) ($data['consent_given'] ?? false),
            ],
        ];
    }

    /** @param  array<string, mixed>  $state */
    protected function mapPage(array $state, ?array $preview)
    {
        $statuses = (array) config('leadhub.statuses', []);

        $targets = collect(ContactImportService::TARGETS)
            ->map(fn (string $t): array => ['value' => $t, 'label' => __('leadhub::imports.targets.'.$t)]);

        foreach ($this->import->allowedTargets() as $target) {
            if (str_starts_with($target, 'custom:')) {
                $targets->push(['value' => $target, 'label' => __('leadhub::imports.custom_field', ['field' => substr($target, 7)])]);
            }
        }

        if (config('leadhub.storage.driver', 'eloquent') === 'eloquent') {
            $targets->push(['value' => 'custom:new', 'label' => __('leadhub::imports.targets.custom_new')]);
        }

        return Inertia::render('leadhub::Imports/Map', [
            'batch' => [
                'id' => $state['id'],
                'filename' => $state['filename'],
                'total_rows' => $state['total_rows'],
                'header' => $state['header'],
            ],
            'suggested' => $this->import->suggest($state),
            'targets' => $targets->values()->all(),
            'statuses' => collect($statuses)->map(fn ($label, $key) => ['value' => (string) $key, 'label' => (string) $label])->values()->all(),
            'defaultStatus' => (string) config('leadhub.default_status', 'new'),
            'disclaimer' => $this->disclaimer(),
            'preview' => $preview,
            'previewUrl' => cp_route('leadhub.import.preview', $state['id']),
            'runUrl' => cp_route('leadhub.import.run', $state['id']),
            'cancelUrl' => cp_route('leadhub.import.create'),
        ]);
    }

    /** @return list<string> */
    protected function disclaimer(): array
    {
        return [
            __('leadhub::imports.disclaimer.duplicates'),
            __('leadhub::imports.disclaimer.no_mail'),
            __('leadhub::imports.disclaimer.sync'),
            __('leadhub::imports.disclaimer.permission'),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function summary(array $state): array
    {
        $done = $state['row'];
        $total = max(1, (int) $state['total_rows']);

        return [
            'id' => $state['id'],
            'filename' => $state['filename'],
            'status' => $state['status'],
            'status_label' => __('leadhub::imports.status.'.$state['status']),
            'finished' => in_array($state['status'], [ContactImportService::STATUS_COMPLETED, ContactImportService::STATUS_FAILED], true),
            'message' => $state['message'],
            'total_rows' => (int) $state['total_rows'],
            'processed' => $done,
            'percent' => (int) min(100, round($done / $total * 100)),
            'counts' => $state['counts'],
            'created_at' => $state['created_at'],
            'url' => cp_route('leadhub.import.show', $state['id']),
        ];
    }

    /** @param  array<string, mixed>  $issue */
    protected function reason(array $issue): string
    {
        $text = __('leadhub::imports.reasons.'.$issue['reason']);

        return ($issue['detail'] ?? null) ? $text.': '.$issue['detail'] : $text;
    }

    /** Same guard as the contact export: a spreadsheet runs a cell that starts like a formula. */
    protected function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
