<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event as TimelineEvent;
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Services\FollowupService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(protected FollowupService $followups)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('view leadhub'), 403);

        $newLeadsThisWeek = Contact::query()
            ->active()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $qualified = Contact::query()->active()->withStatus('qualified')->count();
        $won = Contact::query()->active()->withStatus('won')->count();

        $followupsDueToday = $this->followups->dueToday()->count();
        $followupsOverdue = $this->followups->overdue()->count();

        $latestLeads = Contact::query()
            ->active()
            ->latest()
            ->limit(5)
            ->get();

        $latestActivity = TimelineEvent::query()
            ->with('contact')
            ->latest()
            ->limit(8)
            ->get();

        $followupsToday = $this->followups->dueToday()
            ->with('contact')
            ->orderBy('due_at')
            ->limit(5)
            ->get();

        $followupsOverdueList = $this->followups->overdue()
            ->with('contact')
            ->orderBy('due_at')
            ->limit(5)
            ->get();

        $statuses = (array) config('leadhub.statuses', []);
        $leadsByStatus = Contact::query()
            ->active()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $hasFormConnected = FormMapping::query()->where('enabled', true)->exists();

        return view('leadhub::dashboard', [
            'kpis' => [
                'new_leads_week' => $newLeadsThisWeek,
                'qualified' => $qualified,
                'won' => $won,
                'due_followups' => $followupsDueToday,
                'overdue_followups' => $followupsOverdue,
            ],
            'latestLeads' => $latestLeads,
            'latestActivity' => $latestActivity,
            'followupsToday' => $followupsToday,
            'followupsOverdue' => $followupsOverdueList,
            'leadsByStatus' => $leadsByStatus,
            'statuses' => $statuses,
            'hasFormConnected' => $hasFormConnected,
        ]);
    }
}
