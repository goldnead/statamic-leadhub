<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Events\LeadHubContactArchived;
use Goldnead\Leadhub\Events\LeadHubContactDeleted;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Http\Requests\UpdateContactRequest;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\TagService;
use Goldnead\Leadhub\Services\TimelineService;
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
        protected FormMappingRepository $mappings,
        protected TimelineService $timeline,
        protected TagService $tags,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->can('view leadhub contacts'), 403);

        $filters = [
            'archived' => $request->boolean('archived'),
            'status' => $request->string('status')->toString() ?: null,
            'source_form' => $request->string('source')->toString() ?: null,
            'tag_id' => $request->input('tag') ?: null,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'has_followup' => $request->string('followup')->toString() ?: null,
            'search' => $request->string('q')->toString() ?: null,
            'sort' => $request->input('sort', 'created_at'),
            'direction' => $request->input('direction', 'desc'),
        ];

        $statuses = (array) config('leadhub.statuses', []);
        $page = $this->contacts->paginate($filters, 25, (int) $request->input('page', 1));

        $rows = collect($page->items())->map(function (Contact $contact) use ($statuses) {
            $followups = $contact->getRelation('followups') ?? collect();
            $active = $followups instanceof \Illuminate\Support\Collection
                ? $followups->whereNull('completed_at')->sortBy('due_at')->first()
                : null;

            return [
                'id' => (string) $contact->id,
                'display_name' => $contact->displayName(),
                'email' => $contact->email,
                'phone' => $contact->phone,
                'company' => $contact->company,
                'status' => $contact->status,
                'status_label' => $statuses[$contact->status] ?? $contact->status,
                'tags' => collect($contact->getRelation('tags') ?? [])->map(fn ($t) => [
                    'id' => (string) $t->id,
                    'name' => $t->name,
                ])->all(),
                'source_form' => $contact->source_form,
                'last_activity_at' => $contact->last_activity_at?->diffForHumans(),
                'archived_at' => $contact->archived_at?->toIso8601String(),
                'active_followup' => $active ? [
                    'due_at' => $active->due_at?->format('Y-m-d'),
                    'is_overdue' => method_exists($active, 'isOverdue') && $active->isOverdue(),
                ] : null,
                'edit_url' => cp_route('leadhub.contacts.show', $contact->id),
                'archive_url' => cp_route('leadhub.contacts.archive', $contact->id),
                'restore_url' => cp_route('leadhub.contacts.restore', $contact->id),
                'delete_url' => cp_route('leadhub.contacts.destroy', $contact->id),
                'can_edit' => true,
                'can_archive' => true,
                'can_delete' => true,
            ];
        });

        $columns = collect([
            Column::make('display_name')->label(__('leadhub::contacts.name'))->sortable(),
            Column::make('email')->label(__('leadhub::contacts.email')),
            Column::make('status')->label(__('leadhub::contacts.status')),
            Column::make('tags')->label(__('leadhub::contacts.tags')),
            Column::make('source_form')->label(__('leadhub::contacts.source')),
            Column::make('last_activity_at')->label(__('leadhub::contacts.last_activity')),
            Column::make('active_followup')->label(__('leadhub::contacts.followup')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('leadhub::Contacts/Index', [
            'contacts' => $rows->all(),
            'columns' => $columns,
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'statuses' => $statuses,
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
        abort_unless($request->user()?->can('view leadhub contacts'), 403);

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $eventsPaginator = $this->events->forContact($contact, 20, (int) $request->input('page', 1));
        $events = collect($eventsPaginator->items())->map(fn ($e) => [
            'id' => (string) ($e->id ?? $e->uuid),
            'type' => $e->type,
            'summary' => $e->summary,
            'payload' => $e->payload ?? [],
            'actor_label' => $e->actor_type === 'user' ? __('leadhub::timeline.actor_user') : __('leadhub::timeline.actor_system'),
            'created_at' => $e->created_at?->diffForHumans(),
        ])->all();

        $activeFollowups = $contact->getRelation('followups') ?? collect();
        $active = $activeFollowups instanceof \Illuminate\Support\Collection
            ? $activeFollowups->whereNull('completed_at')->sortBy('due_at')->first()
            : null;

        $statuses = (array) config('leadhub.statuses', []);
        $allTags = $this->tagsRepo->all()->map(fn ($t) => [
            'id' => (string) $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
        ])->all();

        return Inertia::render('leadhub::Contacts/Show', [
            'contact' => [
                'id' => (string) $contact->id,
                'display_name' => $contact->displayName(),
                'email' => $contact->email,
                'phone' => $contact->phone,
                'company' => $contact->company,
                'status' => $contact->status,
                'status_label' => $statuses[$contact->status] ?? $contact->status,
                'tags' => collect($contact->getRelation('tags') ?? [])->map(fn ($t) => [
                    'id' => (string) $t->id,
                    'name' => $t->name,
                ])->all(),
                'source_form' => $contact->source_form,
                'consent' => (bool) $contact->consent,
                'created_at' => $contact->created_at?->format('Y-m-d'),
                'last_activity_at' => $contact->last_activity_at?->diffForHumans(),
                'archived_at' => $contact->archived_at?->format('Y-m-d'),
                'update_url' => cp_route('leadhub.contacts.update', $contact->id),
                'archive_url' => cp_route('leadhub.contacts.archive', $contact->id),
                'restore_url' => cp_route('leadhub.contacts.restore', $contact->id),
                'delete_url' => cp_route('leadhub.contacts.destroy', $contact->id),
                'note_url' => cp_route('leadhub.contacts.notes.store', $contact->id),
                'followup_url' => cp_route('leadhub.contacts.followup.store', $contact->id),
                'redirect_url' => cp_route('leadhub.contacts.index'),
            ],
            'events' => [
                'data' => $events,
                'meta' => [
                    'current_page' => $eventsPaginator->currentPage(),
                    'last_page' => $eventsPaginator->lastPage(),
                    'total' => $eventsPaginator->total(),
                ],
            ],
            'activeFollowup' => $active ? [
                'id' => (string) ($active->id ?? $active->uuid),
                'due_at' => $active->due_at?->format('Y-m-d H:i'),
                'due_at_iso' => $active->due_at?->toIso8601String(),
                'note' => $active->note,
                'is_overdue' => method_exists($active, 'isOverdue') && $active->isOverdue(),
                'complete_url' => cp_route('leadhub.followups.complete', $active->id ?? $active->uuid),
                'delete_url' => cp_route('leadhub.followups.destroy', $active->id ?? $active->uuid),
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

        $oldStatus = $contact->status;

        $contact->fill($request->validated());
        $this->contacts->save($contact);

        if ($contact->wasChanged('status')) {
            $this->timeline->recordStatusChanged($contact, $oldStatus, $contact->status);
            event(new LeadHubStatusChanged(
                $contact,
                metadata: ['from' => $oldStatus, 'to' => $contact->status]
            ));
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
        abort_unless($request->user()?->can('delete leadhub contacts'), 403);

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        event(new LeadHubContactDeleted($contact));
        $this->contacts->delete($contact);

        return redirect(cp_route('leadhub.contacts.index'))
            ->with('success', __('leadhub::contacts.flashes.deleted'));
    }

    public function archive(Request $request, int|string $contactId)
    {
        abort_unless($request->user()?->can('archive leadhub contacts'), 403);

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $this->contacts->archive($contact);
        $this->timeline->recordContactArchived($contact);
        event(new LeadHubContactArchived($contact));

        return back()->with('success', __('leadhub::contacts.flashes.archived'));
    }

    public function restore(Request $request, int|string $contactId)
    {
        abort_unless($request->user()?->can('archive leadhub contacts'), 403);

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $this->contacts->restore($contact);

        return back()->with('success', __('leadhub::contacts.flashes.restored'));
    }
}
