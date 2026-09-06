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
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub');

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
        $defaultStatus = (string) config('leadhub.default_status', '');

        $leadsByStatus = collect($statuses)
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'count' => $countsByStatus[$key] ?? 0,
                'filter_url' => $contactsUrl.'?status='.$key,
                // The handle and the "this one is the default" marker used to
                // be a read-only panel on the settings screen. That screen is
                // the suite's shared one now, and a read-only printout of one
                // addon's config does not belong on it — but it must not
                // disappear either: the handle is what is stored on every
                // contact, so an operator editing `leadhub.statuses` needs to
                // see which strings are in use.
                'is_default' => $key === $defaultStatus,
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
                'id' => (string) ($f->uuid),
                'contact_name' => $f->contact?->displayName() ?? '—',
                'contact_url' => $f->contact ? cp_route('leadhub.contacts.show', $f->contact->id) : null,
                'due_at' => $f->due_at?->format('H:i'),
                'note' => $f->note,
            ])->all(),
            'followupsOverdue' => $this->followups->overdue(5)->map(fn ($f) => [
                'id' => (string) ($f->uuid),
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

            // Deployment-owned values, shown so they can be checked. Empty for
            // anybody without the settings permission: the list carries the
            // notification and digest recipient addresses, and it sat behind
            // that permission on the settings screen it comes from.
            'environment' => $this->userCan($request, 'manage leadhub settings')
                ? $this->environmentPayload()
                : [],
            'environmentTexts' => [
                'heading' => __('leadhub::settings.environment.heading'),
                'description' => __('leadhub::settings.environment.description'),
                'publishCommand' => __('leadhub::settings.publish_command'),
            ],
        ]);
    }

    /**
     * Settings the deployment owns, shown but never editable.
     *
     * Moved here from the settings screen when that moved to brand-context.
     * These are not settings and never were, so they have no place on a shared
     * screen generated from `ProvidesSettings` — but they are the only view an
     * operator has of what the environment actually resolved to, and losing
     * them in the move would have been a silent removal.
     *
     * All of them resolve from `env()`. A database row that outranks an env var
     * is a setting that changes back on the next deploy without anyone touching
     * a screen. `storage.driver` is worse than that: it decides where contacts,
     * events and notes live, and switching it under a running install has to
     * move them first — that is what `leadhub:storage:migrate` is for, and a
     * second, silent way to flip the same switch would strand the data in the
     * old store.
     *
     * No credential is reported by value. The CRM destinations are not here at
     * all: their `token`, `api_key` and `secret` are why this list is an
     * allow-list and not `config('leadhub')`. Pinned by
     * tests/Feature/SettingsSecretsTest.php.
     *
     * @return array<int, array{label: string, value: string, env: string}>
     */
    protected function environmentPayload(): array
    {
        $notifications = (array) config('leadhub.notifications', []);
        $digest = (array) ($notifications['digest'] ?? []);

        $none = __('leadhub::settings.environment.none');
        $yesNo = fn (mixed $value) => $value
            ? __('leadhub::settings.environment.on')
            : __('leadhub::settings.environment.off');

        return [
            [
                'label' => __('leadhub::settings.environment.storage_driver'),
                'value' => (string) config('leadhub.storage.driver', 'eloquent'),
                'env' => 'LEADHUB_DRIVER',
            ],
            [
                'label' => __('leadhub::settings.environment.flat_path'),
                'value' => (string) config('leadhub.storage.flat.path', ''),
                'env' => 'LEADHUB_FLAT_PATH',
            ],
            [
                'label' => __('leadhub::settings.environment.notifications'),
                'value' => $yesNo($notifications['enabled'] ?? false),
                'env' => 'LEADHUB_NOTIFICATIONS',
            ],
            [
                'label' => __('leadhub::settings.environment.notify_recipients'),
                'value' => implode(', ', (array) ($notifications['recipients'] ?? [])) ?: $none,
                'env' => 'LEADHUB_NOTIFY_EMAILS',
            ],
            [
                'label' => __('leadhub::settings.environment.digest_time'),
                'value' => (string) ($digest['time'] ?? '08:00'),
                'env' => 'LEADHUB_DIGEST_TIME',
            ],
            [
                'label' => __('leadhub::settings.environment.digest_recipients'),
                'value' => implode(', ', (array) ($digest['fallback_recipients'] ?? [])) ?: $none,
                'env' => 'LEADHUB_DIGEST_EMAILS',
            ],
        ];
    }
}
