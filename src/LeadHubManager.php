<?php

namespace Goldnead\Leadhub;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Contracts\SourceProjector;
use Goldnead\Leadhub\Events\LeadHubNoteAdded;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\ContactResolver;
use Goldnead\Leadhub\Services\FollowupService;
use Goldnead\Leadhub\Services\IngestionService;
use Goldnead\Leadhub\Services\TagService;
use Goldnead\Leadhub\Services\TimelineService;
use Goldnead\Leadhub\Support\ContactDto;
use Goldnead\Leadhub\Support\SourceEvent;

/**
 * The public, stable API surface for LeadHub. Resolved through the
 * {@see \Goldnead\Leadhub\Facades\LeadHub} facade and consumed by sibling
 * addons (statamic-automations, statamic-webhook-manager) as well as host apps.
 *
 * Every mutating method returns a normalized array representation of the
 * affected contact so callers never need to touch Eloquent models directly.
 */
class LeadHubManager
{
    public function __construct(
        protected ContactRepository $contacts,
        protected TagRepository $tags,
        protected NoteRepository $notes,
        protected FollowupRepository $followups,
        protected ContactResolver $resolver,
        protected TagService $tagService,
        protected FollowupService $followupService,
        protected TimelineService $timeline,
        protected IngestionService $ingestion,
    ) {
    }

    // -- Reads --------------------------------------------------------------

    /** Configured status key => label map. */
    public function statuses(): array
    {
        return (array) config('leadhub.statuses', []);
    }

