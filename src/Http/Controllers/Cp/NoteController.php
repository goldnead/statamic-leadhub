<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Events\LeadHubNoteAdded;
use Goldnead\Leadhub\Http\Requests\StoreNoteRequest;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\TimelineService;

class NoteController extends Controller
{
    public function __construct(protected TimelineService $timeline)
    {
    }

    public function store(StoreNoteRequest $request, int $contactId)
    {
        $contact = Contact::query()->findOrFail($contactId);

        $note = $contact->notes()->create([
            'body' => $request->string('body')->toString(),
            'user_id' => (string) $request->user()?->id(),
        ]);

        $this->timeline->recordNoteAdded($contact, $note->body);
        event(new LeadHubNoteAdded($contact, metadata: ['note_id' => $note->id]));

        if ($request->expectsJson()) {
            return response()->json(['data' => $note], 201);
        }

        return back()->with('success', __('leadhub::contacts.flashes.note_added'));
    }
}
