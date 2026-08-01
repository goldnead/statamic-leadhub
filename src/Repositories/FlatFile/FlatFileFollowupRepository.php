<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use DateTimeInterface;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Followup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Followups are embedded in the contact YAML for the active one, and
 * appended to a per-contact JSON-Lines log for history. Find/list operations
 * scan the contact YAMLs for active followups.
 */
class FlatFileFollowupRepository implements FollowupRepository
{
    public function __construct(
        protected FileStore $files,
        protected Index $contactsIndex,
        protected IndexBuilder $builder,
        protected ModelHydrator $hydrator,
    ) {}

    public function find(int|string $id): ?Followup
    {
        $id = (string) $id;

        // Look at every contact's active_followup, and history log.
        foreach ($this->files->glob('contacts/*.yaml') as $relative) {
            $data = $this->files->readYaml($relative);
            $af = $data['active_followup'] ?? null;
            if ($af && (($af['id'] ?? null) === $id || ($af['uuid'] ?? null) === $id)) {
                $af['contact_id'] = $data['uuid'] ?? null;

                return $this->hydrator->followup($af);
            }
        }

        foreach ($this->files->glob('followups/*.jsonl') as $relative) {
            foreach ($this->files->readJsonLines($relative) as $record) {
                if (($record['id'] ?? null) === $id || ($record['uuid'] ?? null) === $id) {
                    return $this->hydrator->followup($record);
                }
            }
        }

        return null;
    }

    public function create(Contact $contact, DateTimeInterface $dueAt, ?string $note = null, ?string $createdBy = null): Followup
    {
        $uuid = (string) Str::uuid();
        $now = now()->toIso8601String();

        $record = [
            'id' => $uuid,
            'uuid' => $uuid,
            'contact_id' => (string) $contact->id,
            'due_at' => $dueAt instanceof Carbon
                ? $dueAt->toIso8601String()
                : Carbon::parse($dueAt->format('Y-m-d H:i:s'))->toIso8601String(),
            'note' => $note,
            'completed_at' => null,
            'created_by' => $createdBy,
            'completed_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Mirror as the active followup on the contact YAML.
        $contactPath = 'contacts/'.$contact->id.'.yaml';
        $data = $this->files->readYaml($contactPath);

        // A contact YAML that does not exist yet would be created here holding
        // nothing but the follow-up. That file is invisible to the index,
        // which skips records without a uuid, and yet visible to every scan in
        // this class that globs the directory — so the digest command found a
        // follow-up whose contact_id resolved to null and crashed on it. Stamp
        // the identity so the two views of the same directory agree.
        $data['uuid'] = $data['uuid'] ?? (string) $contact->uuid ?: (string) $contact->id;
        $data['id'] = $data['id'] ?? $data['uuid'];

        $data['active_followup'] = $record;
        $this->files->writeYaml($contactPath, $data);

        // Also append to the history log.
        $this->files->appendJsonLine($this->logPathFor($contact->id), $record);

        $this->builder->rebuildContacts($this->contactsIndex);

        return $this->hydrator->followup($record);
    }

    public function update(Followup $followup, ?DateTimeInterface $dueAt = null, ?string $note = null): Followup
    {
        $contactPath = 'contacts/'.$followup->contact_id.'.yaml';
        $data = $this->files->readYaml($contactPath);
        $af = $data['active_followup'] ?? null;

        if (! $af || ($af['uuid'] ?? null) !== $followup->uuid) {
            return $followup;
        }

        if ($dueAt !== null) {
            $af['due_at'] = $dueAt instanceof Carbon
                ? $dueAt->toIso8601String()
                : Carbon::parse($dueAt->format('Y-m-d H:i:s'))->toIso8601String();
        }
        if ($note !== null) {
            $af['note'] = $note;
        }
        $af['updated_at'] = now()->toIso8601String();

        $data['active_followup'] = $af;
        $this->files->writeYaml($contactPath, $data);

        $this->builder->rebuildContacts($this->contactsIndex);

        return $this->hydrator->followup($af);
    }

    public function markCompleted(Followup $followup, ?string $completedBy = null): Followup
    {
        $contactPath = 'contacts/'.$followup->contact_id.'.yaml';
        $data = $this->files->readYaml($contactPath);
        $af = $data['active_followup'] ?? null;

        if ($af && ($af['uuid'] ?? null) === $followup->uuid) {
            $af['completed_at'] = now()->toIso8601String();
            $af['completed_by'] = $completedBy;
            $af['updated_at'] = now()->toIso8601String();

            // Append final state to history, clear active.
            $this->files->appendJsonLine($this->logPathFor($followup->contact_id), $af);

            unset($data['active_followup']);
            $this->files->writeYaml($contactPath, $data);

            $this->builder->rebuildContacts($this->contactsIndex);

            return $this->hydrator->followup($af);
        }

        return $followup;
    }

    public function delete(Followup $followup): void
    {
        $contactPath = 'contacts/'.$followup->contact_id.'.yaml';
        $data = $this->files->readYaml($contactPath);

        if (isset($data['active_followup'])
            && ($data['active_followup']['uuid'] ?? null) === $followup->uuid) {
            unset($data['active_followup']);
            $this->files->writeYaml($contactPath, $data);
            $this->builder->rebuildContacts($this->contactsIndex);
        }
    }

    public function activeFor(Contact|int|string $contact): Collection
    {
        $f = $this->activeForOne($contact);

        return $f ? collect([$f]) : collect();
    }

    public function activeForOne(Contact|int|string $contact): ?Followup
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $data = $this->files->readYaml('contacts/'.$contactId.'.yaml');
        $af = $data['active_followup'] ?? null;

        if (! $af) {
            return null;
        }

        $af['contact_id'] = $contactId;

        return $this->hydrator->followup($af);
    }

