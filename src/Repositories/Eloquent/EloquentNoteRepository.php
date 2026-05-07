<?php

namespace Goldnead\Leadhub\Repositories\Eloquent;

use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Note;
use Illuminate\Support\Collection;

class EloquentNoteRepository implements NoteRepository
{
    public function create(Contact $contact, string $body, ?string $userId = null): Note
    {
        return $contact->notes()->create([
            'body' => $body,
            'user_id' => $userId,
        ]);
    }

    public function forContact(Contact|int|string $contact): Collection
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        return Note::query()
            ->where('contact_id', $contactId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function delete(Note $note): void
    {
        $note->delete();
    }

    public function deleteForContact(Contact|int|string $contact): void
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        Note::query()->where('contact_id', $contactId)->delete();
    }
}