    /** All tags as [id, name, slug] arrays. */
    public function tags(): array
    {
        return $this->tags->all()
            ->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug])
            ->values()
            ->all();
    }

    /** Find a contact by id or uuid, normalized to an array (or null). */
    public function find(int|string $id): ?array
    {
        $contact = $this->contacts->find($id);

        return $contact ? $this->present($contact) : null;
    }

    /** Find a contact by (un-normalized) email, normalized to an array (or null). */
    public function findByEmail(string $email): ?array
    {
        $contact = $this->contacts->findByEmailNormalized(
            \Goldnead\Leadhub\Support\EmailNormalizer::normalize($email)
        );

        return $contact ? $this->present($contact) : null;
    }

    // -- Writes -------------------------------------------------------------

    /**
     * Create — or update the existing match by email — a contact.
     *
     * @param  array<string,mixed>  $attributes  email, first_name, last_name,
     *         full_name, phone, company, status, source, tags[]
     */
    public function create(array $attributes): array
    {
        $dto = new ContactDto(
            email: $attributes['email'] ?? null,
            firstName: $attributes['first_name'] ?? null,
            lastName: $attributes['last_name'] ?? null,
            fullName: $attributes['full_name'] ?? null,
            phone: $attributes['phone'] ?? null,
            company: $attributes['company'] ?? null,
            tags: (array) ($attributes['tags'] ?? []),
            source: $attributes['source'] ?? null,
            defaultStatus: $attributes['status'] ?? null,
        );

        [$contact] = $this->resolver->resolveOrCreate($dto);

        if (! empty($attributes['status']) && $contact->status !== $attributes['status']) {
            $this->applyStatus($contact, (string) $attributes['status']);
        }

        if (! empty($attributes['tags'])) {
            $this->tagService->attachMany($contact, (array) $attributes['tags'], silent: true);
        }

        return $this->present($this->reload($contact));
    }

    /**
     * Update an existing contact by id/uuid. Status changes route through the
     * status workflow (timeline + event).
     */
    public function update(int|string $id, array $attributes): array
    {
        $contact = $this->mustFind($id);

        $fillable = ['email', 'first_name', 'last_name', 'full_name', 'phone', 'company', 'source'];

        foreach ($fillable as $key) {
            if (array_key_exists($key, $attributes)) {
                $contact->setAttribute($key, $attributes[$key]);
            }
        }

        $this->contacts->save($contact);

        if (! empty($attributes['status']) && $contact->status !== $attributes['status']) {
            $this->applyStatus($contact, (string) $attributes['status']);
        }

        return $this->present($this->reload($contact));
    }

    public function addTag(int|string $id, string $tag): array
    {
        $contact = $this->mustFind($id);
        $this->tagService->attach($contact, $tag);

        return $this->present($this->reload($contact));
    }

    public function removeTag(int|string $id, string $tag): array
    {
        $contact = $this->mustFind($id);
        $this->tagService->detach($contact, $tag);

        return $this->present($this->reload($contact));
    }

    public function changeStatus(int|string $id, string $status): array
    {
        $contact = $this->mustFind($id);
        $this->applyStatus($contact, $status);

        return $this->present($this->reload($contact));
    }

    public function addNote(int|string $id, string $body, ?string $userId = null): array
    {
        $contact = $this->mustFind($id);

        $note = $this->notes->create($contact, $body, $userId);
        $this->timeline->recordNoteAdded($contact, $body);
        event(new LeadHubNoteAdded($contact, metadata: ['note_id' => $note->id]));

        return $this->present($this->reload($contact));
    }

    /**
     * @param  array{due_at?:string,due_in_days?:int,note?:string,created_by?:string}  $data
     */
    public function createFollowUp(int|string $id, array $data): array
    {
        $contact = $this->mustFind($id);

        $dueAt = isset($data['due_at'])
            ? \Illuminate\Support\Carbon::parse($data['due_at'])
            : now()->addDays((int) ($data['due_in_days'] ?? 1));

        $followup = $this->followupService->set(
            $contact,
            $dueAt,
            $data['note'] ?? null,
            $data['created_by'] ?? null,
        );

        $result = $this->present($this->reload($contact));
        $result['followup_id'] = $followup->id;

        return $result;
    }

    public function completeFollowUp(int|string $id, int|string $followUpId): array
    {
        $contact = $this->mustFind($id);

        $followup = $this->followups->find($followUpId);

        if ($followup && (string) $followup->contact_id === (string) $contact->id) {
            $this->followupService->complete($followup);
        }

        return $this->present($this->reload($contact));
    }

    // -- Ingestion ----------------------------------------------------------

    /** Ingest a generic source event (purchase, booking, login, webhook, …). */
    public function ingest(SourceEvent|array $event): ?Event
    {
        $event = $event instanceof SourceEvent ? $event : SourceEvent::fromArray($event);

        return $this->ingestion->ingest($event);
    }

    /** Register a host-app SourceProjector so its model auto-projects on ingest. */
    public function registerSourceProjector(SourceProjector $projector): void
    {
        $this->ingestion->registerProjector($projector);
    }

    /** Project + ingest a raw model through its registered projector. */
    public function projectAndIngest(mixed $model): ?Event
    {
        return $this->ingestion->projectAndIngest($model);
    }

    // -- Internals ----------------------------------------------------------

    protected function applyStatus(Contact $contact, string $status): void
    {
        $from = (string) $contact->status;

        if ($from === $status) {
            return;
        }

        $contact->status = $status;
        $this->contacts->save($contact);

        $this->timeline->recordStatusChanged($contact, $from, $status);
        event(new LeadHubStatusChanged($contact, null, ['from' => $from, 'to' => $status]));
    }

    protected function mustFind(int|string $id): Contact
    {
        $contact = $this->contacts->find($id);

        if (! $contact instanceof Contact) {
            throw new \RuntimeException("LeadHub contact [{$id}] not found.");
        }

        return $contact;
    }

    /**
     * Re-fetch a contact through the repository so presenter output reflects
     * the latest persisted state. Works across both storage drivers (the
     * Eloquent model's refresh() would not, under the flat-file driver).
     */
    protected function reload(Contact $contact): Contact
    {
        return $this->contacts->find($contact->id) ?? $contact;
    }

    /**
     * Normalize a Contact to the stable public array shape. Tags are pulled
     * through the TagRepository so this is driver-agnostic.
     */
    public function present(Contact $contact): array
    {
        $tagNames = $this->tags->forContact($contact)
            ->map(fn ($tag) => is_array($tag) ? ($tag['name'] ?? null) : $tag->name)
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $contact->id,
            'uuid' => $contact->uuid,
            'email' => $contact->email,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'full_name' => $contact->displayName(),
            'phone' => $contact->phone,
            'company' => $contact->company,
            'status' => $contact->status,
            'source' => $contact->source,
            'tags' => $tagNames,
            'owner_id' => $contact->assigned_to,
            'created_at' => optional($contact->created_at)->toIso8601String(),
            'last_activity_at' => optional($contact->last_activity_at)->toIso8601String(),
        ];
    }
}
