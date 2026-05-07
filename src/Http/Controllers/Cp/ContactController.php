<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Events\LeadHubContactArchived;
use Goldnead\Leadhub\Events\LeadHubContactDeleted;
use Goldnead\Leadhub\Http\Requests\UpdateContactRequest;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Tag;
use Goldnead\Leadhub\Services\TagService;
use Goldnead\Leadhub\Services\TimelineService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(
        protected TimelineService $timeline,
        protected TagService $tags,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('view leadhub contacts'), 403);

        $query = Contact::query()->with('tags', 'followups');

        // Default: hide archived unless explicitly asked.
        $showArchived = $request->boolean('archived');
        $query = $showArchived ? $query->archived() : $query->active();

        // Filters
        if ($status = $request->string('status')->toString()) {
            $query->withStatus($status);
        }

        if ($source = $request->string('source')->toString()) {
            $query->where('source_form', $source);
        }

        if ($tagId = $request->input('tag')) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('leadhub_tags.id', $tagId));
        }

        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to);
        }

        if ($request->filled('followup')) {
            switch ($request->string('followup')->toString()) {
                case 'has':
                    $query->whereHas('followups', fn (Builder $q) => $q->whereNull('completed_at'));
                    break;
                case 'today':
                    $query->whereHas('followups', fn (Builder $q) => $q->dueToday());
                    break;
                case 'overdue':
                    $query->whereHas('followups', fn (Builder $q) => $q->overdue());
                    break;
            }
        }

        if ($search = $request->string('q')->toString()) {
            $query->search($search);
        }

        // Sorting
        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'last_activity_at', 'status'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }
        $query->orderBy($sort, $direction);

        $contacts = $query->paginate(25)->withQueryString();

        return view('leadhub::contacts.index', [
            'contacts' => $contacts,
            'filters' => $request->only(['status', 'source', 'tag', 'from', 'to', 'followup', 'q', 'archived']),
            'statuses' => (array) config('leadhub.statuses', []),
            'tags' => Tag::query()->orderBy('name')->get(),
            'sources' => \Goldnead\Leadhub\Models\FormMapping::query()
                ->where('enabled', true)
                ->pluck('form_handle')
                ->all(),
        ]);
    }

    public function show(Request $request, int $contactId)
    {
        abort_unless($request->user()?->hasPermission('view leadhub contacts'), 403);

        $contact = Contact::query()
            ->with(['tags', 'followups', 'notes'])
            ->findOrFail($contactId);

        $events = $contact->events()
            ->paginate(20);

        return view('leadhub::contacts.show', [
            'contact' => $contact,
            'events' => $events,
            'activeFollowup' => $contact->activeFollowup(),
            'statuses' => (array) config('leadhub.statuses', []),
            'allTags' => Tag::query()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateContactRequest $request, int $contactId)
    {
        $contact = Contact::query()->findOrFail($contactId);

        $oldStatus = $contact->status;

        $contact->fill($request->validated());
        $contact->save();

        // Status change side-effect
        if ($contact->wasChanged('status')) {
            $this->timeline->recordStatusChanged($contact, $oldStatus, $contact->status);
            event(new \Goldnead\Leadhub\Events\LeadHubStatusChanged(
                $contact,
                metadata: ['from' => $oldStatus, 'to' => $contact->status]
            ));
        }

        // Tag sync from request
        if ($request->has('tag_ids')) {
            $newTagIds = collect($request->input('tag_ids', []))->map('intval')->all();
            $existingTagIds = $contact->tags->pluck('id')->all();

            $toAttach = array_diff($newTagIds, $existingTagIds);
            $toDetach = array_diff($existingTagIds, $newTagIds);

            foreach ($toAttach as $id) {
                if ($tag = Tag::find($id)) {
                    $this->tags->attach($contact, $tag);
                }
            }

            foreach ($toDetach as $id) {
                if ($tag = Tag::find($id)) {
                    $this->tags->detach($contact, $tag);
                }
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $contact->fresh()->load('tags', 'followups'),
            ]);
        }

        return redirect()
            ->route('statamic.cp.leadhub.contacts.show', $contact)
            ->with('success', __('leadhub::contacts.flashes.updated'));
    }

    public function destroy(Request $request, int $contactId)
    {
        abort_unless($request->user()?->hasPermission('delete leadhub contacts'), 403);

        $contact = Contact::query()->findOrFail($contactId);

        event(new LeadHubContactDeleted($contact));

        $contact->delete();

        if ($request->expectsJson()) {
            return response()->json(['data' => ['ok' => true]]);
        }

        return redirect()
            ->route('statamic.cp.leadhub.contacts.index')
            ->with('success', __('leadhub::contacts.flashes.deleted'));
    }

    public function archive(Request $request, int $contactId)
    {
        abort_unless($request->user()?->hasPermission('archive leadhub contacts'), 403);

        $contact = Contact::query()->findOrFail($contactId);
        $contact->archived_at = now();
        $contact->save();

        $this->timeline->recordContactArchived($contact);
        event(new LeadHubContactArchived($contact));

        return back()->with('success', __('leadhub::contacts.flashes.archived'));
    }

    public function restore(Request $request, int $contactId)
    {
        abort_unless($request->user()?->hasPermission('archive leadhub contacts'), 403);

        $contact = Contact::query()->findOrFail($contactId);
        $contact->archived_at = null;
        $contact->save();

        return back()->with('success', __('leadhub::contacts.flashes.restored'));
    }
}
