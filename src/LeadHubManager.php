<?php

namespace Goldnead\Leadhub;

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Contracts\SourceProjector;
use Goldnead\Leadhub\Events\LeadHubContactScoreChanged;
use Goldnead\Leadhub\Events\LeadHubNoteAdded;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Leadhub\Services\CompanyResolver;
use Goldnead\Leadhub\Services\ContactMergeService;
use Goldnead\Leadhub\Services\ContactResolver;
use Goldnead\Leadhub\Services\CrmSyncService;
use Goldnead\Leadhub\Services\FollowupService;
use Goldnead\Leadhub\Services\IngestionService;
use Goldnead\Leadhub\Services\OpportunityService;
use Goldnead\Leadhub\Services\ScoringService;
use Goldnead\Leadhub\Services\SegmentService;
use Goldnead\Leadhub\Services\StageTransitionService;
use Goldnead\Leadhub\Services\TagService;
use Goldnead\Leadhub\Services\TaskService;
use Goldnead\Leadhub\Services\TimelineService;
use Goldnead\Leadhub\Support\ContactDto;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Leadhub\Support\SourceEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The public, stable API surface for LeadHub. Resolved through the
 * {@see LeadHub} facade and consumed by sibling
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
        protected SegmentRepository $segmentRepository,
        protected SegmentService $segmentService,
    ) {}

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

    /**
     * All segments as [id, name, handle, is_active, members_count] arrays.
     *
     * @return array<int,array{id:mixed,name:string,handle:string,is_active:bool,members_count:int}>
     */
    public function segments(): array
    {
        return $this->segmentRepository->all()
            ->map(fn ($segment) => [
                'id' => $segment->uuid,
                'name' => $segment->name,
                'handle' => $segment->handle,
                'is_active' => (bool) $segment->is_active,
                'members_count' => $this->segmentRepository->membersCount($segment),
            ])
            ->values()
            ->all();
    }

    /**
     * Member contact UUIDs for a segment handle, resolved LIVE from the
     * segment's rules (not the materialized pivot). Returns [] for an unknown
     * or inactive segment. This is the stable contract consumers (e.g.
     * statamic-marketing campaign audience narrowing) build on.
     *
     * @return array<int,string>
     */
    public function segmentMemberIds(string $handle): array
    {
        return $this->segmentService->resolveMemberIds($handle);
    }

    /**
     * Is a contact (by model, id, or uuid) a member of the segment identified
     * by $handle? Cheap, reactive path — evaluates the contact's fields against
     * the segment rules without scanning the whole contact set.
     */
    public function contactInSegment(Contact|int|string $contactOrId, string $handle): bool
    {
        return $this->segmentService->contactInSegment($contactOrId, $handle);
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
            EmailNormalizer::normalize($email)
        );

        return $contact ? $this->present($contact) : null;
    }

    // -- Writes -------------------------------------------------------------

    /**
     * Create — or update the existing match by email — a contact.
     *
     * @param  array<string,mixed>  $attributes  email, first_name, last_name,
     *                                           full_name, phone, company, status, source, tags[]
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

    /**
     * Relative engagement-score change — the target of the automations
     * "change score" action. Resolves the contact by id/uuid (or accepts a
     * Contact), applies $delta (clamped at 0), persists, and returns the new
     * score — or null if the contact can't be found. Routes through
     * {@see ScoringService::adjust} so {@see LeadHubContactScoreChanged}
     * fires on the same mutation path as activity-based scoring.
     */
    public function adjustScore(string|Contact $contact, int $delta, ?string $reason = null): ?int
    {
        $model = $contact instanceof Contact ? $contact : $this->contacts->find($contact);

        if (! $model instanceof Contact) {
            return null;
        }

        return app(ScoringService::class)->adjust($model, $delta, $reason);
    }

    /**
     * Absolute engagement-score set. Same resolution + return contract as
     * {@see adjustScore}; fires LeadHubContactScoreChanged on a real change.
     */
    public function setScore(string|Contact $contact, int $score, ?string $reason = null): ?int
    {
        $model = $contact instanceof Contact ? $contact : $this->contacts->find($contact);

        if (! $model instanceof Contact) {
            return null;
        }

        return app(ScoringService::class)->set($model, $score, $reason);
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

    /**
     * Mark a contact as opted-out: sets do_not_contact and actively removes /
     * unsubscribes them from any CRM destination that supports removal.
     */
    public function optOut(int|string $id): array
    {
        $contact = $this->mustFind($id);

        if (! $contact->do_not_contact) {
            $contact->do_not_contact = true;
            $this->contacts->save($contact);
        }

        app(CrmSyncService::class)->removeContact($contact);

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
            ? Carbon::parse($data['due_at'])
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

    // -- Pipelines & opportunities -----------------------------------------

    /**
     * Create a pipeline with its stages in one call.
     *
     * @param  array<int,array{name:string,slug?:string,is_terminal?:bool,terminal_outcome?:string}>  $stages
     */
    public function createPipeline(string $name, array $stages = [], ?string $slug = null): array
    {
        $pipeline = Pipeline::query()->create([
            'name' => $name,
            'slug' => $slug ?: Str::slug($name),
        ]);

        foreach (array_values($stages) as $index => $stage) {
            $pipeline->stages()->create([
                'name' => $stage['name'],
                'slug' => $stage['slug'] ?? Str::slug($stage['name']),
                'sort_order' => $stage['sort_order'] ?? $index,
                'is_terminal' => $stage['is_terminal'] ?? false,
                'terminal_outcome' => $stage['terminal_outcome'] ?? null,
            ]);
        }

        return $this->presentPipeline($pipeline->refresh());
    }

    /**
     * Create or update an opportunity for a contact within a pipeline (by slug
     * or id). Deduped by (contact, pipeline, source) when source_* are given.
     */
    public function upsertOpportunity(int|string $contactId, int|string $pipeline, array $attributes = []): array
    {
        $contact = $this->mustFind($contactId);
        $pipelineModel = $this->resolvePipeline($pipeline);

        $opportunity = app(OpportunityService::class)
            ->createOrUpdate($contact, $pipelineModel, $attributes);

        return $this->presentOpportunity($opportunity);
    }

    /** Move an opportunity to a stage (by slug or id) within its pipeline. */
    public function moveStage(int|string $opportunityId, int|string $stage, ?string $note = null): array
    {
        $opportunity = Opportunity::query()->findOrFail($opportunityId);

        $stageModel = is_numeric($stage)
            ? Stage::query()->findOrFail($stage)
            : Stage::query()
                ->where('pipeline_id', $opportunity->pipeline_id)
                ->where('slug', $stage)
                ->firstOrFail();

        app(StageTransitionService::class)
            ->transition($opportunity, $stageModel, $note);

        return $this->presentOpportunity($opportunity->refresh());
    }

    protected function resolvePipeline(int|string $pipeline): Pipeline
    {
        return is_numeric($pipeline)
            ? Pipeline::query()->findOrFail($pipeline)
            : Pipeline::query()->where('slug', $pipeline)->firstOrFail();
    }

    protected function presentPipeline(Pipeline $pipeline): array
    {
        return [
            'id' => $pipeline->id,
            'uuid' => $pipeline->uuid,
            'name' => $pipeline->name,
            'slug' => $pipeline->slug,
            'is_active' => (bool) $pipeline->is_active,
            'stages' => $pipeline->stages->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'is_terminal' => (bool) $s->is_terminal,
                'terminal_outcome' => $s->terminal_outcome,
            ])->all(),
        ];
    }

    protected function presentOpportunity(Opportunity $opportunity): array
    {
        return [
            'id' => $opportunity->id,
            'uuid' => $opportunity->uuid,
            'contact_id' => $opportunity->contact_id,
            'pipeline_id' => $opportunity->pipeline_id,
            'stage_id' => $opportunity->stage_id,
            'title' => $opportunity->title,
            'value_estimate' => $opportunity->value_estimate,
            'confidence' => $opportunity->confidence,
            'status' => $opportunity->status,
            'outcome' => $opportunity->outcome,
            'owner_id' => $opportunity->owner_id,
        ];
    }

    // -- Tasks --------------------------------------------------------------

    /**
     * Create a task, optionally bound to a contact (by id/uuid).
     *
     * @param  array{title:string,description?:string,priority?:string,due_at?:string,assignee_id?:string,opportunity_id?:int,created_by?:string}  $attributes
     */
    public function createTask(array $attributes, int|string|null $contactId = null): array
    {
        $contact = $contactId !== null ? $this->mustFind($contactId) : null;

        $task = app(TaskService::class)->create($attributes, $contact);

        return $this->presentTask($task);
    }

    public function completeTask(int|string $taskId, ?string $completedBy = null): array
    {
        $task = Task::query()->findOrFail($taskId);
        $completed = app(TaskService::class)->complete($task, $completedBy);

        return $this->presentTask($completed);
    }

    protected function presentTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'uuid' => $task->uuid,
            'contact_id' => $task->contact_id,
            'opportunity_id' => $task->opportunity_id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_at' => optional($task->due_at)->toIso8601String(),
            'assignee_id' => $task->assignee_id,
            'completed_at' => optional($task->completed_at)->toIso8601String(),
        ];
    }

    // -- Companies ----------------------------------------------------------

    /**
     * Create (or dedupe to an existing) company and return it as an array.
     */
    public function createCompany(array $attributes): array
    {
        [$company] = app(CompanyResolver::class)->resolveOrCreate($attributes);

        return $this->presentCompany($company);
    }

    /**
     * Link a company to a contact. $company may be an existing company id or an
     * array of company attributes (which will be resolved/created).
     */
    public function linkCompany(int|string $contactId, int|string|array $company, ?string $label = null, bool $primary = false): array
    {
        $contact = $this->mustFind($contactId);
        $resolver = app(CompanyResolver::class);

        if (is_array($company)) {
            [$companyModel] = $resolver->resolveOrCreate($company);
        } else {
            $companyModel = Company::query()->findOrFail($company);
        }

        $resolver->link($contact, $companyModel, $label, $primary);

        return $this->presentCompany($companyModel);
    }

    protected function presentCompany(Company $company): array
    {
        return [
            'id' => $company->id,
            'uuid' => $company->uuid,
            'name' => $company->name,
            'domain' => $company->domain,
            'website' => $company->website,
            'industry' => $company->industry,
            'employee_range' => $company->employee_range,
            'status' => $company->status,
        ];
    }

    /**
     * Merge a duplicate contact into a surviving one. Both are resolved by
     * id/uuid. Returns the surviving contact.
     */
    public function merge(int|string $loserId, int|string $winnerId): array
    {
        $loser = $this->mustFind($loserId);
        $winner = $this->mustFind($winnerId);

        $merged = app(ContactMergeService::class)->merge($loser, $winner);

        return $this->present($merged);
    }

    // -- Email templates ----------------------------------------------------

    /**
     * Resolve an email template by slug. Email templates are owned by the
     * standalone goldnead/statamic-email-templates addon (the shared
     * `et_templates` collection); this method is a thin seam that delegates to
     * that addon's public resolver facade. A managed entry always wins; the
     * caller-supplied $fallback (the old file-based template) is only used when
     * no entry exists.
     *
     * Consumed by sibling addons (automations, marketing) via the class_exists
     * coupling on {@see LeadHub}. Returns the stable
     * array shape [slug, title, subject, body, plain_text, description, source]
     * or null when neither an entry nor a fallback yields a template — and also
     * null (never fatal) when the email-templates addon isn't installed.
     *
     * @param  (callable(string):(array<string,mixed>|object|null))|null  $fallback
     * @return array<string,mixed>|null
     */
    public function resolveEmailTemplate(string $slug, ?callable $fallback = null): ?array
    {
        $facade = EmailTemplates::class;

        // Guard gracefully: the email-templates addon is an optional, soft
        // dependency. Without it there are no managed templates to resolve.
        if (! class_exists($facade)) {
            return null;
        }

        return $facade::resolve($slug, $fallback)?->toArray();
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
