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
