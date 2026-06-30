<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Events\LeadHubContactArchived;
use Goldnead\Leadhub\Events\LeadHubContactDeleted;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
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
                ? (string) ($request->user()?->id() ?? '')
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
        ]);
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
            'canArchive' => $request->user()?->hasPermission('archive leadhub contacts') ?? false,
            'canDelete' => $request->user()?->hasPermission('delete leadhub contacts') ?? false,
        ]);
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
