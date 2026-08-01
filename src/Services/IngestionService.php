<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\SourceProjector;
use Goldnead\Leadhub\Events\LeadHubSourceIngested;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Support\SourceEvent;

/**
 * Turns generic SourceEvents (purchases, bookings, logins, inbound webhooks,
 * …) into contacts + timeline entries, deduplicated by email/phone and
 * idempotent by dedupe_key. This is the multi-source counterpart to the
 * form-submission pipeline.
 */
class IngestionService
{
    /** @var array<string, SourceProjector> */
    protected array $projectors = [];

    public function __construct(
        protected ContactResolver $resolver,
        protected TimelineService $timeline,
        protected TagService $tags,
        protected EventRepository $events,
    ) {}

    public function registerProjector(SourceProjector $projector): void
    {
        $this->projectors[$projector->sourceType()] = $projector;
    }

    /**
     * Ingest a raw model by resolving its registered projector first.
     */
    public function projectAndIngest(mixed $model): ?Event
    {
        $type = is_object($model) ? $model::class : null;

        if ($type === null || ! isset($this->projectors[$type])) {
            return null;
        }

        $sourceEvent = $this->projectors[$type]->project($model);

        return $sourceEvent ? $this->ingest($sourceEvent) : null;
    }

    /**
     * Ingest a normalized SourceEvent. Returns the created (or pre-existing,
     * when deduped) timeline Event, or null when the event carries no email.
     */
    public function ingest(SourceEvent $sourceEvent): ?Event
    {
        if (! $sourceEvent->hasEmail()) {
            // Without an email we cannot resolve a contact deterministically.
            return null;
        }

        // Idempotency: a matching dedupe_key means this exact source event was
        // already ingested — return the existing timeline entry untouched.
        if ($sourceEvent->dedupeKey) {
            $existing = $this->events->findByDedupeKey($sourceEvent->dedupeKey);

            if ($existing instanceof Event) {
                return $existing;
            }
        }

        [$contact, $wasCreated] = $this->resolver->resolveOrCreate($sourceEvent->toContactDto());

        if (! empty($sourceEvent->tags)) {
            $this->tags->attachMany($contact, $sourceEvent->tags, silent: true);
        }

        $event = $this->timeline->recordSource(
            $contact,
            $sourceEvent->type,
            $sourceEvent->summary,
            $sourceEvent->payload,
            $sourceEvent->sourceType,
            $sourceEvent->sourceId,
            $sourceEvent->dedupeKey,
            $sourceEvent->occurredAt,
        );

        $this->resolver->touchActivity($contact);

        event(new LeadHubSourceIngested($contact, null, [
            'type' => $sourceEvent->type,
            'source_type' => $sourceEvent->sourceType,
            'source_id' => $sourceEvent->sourceId,
            'dedupe_key' => $sourceEvent->dedupeKey,
            'was_created' => $wasCreated,
            'event_id' => $event->id,
        ]));

        return $event;
    }

    /** @return Contact resolved or created from the source event (no timeline write). */
    public function resolveContact(SourceEvent $sourceEvent): Contact
    {
        [$contact] = $this->resolver->resolveOrCreate($sourceEvent->toContactDto());

        return $contact;
    }
}
