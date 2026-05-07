<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Services\FollowupService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected ContactRepository $contacts,
        protected EventRepository $events,
        protected FormMappingRepository $mappings,
        protected FollowupService $followups,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('view leadhub'), 403);

        $statuses = (array) config('leadhub.statuses', []);

        $kpis = [
            'new_leads_week' => $this->contacts->countNewSince(7),
            'qualified' => $this->contacts->countWithStatus('qualified'),
            'won' => $this->contacts->countWithStatus('won'),
            'due_followups' => $this->followups->countDueToday(),
            'overdue_followups' => $this->followups->countOverdue(),
        ];

        return view('leadhub::dashboard', [
            'kpis' => $kpis,
            'latestLeads' => $this->contacts->recent(5),
            'latestActivity' => $this->events->latest(8),
            'followupsToday' => $this->followups->dueToday(5),
            'followupsOverdue' => $this->followups->overdue(5),
            'leadsByStatus' => $this->contacts->countByStatus(),
            'statuses' => $statuses,
            'hasFormConnected' => $this->mappings->anyEnabled(),
        ]);
    }
}
