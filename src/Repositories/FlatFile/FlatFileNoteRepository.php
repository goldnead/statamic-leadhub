<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Note;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Notes are embedded directly in the contact YAML for atomicity.
 * This repository reads/writes the contact file under the hood.
 */
class FlatFileNoteRepository implements NoteRepository
{
    public function __construct(
        protected FileStore $files,
        protected ModelHydrator $hydrator,
    ) {
    }

    public function create(Contact $contact, string $body, ?string $userId = null): Note
    {
        $uuid = (string) Str::uuid();
        $now = now()->toIso8601String();

        $record = [
            'id' => $uuid,
            'uuid' => $uuid,
            'contact_id' => (string) $contact->id,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $path = $this->contactPath($contact->id);
        $data = $this->files->readYaml($path);
        $data['notes'] = $data['notes'] ?? [];
        $data['notes'][] = $record;
        $this->files->writeYaml($path, $data);

        return $this->hydrator->note($record);
    }

    public function forContact(Contact|int|string $contact): Collection
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $data = $this->files->readYaml($this->contactPath($contactId));

        $records = $data['notes'] ?? [];

        usort($records, fn ($a, $b) => strcmp(
            $b['created_at'] ?? '',
            $a['created_at'] ?? '',
        ));

        return collect($records)->map(fn ($r) => $this->hydrator->note($r));
    }

    public function delete(Note $note): void
    {
        $path = $this->contactPath($note->contact_id);
        $data = $this->files->readYaml($path);

        if (! isset($data['notes'])) {
            return;
        }

        $data['notes'] = array_values(array_filter(
            $data['notes'],
            fn ($n) => ($n['uuid'] ?? null) !== $note->uuid,
        ));

        $this->files->writeYaml($path, $data);
    }

    public function deleteForContact(Contact|int|string $contact): void
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $path = $this->contactPath($contactId);
        $data = $this->files->readYaml($path);

        if (! isset($data['notes'])) {
            return;
        }

        $data['notes'] = [];
        $this->files->writeYaml($path, $data);
    }

    protected function contactPath(int|string $contactId): string
    {
        return 'contacts/'.$contactId.'.yaml';
    }
}
