<?php

namespace Goldnead\Leadhub\Contracts\Repositories;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Note;
use Illuminate\Support\Collection;

interface NoteRepository
{
    public function create(Contact $contact, string $body, ?string $userId = null): Note;

    public function forContact(Contact|int|string $contact): Collection;

    public function delete(Note $note): void;

    public function deleteForContact(Contact|int|string $contact): void;
}
