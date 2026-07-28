<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Events\LeadHubContactArchived;
use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubContactDeleted;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Http\Requests\StoreContactRequest;
use Goldnead\Leadhub\Http\Requests\UpdateContactRequest;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\LeadHubNotifier;
use Goldnead\Leadhub\Services\TagService;
use Goldnead\Leadhub\Services\TimelineService;
use Goldnead\Leadhub\Support\UserDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;

class ContactController extends Controller
{
    public function __construct(
        protected ContactRepository $contacts,
        protected EventRepository $events,
        protected NoteRepository $notes,
        protected TagRepository $tagsRepo,
        protected FollowupRepository $followups,
        protected FormMappingRepository $mappings,
        protected TimelineService $timeline,
        protected TagService $tags,
        protected UserDirectory $users,
        protected LeadHubNotifier $notifier,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub contacts');

        $filters = [
            'archived' => $request->boolean('archived'),
            'status' => $request->string('status')->toString() ?: null,
            'source_form' => $request->string('source')->toString() ?: null,
            'tag_id' => $request->input('tag') ?: null,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'has_followup' => $request->string('followup')->toString() ?: null,
            'search' => $request->string('q')->toString() ?: null,
            // ?mine=1 scopes to the current user; ?assigned_to=ID to a specific
            // owner; ?assigned_to=none to unassigned leads.
            'assigned_to' => $request->boolean('mine')
                ? $this->userId($request)
                : ($request->string('assigned_to')->toString() ?: null),
            'sort' => $request->input('sort', 'created_at'),
            'direction' => $request->input('direction', 'desc'),
        ];

        $statuses = (array) config('leadhub.statuses', []);
        $assignableUsers = $this->users->assignable();
        $ownerLabels = collect($assignableUsers)->pluck('label', 'value')->all();
        $page = $this->contacts->paginate($filters, 25, (int) $request->input('page', 1));

        $rows = collect($page->items())->map(function (Contact $contact) use ($statuses, $ownerLabels) {
            $followups = $contact->relationLoaded('followups') ? $contact->getRelation('followups') : collect();
            $active = $followups instanceof \Illuminate\Support\Collection
                ? $followups->whereNull('completed_at')->sortBy('due_at')->first()
                : null;

            return [
                'id' => (string) $contact->uuid,
                'display_name' => $contact->displayName(),
                'email' => $contact->email,
                'phone' => $contact->phone,
                'company' => $contact->company,
                'status' => $contact->status,
                'status_label' => $statuses[$contact->status] ?? $contact->status,
                'tags' => collect($contact->relationLoaded('tags') ? $contact->getRelation('tags')->all() : [])->map(fn ($t) => [
                    'id' => (string) $t->id,
                    'name' => $t->name,
                ])->all(),
                'source_form' => $contact->source_form,
                'owner_name' => $ownerLabels[(string) ($contact->assigned_to ?? '')] ?? null,
                'last_activity_at' => $contact->last_activity_at?->diffForHumans(),
                'archived_at' => $contact->archived_at?->toIso8601String(),
                'active_followup' => $active ? [
                    'due_at' => $active->due_at?->format('Y-m-d'),
                    'is_overdue' => method_exists($active, 'isOverdue') && $active->isOverdue(),
                ] : null,
                'edit_url' => cp_route('leadhub.contacts.show', $contact->uuid),
                'archive_url' => cp_route('leadhub.contacts.archive', $contact->uuid),
                'restore_url' => cp_route('leadhub.contacts.restore', $contact->uuid),
                'delete_url' => cp_route('leadhub.contacts.destroy', $contact->uuid),
                'can_edit' => true,
                'can_archive' => true,
                'can_delete' => true,
            ];
        });

        $columns = collect([
            Column::make('display_name')->label(__('leadhub::contacts.name'))->sortable(true),
            Column::make('email')->label(__('leadhub::contacts.email')),
            Column::make('status')->label(__('leadhub::contacts.status')),
            Column::make('tags')->label(__('leadhub::contacts.tags')),
            Column::make('source_form')->label(__('leadhub::contacts.source')),
            Column::make('owner_name')->label(__('leadhub::contacts.owner')),
            Column::make('last_activity_at')->label(__('leadhub::contacts.last_activity')),
            Column::make('active_followup')->label(__('leadhub::contacts.followup')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Contacts/Index', [
            'contacts' => $rows->all(),
            'columns' => $columns,
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'statuses' => $statuses,
            'assignableUsers' => $assignableUsers,
            'tagOptions' => $this->tagsRepo->all()->map(fn ($t) => [
                'value' => (string) $t->id,
                'label' => $t->name,
            ])->all(),
            'sourceOptions' => collect($this->mappings->enabledHandles())->map(fn ($h) => [
                'value' => $h,
                'label' => $h,
            ])->all(),
            'exportUrl' => cp_route('leadhub.export'),
            'showArchived' => $filters['archived'],
            'hasFormConnected' => $this->mappings->anyEnabled(),
            'configureFormsUrl' => cp_route('leadhub.forms.index'),
            // Manual "create contact" entry point — null (button hidden) when
            // the feature flag is off or the user lacks the permission.
            'createUrl' => config('leadhub.features.manual_contacts', true)
                && $this->userCan($request, 'create leadhub contacts')
                ? cp_route('leadhub.contacts.create')
                : null,
        ]);
    }

    /**
     * Contact options for the pickers on the task and opportunity forms.
     *
     * A JSON endpoint rather than a prop, because a `<Select>` over every
     * contact stops working somewhere in the low thousands. Brand-scoped
     * through the repository like every other read — a picker that offered
     * another brand's contacts would be a leak with a friendly interface.
     */
    public function options(Request $request)
    {
        $this->authorizeOrFail($request, 'view leadhub contacts');

        return response()->json([
            'options' => app(\Goldnead\Leadhub\Support\ContactPicker::class)->options(
                $request->string('q')->toString() ?: null,
            ),
        ]);
    }

    /**
     * Render the manual "create contact" form. Gated behind the
     * `features.manual_contacts` flag and the `create leadhub contacts`
     * permission.
     */
    public function create(Request $request)
    {
        abort_unless(config('leadhub.features.manual_contacts', true), 404);
        $this->authorizeOrFail($request, 'create leadhub contacts');

        $statuses = (array) config('leadhub.statuses', []);

        return Inertia::render('leadhub::Contacts/Create', [
            'statuses' => $statuses,
            'defaultStatus' => (string) config('leadhub.default_status', array_key_first($statuses) ?: 'new'),
            'assignableUsers' => $this->users->assignable(),
            'tagOptions' => $this->tagsRepo->all()->map(fn ($t) => [
                'value' => (string) $t->id,
                'label' => $t->name,
            ])->all(),
            'storeUrl' => cp_route('leadhub.contacts.store'),
            'cancelUrl' => cp_route('leadhub.contacts.index'),
        ]);
    }

    /**
     * Persist a manually created contact. Validated against the contact
     * blueprint via StoreContactRequest, then created through the repository so
     * the timeline entry + LeadHubContactCreated event fire exactly like the
     * form-ingestion path.
     */
    public function store(StoreContactRequest $request)
    {
        abort_unless(config('leadhub.features.manual_contacts', true), 404);

        $validated = $request->validated();

        $attributes = collect($validated)->except('tag_ids')->all();
        $attributes['status'] = $attributes['status']
            ?? (string) config('leadhub.default_status', 'new');
        $attributes['consent'] = (bool) ($validated['consent'] ?? false);
        $attributes['consent_at'] = ! empty($attributes['consent']) ? now() : null;
        $attributes['last_activity_at'] = now();

        $contact = $this->contacts->create($attributes);

        $this->timeline->recordContactCreated($contact);
        event(new LeadHubContactCreated($contact));

        if (! empty($validated['tag_ids'])) {
            foreach ($validated['tag_ids'] as $id) {
                if ($tag = $this->tagsRepo->find((string) $id)) {
                    $this->tags->attach($contact, $tag);
                }
            }
        }

        return redirect(cp_route('leadhub.contacts.show', $contact->uuid))
            ->with('success', __('leadhub::contacts.flashes.created'));
    }

    public function show(Request $request, int|string $contactId)
    {
        $this->authorizeOrFail($request, 'view leadhub contacts');

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $eventsPaginator = $this->events->forContact($contact, 20, (int) $request->input('page', 1));
        $events = collect($eventsPaginator->items())->map(fn ($e) => [
            'id' => (string) ($e->uuid),
            'type' => $e->type,
            'summary' => $e->summary,
            'payload' => $e->payload ?? [],
            'actor_label' => $e->actor_type === 'user' ? __('leadhub::timeline.actor_user') : __('leadhub::timeline.actor_system'),
            'created_at' => $e->created_at?->diffForHumans(),
        ])->all();

        // Fetch via the repository — find() doesn't eager-load relations, so
        // relying on relationLoaded() here would always miss active follow-ups.
        $active = $this->followups->activeForOne($contact);

        // Attribution (UTM / referrer / landing page) — only the populated
        // fields, and only when the feature is enabled.
        $attribution = [];
        if (config('leadhub.features.attribution', false)) {
            $attributionLabels = [
                'utm_source' => __('leadhub::attribution.utm_source'),
                'utm_medium' => __('leadhub::attribution.utm_medium'),
                'utm_campaign' => __('leadhub::attribution.utm_campaign'),
                'utm_term' => __('leadhub::attribution.utm_term'),
                'utm_content' => __('leadhub::attribution.utm_content'),
                'referrer' => __('leadhub::attribution.referrer'),
                'landing_page' => __('leadhub::attribution.landing_page'),
            ];
            foreach ($attributionLabels as $column => $label) {
                $value = $contact->getAttribute($column);
                if ($value !== null && $value !== '') {
                    $attribution[] = ['label' => $label, 'value' => (string) $value];
                }
            }
        }

        $statuses = (array) config('leadhub.statuses', []);
        $allTags = $this->tagsRepo->all()->map(fn ($t) => [
            'id' => (string) $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
        ])->all();

        $crm = $this->crmPanels($contact);

        return Inertia::render('leadhub::Contacts/Show', [
            'contact' => [
                'id' => (string) $contact->uuid,
                'display_name' => $contact->displayName(),
                'email' => $contact->email,
                'phone' => $contact->phone,
                'company' => $contact->company,
                'status' => $contact->status,
                'status_label' => $statuses[$contact->status] ?? $contact->status,
                'assigned_to' => (string) ($contact->assigned_to ?? ''),
                'owner_name' => $this->users->label($contact->assigned_to),
                'tags' => $this->tagsRepo->forContact($contact)->map(fn ($t) => [
                    'id' => (string) $t->id,
                    'name' => $t->name,
                ])->values()->all(),
                'source_form' => $contact->source_form,
                'attribution' => $attribution,
                'consent' => (bool) $contact->consent,
                'created_at' => $contact->created_at?->format('Y-m-d'),
                'last_activity_at' => $contact->last_activity_at?->diffForHumans(),
                'archived_at' => $contact->archived_at?->format('Y-m-d'),
                'update_url' => cp_route('leadhub.contacts.update', $contact->uuid),
                'archive_url' => cp_route('leadhub.contacts.archive', $contact->uuid),
                'restore_url' => cp_route('leadhub.contacts.restore', $contact->uuid),
                'delete_url' => cp_route('leadhub.contacts.destroy', $contact->uuid),
                'note_url' => cp_route('leadhub.contacts.notes.store', $contact->uuid),
                'followup_url' => cp_route('leadhub.contacts.followup.store', $contact->uuid),
                'redirect_url' => cp_route('leadhub.contacts.index'),
            ],
            'assignableUsers' => $this->users->assignable(),
            'events' => [
                'data' => $events,
                'meta' => [
                    'current_page' => $eventsPaginator->currentPage(),
                    'last_page' => $eventsPaginator->lastPage(),
                    'total' => $eventsPaginator->total(),
                ],
            ],
            'activeFollowup' => $active ? [
                'id' => (string) ($active->uuid),
                'due_at' => $active->due_at?->format('Y-m-d H:i'),
                'due_at_iso' => $active->due_at?->toIso8601String(),
                'note' => $active->note,
                'is_overdue' => method_exists($active, 'isOverdue') && $active->isOverdue(),
                'complete_url' => cp_route('leadhub.followups.complete', $active->uuid),
                'delete_url' => cp_route('leadhub.followups.destroy', $active->uuid),
            ] : null,
            'statuses' => $statuses,
            'allTags' => $allTags,
            'canArchive' => $this->userCan($request, 'archive leadhub contacts'),
            'canDelete' => $this->userCan($request, 'delete leadhub contacts'),
            // Linked CRM records. `contact.company` above is the free-text
            // company string that came off the form — a different thing from
            // `linkedCompanies`, which are real Company records. The view is
            // required to keep them visually apart.
            'crmFeatures' => $crm['features'],
            'linkedCompanies' => $crm['companies'],
            'tasks' => $crm['tasks'],
            'opportunities' => $crm['opportunities'],
            // Entry points into the CRM create forms with this contact already
            // selected — this page is where a user looks first. Null (button
            // hidden) without the matching permission.
            'crmCreateUrls' => [
                'task' => $crm['features']['tasks'] && $this->userCan($request, 'manage leadhub tasks')
                    ? cp_route('leadhub.tasks.create').'?contact='.$contact->id
                    : null,
                'opportunity' => $crm['features']['pipelines'] && $this->userCan($request, 'manage leadhub opportunities')
                    ? cp_route('leadhub.pipelines.opportunities.create').'?contact='.$contact->id
                    : null,
                'company' => $crm['features']['companies'] && $this->userCan($request, 'manage leadhub companies')
                    ? cp_route('leadhub.companies.create')
                    : null,
            ],
        ]);
    }

    /**
     * The CRM entities linked to a contact: companies, tasks, opportunities.
     *
     * Eloquent-only (the CRM-core modules are), each behind its own feature
     * flag. Returns empty lists rather than nulls so the view never has to
     * guard against a missing prop.
     */
    protected function crmPanels(mixed $contact): array
    {
        $features = [
            'companies' => (bool) config('leadhub.features.companies', false),
            'tasks' => (bool) config('leadhub.features.tasks', false),
            'pipelines' => (bool) config('leadhub.features.pipelines', false),
        ];

        $eloquent = config('leadhub.storage.driver', 'eloquent') === 'eloquent'
            && $contact instanceof Contact
            && $contact->exists;

        $out = ['features' => $features, 'companies' => [], 'tasks' => [], 'opportunities' => []];

        if (! $eloquent) {
            return $out;
        }

        if ($features['companies']) {
            $out['companies'] = $contact->companies()->orderBy('name')->get()
                ->map(fn ($company) => [
                    'id' => (string) $company->id,
                    'name' => $company->displayName(),
                    'domain' => $company->domain,
                    'industry' => $company->industry,
                    'relationship_label' => $company->pivot->relationship_label ?? null,
                    'is_primary' => (bool) ($company->pivot->is_primary ?? false),
                    'url' => cp_route('leadhub.companies.show', $company->id),
                ])->values()->all();
        }

        if ($features['tasks']) {
            $out['tasks'] = \Goldnead\Leadhub\Models\Task::query()
                ->where('contact_id', $contact->id)
                ->orderByRaw('completed_at is not null')
                ->orderByRaw('due_at is null, due_at asc')
                ->get()
                ->map(fn ($task) => [
                    'id' => (string) $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_at' => $task->due_at?->format('Y-m-d H:i'),
                    'is_overdue' => $task->isOverdue(),
                    'is_completed' => $task->isCompleted(),
                    'assignee_id' => $task->assignee_id,
                    'assignee_name' => $task->assignee_id ? $this->users->label($task->assignee_id) : null,
                    'complete_url' => cp_route('leadhub.tasks.complete', $task->id),
                    'index_url' => cp_route('leadhub.tasks.index'),
                ])->values()->all();
        }

        if ($features['pipelines']) {
            $out['opportunities'] = \Goldnead\Leadhub\Models\Opportunity::query()
                ->where('contact_id', $contact->id)
                ->with(['pipeline', 'stage'])
                ->orderByDesc('last_activity_at')
                ->get()
                ->map(fn ($opp) => [
                    'id' => (string) $opp->id,
                    'title' => $opp->title,
                    'status' => $opp->status,
                    'outcome' => $opp->outcome,
                    'value_estimate' => $opp->value_estimate !== null ? (float) $opp->value_estimate : null,
                    'confidence' => $opp->confidence,
                    'stage_name' => $opp->stage?->name,
                    'pipeline_name' => $opp->pipeline?->name,
                    'closed_at' => $opp->closed_at?->format('Y-m-d'),
                    'board_url' => $opp->pipeline
                        ? cp_route('leadhub.pipelines.board.show', $opp->pipeline->id)
                        : null,
                ])->values()->all();
        }

        return $out;
    }

    public function update(UpdateContactRequest $request, int|string $contactId)
    {
        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        // Capture old values BEFORE filling — the flat-file driver re-syncs the
        // model on save(), so wasChanged() can't be relied on across drivers.
        $oldStatus = $contact->status;
        $oldAssigned = $contact->assigned_to;

        // tag_ids is not a column on the contact — it's synced to the tag
        // relation below. Filling it onto the model would try to persist a
        // non-existent column.
        $contact->fill(collect($request->validated())->except('tag_ids')->all());

        $statusChanged = $oldStatus !== $contact->status;
        $assignmentChanged = $oldAssigned !== $contact->assigned_to;

        $this->contacts->save($contact);

        if ($statusChanged) {
            $this->timeline->recordStatusChanged($contact, $oldStatus, $contact->status);
            event(new LeadHubStatusChanged(
                $contact,
                metadata: ['from' => $oldStatus, 'to' => $contact->status]
            ));
        }

        if ($assignmentChanged) {
            $this->timeline->recordAssigned($contact, $this->users->label($contact->assigned_to));
            if (! empty($contact->assigned_to)) {
                $this->notifier->assigned($contact);
            }
        }

        if ($request->has('tag_ids')) {
            $newTagIds = collect($request->input('tag_ids', []))->map(fn ($id) => (string) $id)->all();
            $existingTagIds = $this->tagsRepo->forContact($contact)->pluck('id')->map(fn ($id) => (string) $id)->all();

            foreach (array_diff($newTagIds, $existingTagIds) as $id) {
                if ($tag = $this->tagsRepo->find($id)) {
                    $this->tags->attach($contact, $tag);
                }
            }

            foreach (array_diff($existingTagIds, $newTagIds) as $id) {
                if ($tag = $this->tagsRepo->find($id)) {
                    $this->tags->detach($contact, $tag);
                }
            }
        }

        return back()->with('success', __('leadhub::contacts.flashes.updated'));
    }

    public function destroy(Request $request, int|string $contactId)
    {
        $this->authorizeOrFail($request, 'delete leadhub contacts');

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        event(new LeadHubContactDeleted($contact));
        $this->contacts->delete($contact);

        return redirect(cp_route('leadhub.contacts.index'))
            ->with('success', __('leadhub::contacts.flashes.deleted'));
    }

    public function archive(Request $request, int|string $contactId)
    {
        $this->authorizeOrFail($request, 'archive leadhub contacts');

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $this->contacts->archive($contact);
        $this->timeline->recordContactArchived($contact);
        event(new LeadHubContactArchived($contact));

        return back()->with('success', __('leadhub::contacts.flashes.archived'));
    }

    public function restore(Request $request, int|string $contactId)
    {
        $this->authorizeOrFail($request, 'archive leadhub contacts');

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $this->contacts->restore($contact);

        return back()->with('success', __('leadhub::contacts.flashes.restored'));
    }
}
