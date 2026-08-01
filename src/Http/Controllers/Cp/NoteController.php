<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Events\LeadHubNoteAdded;
use Goldnead\Leadhub\Http\Requests\StoreNoteRequest;
use Goldnead\Leadhub\Services\TimelineService;

class NoteController extends Controller
{
    public function __construct(
        protected ContactRepository $contacts,
        protected NoteRepository $notes,
        protected TimelineService $timeline,
    ) {}

    public function store(StoreNoteRequest $request, int|string $contactId)
    {
        $contact = $this->contacts->find($contactId);
        abort_unless($contact, 404);

        $body = $request->string('body')->toString();
        $userId = $this->userId($request);

        $note = $this->notes->create($contact, $body, $userId !== '' ? $userId : null);

        $this->timeline->recordNoteAdded($contact, $body);
        event(new LeadHubNoteAdded($contact, metadata: ['note_id' => $note->id]));

        return back()->with('success', __('leadhub::contacts.flashes.note_added'));
    }
}
