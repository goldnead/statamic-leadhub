<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Statamic\Facades\User;

class TimelineService
{
    public function __construct(protected EventRepository $events)
    {
    }

    public function record(
        Contact $contact,
        string $type,
        ?string $summary = null,
        array $payload = [],
        ?string $actorType = null,
        ?string $actorId = null
    ): Event {
        $resolved = $this->resolveActor($actorType, $actorId);

        return $this->events->record(
            $contact,
            $type,
            $summary,
            $this->redactPayload($payload),
            $resolved['type'],
            $resolved['id'],
        );
    }

    /**
     * Record a timeline event coming from an external source (purchase,
     * booking, login, inbound webhook, …). Honours payload redaction and
     * carries the source/dedupe/occurred_at metadata.
     */
    public function recordSource(
        Contact $contact,
        string $type,
        ?string $summary = null,
        array $payload = [],
        ?string $sourceType = null,
        int|string|null $sourceId = null,
        ?string $dedupeKey = null,
        ?\DateTimeInterface $occurredAt = null,
        ?string $actorType = null,
        ?string $actorId = null,
    ): Event {
        $resolved = $this->resolveActor($actorType, $actorId);

        return $this->events->recordSource($contact, $type, [
            'summary' => $summary,
            'payload' => $this->redactPayload($payload),
            'actor_type' => $resolved['type'],
            'actor_id' => $resolved['id'],
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'dedupe_key' => $dedupeKey,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function recordContactCreated(Contact $contact): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_CONTACT_CREATED,
            __('leadhub::timeline.contact_created'),
        );
    }

    public function recordSubmissionReceived(Contact $contact, string $formHandle, array $payload, ?string $submissionId = null): Event
    {
        $payload = array_merge($payload, [
            'form_handle' => $formHandle,
            'submission_id' => $submissionId,
        ]);

        return $this->record(
            $contact,
            Event::TYPE_SUBMISSION_RECEIVED,
            __('leadhub::timeline.submission_received', ['form' => $formHandle]),
            $payload,
        );
    }

    public function recordStatusChanged(Contact $contact, string $from, string $to): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_STATUS_CHANGED,
            __('leadhub::timeline.status_changed', ['from' => $from, 'to' => $to]),
            ['from' => $from, 'to' => $to],
        );
    }

    public function recordAssigned(Contact $contact, ?string $ownerLabel): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_CONTACT_ASSIGNED,
            $ownerLabel
                ? __('leadhub::timeline.assigned', ['owner' => $ownerLabel])
                : __('leadhub::timeline.unassigned'),
            ['owner' => $ownerLabel],
        );
    }

    public function recordNoteAdded(Contact $contact, string $body): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_NOTE_ADDED,
            __('leadhub::timeline.note_added'),
            ['preview' => mb_substr($body, 0, 200)],
        );
    }

    public function recordTagAdded(Contact $contact, string $tagName): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_TAG_ADDED,
            __('leadhub::timeline.tag_added', ['tag' => $tagName]),
            ['tag' => $tagName],
        );
    }

    public function recordTagRemoved(Contact $contact, string $tagName): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_TAG_REMOVED,
            __('leadhub::timeline.tag_removed', ['tag' => $tagName]),
            ['tag' => $tagName],
        );
    }

    public function recordFollowupSet(Contact $contact, \DateTimeInterface $dueAt): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_FOLLOWUP_SET,
            __('leadhub::timeline.followup_set', ['date' => $dueAt->format('Y-m-d')]),
            ['due_at' => $dueAt->format(DATE_ATOM)],
        );
    }

    public function recordFollowupCompleted(Contact $contact): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_FOLLOWUP_COMPLETED,
            __('leadhub::timeline.followup_completed'),
        );
    }

    public function recordFollowupRemoved(Contact $contact): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_FOLLOWUP_REMOVED,
            __('leadhub::timeline.followup_removed'),
        );
    }

    /**
     * A score change on the timeline.
     *
     * The summary is composed here and stored, not rendered later — the same
     * decision v1.6.0 made for every other entry type. It means the line keeps
     * saying what it said when it happened, even after the rule that produced
     * it was edited or deleted; a timeline that silently rewrites its own past
     * is worse than one in the wrong language.
     *
     * The full numbers stay in the payload so a later screen can do arithmetic
     * on them without re-parsing the sentence.
     */
    public function recordScoreChanged(
        Contact $contact,
        int $from,
        int $to,
        int $delta,
        ?string $reason = null,
    ): Event {
        return $this->record(
            $contact,
            Event::TYPE_SCORE_CHANGED,
            __('leadhub::timeline.score_changed', [
                'from' => $from,
                'to' => $to,
                'delta' => ($delta > 0 ? '+' : '').$delta,
            ]),
            ['from' => $from, 'to' => $to, 'delta' => $delta, 'reason' => $reason],
        );
    }

    public function recordContactArchived(Contact $contact): Event
    {
        return $this->record(
            $contact,
            Event::TYPE_CONTACT_ARCHIVED,
            __('leadhub::timeline.contact_archived'),
        );
    }

    protected function redactPayload(array $payload): array
    {
        $patterns = (array) config('leadhub.timeline_payload_redaction', []);

        if (empty($patterns)) {
            return $payload;
        }

        return $this->redactRecursive($payload, $patterns);
    }

    protected function redactRecursive(array $data, array $patterns): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->keyMatchesPatterns($key, $patterns)) {
                $data[$key] = '***REDACTED***';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactRecursive($value, $patterns);
            }
        }

        return $data;
    }

    protected function keyMatchesPatterns(string $key, array $patterns): bool
    {
        $lowered = mb_strtolower($key);

        foreach ($patterns as $pattern) {
            if (str_contains($lowered, mb_strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    protected function resolveActor(?string $actorType, ?string $actorId): array
    {
        if ($actorType !== null) {
            return ['type' => $actorType, 'id' => $actorId];
        }

        try {
            $user = User::current();

            if ($user) {
                return ['type' => 'user', 'id' => (string) $user->id()];
            }
        } catch (\Throwable) {
            // No CP user context — fall through to system actor.
        }

        return ['type' => 'system', 'id' => null];
    }
}