    public function removeActiveFor(Contact|int|string $contact): int
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $contactPath = 'contacts/'.$contactId.'.yaml';
        $data = $this->files->readYaml($contactPath);

        if (! isset($data['active_followup'])) {
            return 0;
        }

        unset($data['active_followup']);
        $this->files->writeYaml($contactPath, $data);
        $this->builder->rebuildContacts($this->contactsIndex);

        return 1;
    }

    public function dueToday(?int $limit = null): Collection
    {
        return $this->activeFollowupsFiltered(
            fn (Carbon $due) => $due->isToday(),
            $limit,
        );
    }

    public function overdue(?int $limit = null): Collection
    {
        return $this->activeFollowupsFiltered(
            fn (Carbon $due) => $due->isPast() && ! $due->isToday(),
            $limit,
        );
    }

    public function upcoming(?int $limit = null): Collection
    {
        return $this->activeFollowupsFiltered(
            fn (Carbon $due) => $due->isFuture() && ! $due->isToday(),
            $limit,
        );
    }

    public function countDueToday(): int
    {
        return $this->dueToday()->count();
    }

    public function countOverdue(): int
    {
        return $this->overdue()->count();
    }

    public function deleteForContact(Contact|int|string $contact): void
    {
        $contactId = $contact instanceof Contact ? $contact->id : $contact;
        $this->files->delete($this->logPathFor($contactId));

        $contactPath = 'contacts/'.$contactId.'.yaml';
        if ($this->files->exists($contactPath)) {
            $data = $this->files->readYaml($contactPath);
            unset($data['active_followup']);
            $this->files->writeYaml($contactPath, $data);
        }
    }

    protected function logPathFor(int|string $contactId): string
    {
        return 'followups/'.$contactId.'.jsonl';
    }

    /**
     * Scan all contacts for active followups matching the predicate, with
     * each followup's contact attached. Skips archived contacts.
     */
    protected function activeFollowupsFiltered(callable $predicate, ?int $limit): Collection
    {
        $results = collect();

        foreach ($this->files->glob('contacts/*.yaml') as $relative) {
            $data = $this->files->readYaml($relative);
            if (! empty($data['archived_at'])) {
                continue;
            }

            $af = $data['active_followup'] ?? null;
            if (! $af || empty($af['due_at'])) {
                continue;
            }

            try {
                $due = Carbon::parse($af['due_at']);
            } catch (\Throwable) {
                continue;
            }

            if (! $predicate($due)) {
                continue;
            }

            // Fall back to the id embedded in the follow-up record itself. The
            // contact YAML is the primary source, but a file written before
            // the identity stamp above has no uuid, and a follow-up whose
            // contact cannot be named is one the digest cannot deliver.
            $af['contact_id'] = $data['uuid'] ?? $data['id'] ?? ($af['contact_id'] ?? null);

            if (blank($af['contact_id'])) {
                continue;
            }

            $followup = $this->hydrator->followup($af);
            $followup->setRelation('contact', $this->hydrator->contact($data));

            $results->push($followup);

            if ($limit !== null && $results->count() >= $limit) {
                break;
            }
        }

        return $results->sortBy('due_at')->values();
    }
}
