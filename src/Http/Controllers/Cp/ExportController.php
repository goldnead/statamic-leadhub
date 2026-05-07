<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Services\ExportService;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function __construct(protected ExportService $exports)
    {
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->hasPermission('export leadhub contacts'), 403);

        $filters = $request->only([
            'status', 'source', 'tag', 'from', 'to', 'followup', 'q', 'archived',
        ]);

        $result = $this->exports->run($filters, (string) $request->user()?->id());

        if ($result['queued'] ?? false) {
            return back()->with('success', __('leadhub::contacts.flashes.export_queued'));
        }

        return response()->download(
            $result['path'],
            $result['filename'],
            ['Content-Type' => 'text/csv; charset=UTF-8']
        )->deleteFileAfterSend();
    }
}
