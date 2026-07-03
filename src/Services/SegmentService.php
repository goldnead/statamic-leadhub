<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Events\LeadHubContactEnteredSegment;
use Goldnead\Leadhub\Events\LeadHubContactLeftSegment;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Segment;
use Goldnead\Leadhub\Support\SegmentEvaluator;

/**
 * Orchestrates segment membership: evaluation, materialization, reactive
 * re-evaluation, and the enter/leave event surface.
 *
 * ## Loop protection
 *
 * Consumers can react to `LeadHubContactEnteredSegment` /
 * `LeadHubContactLeftSegment` by mutating the contact (e.g. adding a tag or
 * changing status). Those mutations fire the very events this service listens
 * on to re-evaluate membership — which could cascade forever.
 *
 * A static per-request depth guard, keyed by contact id, caps re-evaluation at
 * {@see self::MAX_DEPTH} nested passes per contact per request. The FIRST pass
 * runs normally; a re-entrant pass triggered *while that contact is already
 * being evaluated* is dropped. Membership therefore converges to the state
 * implied by the first pass's resulting mutations; deeper cascades are ignored
 * (and logged at debug level by the caller if desired). One level is enough:
 * a reactive consumer's mutation is reflected on the next legitimate mutation
 * event, not within the same synchronous cascade.
 */
class SegmentService
{
    /** Max nested re-evaluation passes per contact per request. */
    public const MAX_DEPTH = 1;

    /** @var array<string,int> contact id => current evaluation depth */
    protected static array $depth = [];

    public function __construct(
        protected SegmentRepository $segments,
        protected ContactRepository $contacts,
        protected SegmentEvaluator $evaluator,
    ) {
    }

    /** Reset the loop guard (test helper / long-running workers). */
    public static function resetGuard(): void
    {
        static::$depth = [];
    }

    /**
     * Re-evaluate a single contact against ALL active segments and reconcile
     * its materialized membership. Fires enter/leave events on diffs.
     *
     * This is the cheap, reactive path: it is called from the listener on any
     * contact-mutating event. Returns the list of changes made.
     *
     * @return array<int,array{segment:string,change:'entered'|'left'}>
     */
    public function reevaluateContact(Contact $contact): array
    {
        // Key on uuid: under the flat driver $contact->id int-casts to 0 for
        // every contact (UUID primary key), which would collide the guard
        // across all contacts. uuid is stable and unique in both drivers.
        $key = (string) ($contact->uuid ?: $contact->getAttribute('id'));

        // Loop guard: drop a re-entrant pass for a contact already in flight.
        if (($this->depthFor($key)) >= self::MAX_DEPTH) {
            return [];
        }

        static::$depth[$key] = $this->depthFor($key) + 1;

        $changes = [];

        try {
            foreach ($this->segments->all() as $segment) {
                if (! $segment->is_active) {
                    // Inactive segments are not evaluated; leave existing
                    // membership untouched so re-activating restores it lazily.
                    continue;
                }

                $shouldBe = $this->evaluator->matches($contact, (array) $segment->rules);
                $isMember = $this->segments->hasContact($segment, $contact);

                if ($shouldBe && ! $isMember) {
                    $this->segments->addContact($segment, $contact);
                    $this->fireEntered($contact, $segment);
                    $changes[] = ['segment' => $segment->handle, 'change' => 'entered'];
                } elseif (! $shouldBe && $isMember) {
                    $this->segments->removeContact($segment, $contact);
                    $this->fireLeft($contact, $segment);
                    $changes[] = ['segment' => $segment->handle, 'change' => 'left'];
                }
            }
        } finally {
            static::$depth[$key] = max(0, $this->depthFor($key) - 1);
            if (static::$depth[$key] === 0) {
                unset(static::$depth[$key]);
            }
        }

        return $changes;
    }

    /**
     * Resolve the full member set of a segment from its rules (NOT from the
     * materialized pivot) and reconcile the materialized membership to match.
     * Used by the scheduled sweep for time-based rules. Fires enter/leave
     * events on diffs. Returns [entered => n, left => n].
     *
     * @return array{entered:int,left:int}
     */
    public function sweepSegment(Segment $segment): array
    {
        if (! $segment->is_active) {
            return ['entered' => 0, 'left' => 0];
        }

        $entered = 0;
        $left = 0;

        $shouldBe = [];

        foreach ($this->eachContact() as $contact) {
            $matches = $this->evaluator->matches($contact, (array) $segment->rules);
            $isMember = $this->segments->hasContact($segment, $contact);

            if ($matches) {
                $shouldBe[(string) $contact->id] = true;
                if (! $isMember) {
                    $this->segments->addContact($segment, $contact);
                    $this->fireEntered($contact, $segment);
                    $entered++;
                }
            } elseif ($isMember) {
                $this->segments->removeContact($segment, $contact);
                $this->fireLeft($contact, $segment);
                $left++;
            }
        }

        return ['entered' => $entered, 'left' => $left];
    }

    /** Sweep every active segment. Returns per-handle diff counts. */
    public function sweepAll(): array
    {
        $result = [];

        foreach ($this->segments->all() as $segment) {
            if ($segment->is_active) {
                $result[$segment->handle] = $this->sweepSegment($segment);
            }
        }

        return $result;
    }

    /**
     * Member contact UUIDs for a segment handle, resolved LIVE from rules.
     * Consumers (e.g. marketing campaigns) that need the authoritative set
     * without relying on the materialized pivot use this.
     */
    public function resolveMemberIds(string $handle): array
    {
        $segment = $this->segments->findByHandle($handle);

        if (! $segment || ! $segment->is_active) {
            return [];
        }

        $ids = [];

        foreach ($this->eachContact() as $contact) {
            if ($this->evaluator->matches($contact, (array) $segment->rules)) {
                $ids[] = (string) $contact->uuid;
            }
        }

        return $ids;
    }

    /** Cheap reactive check: is this contact (live) in the segment by handle? */
    public function contactInSegment(Contact|int|string $contact, string $handle): bool
    {
        $segment = $this->segments->findByHandle($handle);

        if (! $segment || ! $segment->is_active) {
            return false;
        }

        $model = $contact instanceof Contact ? $contact : $this->contacts->find($contact);

        if (! $model instanceof Contact) {
            return false;
        }

        return $this->evaluator->matches($model, (array) $segment->rules);
    }

    /* -------- internals -------- */

    protected function depthFor(string $key): int
    {
        return static::$depth[$key] ?? 0;
    }

    /**
     * Iterate every active contact across whichever driver is bound. Uses the
     * repository paginator so it works identically for eloquent and flat.
     *
     * @return \Generator<Contact>
     */
    protected function eachContact(): \Generator
    {
        $page = 1;
        $perPage = 200;

        do {
            $paginator = $this->contacts->paginate([], perPage: $perPage, page: $page);

            foreach ($paginator->items() as $contact) {
                yield $contact;
            }

            $page++;
        } while ($paginator->hasMorePages());
    }

    protected function fireEntered(Contact $contact, Segment $segment): void
    {
        event(new LeadHubContactEnteredSegment($contact, null, [
            'segment_handle' => $segment->handle,
            'segment_id' => $segment->id,
            'segment_name' => $segment->name,
        ]));
    }

    protected function fireLeft(Contact $contact, Segment $segment): void
    {
        event(new LeadHubContactLeftSegment($contact, null, [
            'segment_handle' => $segment->handle,
            'segment_id' => $segment->id,
            'segment_name' => $segment->name,
        ]));
    }
}
