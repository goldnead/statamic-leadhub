<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Services\FollowupService;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
            'new_leads_week_from' => now()->subDays(7)->toDateString(),
            'qualified' => $this->contacts->countWithStatus('qualified'),
            'won' => $this->contacts->countWithStatus('won'),
            'due_followups' => $this->followups->countDueToday(),
            'overdue_followups' => $this->followups->countOverdue(),
        ];

        $countsByStatus = $this->contacts->countByStatus();
        $contactsUrl = cp_route('leadhub.contacts.index');

        $leadsByStatus = collect($statuses)
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'count' => $countsByStatus[$key] ?? 0,
                'filter_url' => $contactsUrl.'?status='.$key,
            ])
            ->values()
            ->all();

        return Inertia::render('leadhub::Dashboard', [
            'kpis' => $kpis,
            'latestActivity' => $this->events->latest(8)->map(fn ($event) => [
                'id' => (string) ($event->id ?? $event->uuid),
                'contact_name' => $event->contact?->displayName() ?? '—',
                'contact_url' => $event->contact ? cp_route('leadhub.contacts.show', $event->contact->id) : null,
                'summary' => (string) $event->summary,
                'type' => (string) $event->type,
                'created_at' => $event->created_at?->diffForHumans(),
            ])->all(),
            'followupsToday' => $this->followups->dueToday(5)->map(fn ($f) => [
                'id' => (string) ($f->id ?? $f->uuid),
                'contact_name' => $f->contact?->displayName() ?? '—',
                'contact_url' => $f->contact ? cp_route('leadhub.contacts.show', $f->contact->id) : null,
                'due_at' => $f->due_at?->format('H:i'),
                'note' => $f->note,
            ])->all(),
            'followupsOverdue' => $this->followups->overdue(5)->map(fn ($f) => [
                'id' => (string) ($f->id ?? $f->uuid),
                'contact_name' => $f->contact?->displayName() ?? '—',
                'contact_url' => $f->contact ? cp_route('leadhub.contacts.show', $f->contact->id) : null,
                'due_at' => $f->due_at?->diffForHumans(),
                'note' => $f->note,
            ])->all(),
            'leadsByStatus' => $leadsByStatus,
            'hasFormConnected' => $this->mappings->anyEnabled(),
            'configureFormsUrl' => cp_route('leadhub.forms.index'),
            'contactsUrl' => $contactsUrl,
            'followupsUrl' => cp_route('leadhub.followups.index'),
        ]);
    }
}
