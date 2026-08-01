<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\SyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Statamic\CP\Column;

class SyncLogController extends Controller
{
    /** Columns the sync log renders, in the order the screen shows them. */
    protected function columns(): array
    {
        return collect([
            Column::make('contact_label')->label(__('leadhub::crm.contact'))->sortable(false),
            Column::make('destination')->label(__('leadhub::crm.destination'))->sortable(false),
            Column::make('event')->label(__('leadhub::crm.event'))->sortable(false),
            Column::make('status')->label(__('leadhub::crm.status'))->sortable(false),
            Column::make('message')->label(__('leadhub::crm.detail'))->sortable(false),
            Column::make('created_at')->label(__('leadhub::crm.time'))->sortable(true),
        ])->map(fn (Column $c) => $c->toArray())->all();
    }

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');

        return Inertia::render('leadhub::SyncLog', [
            'enabled' => (bool) config('leadhub.features.crm_destinations', false),
            'columns' => $this->columns(),
            'dataUrl' => cp_route('leadhub.sync-log.data'),
            // Whether anything has ever been logged. The screen needs it to
            // pick between the empty state and the listing before the listing
            // has made its first request.
            'hasLogs' => Schema::hasTable('leadhub_sync_logs') && SyncLog::query()->exists(),
        ]);
    }

    /**
     * The listing's data endpoint.
     *
     * The screen used to be a hand-rolled <table> fed by a hardcoded
     * `->limit(100)`: an install syncing a few hundred contacts silently lost
     * the rest of its log, with no pagination and no hint that anything was
     * missing. <Listing> in server mode paginates instead, so the whole log is
     * reachable.
     */
    public function data(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');

        if (! Schema::hasTable('leadhub_sync_logs')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'columns' => $this->columns(),
                    'current_page' => 1, 'last_page' => 1, 'per_page' => 25,
                    'from' => null, 'to' => null, 'total' => 0,
                ],
            ]);
        }

        $query = SyncLog::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                foreach (['contact_label', 'destination', 'driver', 'event', 'status', 'message'] as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        // Only `created_at` is sortable; anything else the client sends is
        // ignored rather than passed into the query.
        $direction = strtolower((string) $request->query('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy('created_at', $direction)->orderBy('id', $direction);

        $perPage = min(max((int) $request->query('perPage', 25), 1), 100);

        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => collect($paginator->items())
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
                    'status_label' => __('leadhub::crm.statuses.'.$log->status),
                    'response_code' => $log->response_code,
                    'message' => $log->message,
                    'created_at' => $log->created_at?->diffForHumans(),
                ])
                ->all(),
            'meta' => [
                'columns' => $this->columns(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
