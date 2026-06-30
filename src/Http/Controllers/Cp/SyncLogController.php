<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\SyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SyncLogController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');

        $logs = [];
        if (Schema::hasTable('leadhub_sync_logs')) {
            $logs = SyncLog::query()
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (SyncLog $log) => [
                    'id' => (string) $log->id,
                    'contact_label' => $log->contact_label,
                    'contact_url' => $log->contact_uuid
                        ? cp_route('leadhub.contacts.show', $log->contact_uuid)
                        : null,
                    'destination' => $log->destination,
                    'driver' => $log->driver,
                    'event' => $log->event,
                    'status' => $log->status,
                    'response_code' => $log->response_code,
                    'message' => $log->message,
                    'created_at' => $log->created_at?->diffForHumans(),
                ])
                ->all();
        }

        return Inertia::render('leadhub::SyncLog', [
            'enabled' => (bool) config('leadhub.features.crm_destinations', false),
            'logs' => $logs,
        ]);
    }
}
