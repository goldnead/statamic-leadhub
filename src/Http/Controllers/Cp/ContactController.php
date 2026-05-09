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
use Goldnead\Leadhub\Services\TagService;
use Goldnead\Leadhub\Services\TimelineService;
use Illuminate\Http\Request;

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

        $contacts = $this->contacts->paginate(
            $filters,
            perPage: 25,
            page: (int) $request->input('page', 1),
        )->withQueryString();

        return view('leadhub::contacts.index', [
            'contacts' => $contacts,
            'filters' => array_filter([
                'status' => $filters['status'],
                'source' => $filters['source_form'],
                'tag' => $filters['tag_id'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'followup' => $filters['has_followup'],
                'q' => $filters['search'],
                'archived' => $filters['archived'] ? 1 : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'statuses' => (array) config('leadhub.statuses', []),
            'tags' => $this->tagsRepo->all(),
            'sources' => $this->mappings->enabledHandles(),
        ]);
    }

    public function show(Request $request, int|string $contactId)
    {
        abort_unless($request->user()?->can('view leadhub contacts'), 403);

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $events = $this->events->forContact(
            $contact,
            perPage: 20,
            page: (int) $request->input('page', 1),
        );

        $activeFollowups = $contact->getRelation('followups') ?? collect();
        $activeFollowup = $activeFollowups instanceof \Illuminate\Support\Collection
            ? $activeFollowups->whereNull('completed_at')->sortBy('due_at')->first()
            : null;

        return view('leadhub::contacts.show', [
            'contact' => $contact,
            'events' => $events,
            'activeFollowup' => $activeFollowup,
            'statuses' => (array) config('leadhub.statuses', []),
            'allTags' => $this->tagsRepo->all(),
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

            $toAttach = array_diff($newTagIds, $existingTagIds);
            $toDetach = array_diff($existingTagIds, $newTagIds);

            foreach ($toAttach as $id) {
                if ($tag = $this->tagsRepo->find($id)) {
                    $this->tags->attach($contact, $tag);
                }
            }

            foreach ($toDetach as $id) {
                if ($tag = $this->tagsRepo->find($id)) {
                    $this->tags->detach($contact, $tag);
                }
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $this->contacts->find($contactId)]);
        }

        return redirect()
            ->route('statamic.cp.leadhub.contacts.show', $contact)
            ->with('success', __('leadhub::contacts.flashes.updated'));
    }

    public function destroy(Request $request, int|string $contactId)
    {
        abort_unless($request->user()?->can('delete leadhub contacts'), 403);

        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        event(new LeadHubContactDeleted($contact));

        $this->contacts->delete($contact);

        if ($request->expectsJson()) {
            return response()->json(['data' => ['ok' => true]]);
        }

        return redirect()
            ->route('statamic.cp.leadhub.contacts.index')
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
