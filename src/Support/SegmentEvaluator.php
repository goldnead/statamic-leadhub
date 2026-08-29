<?php

namespace Goldnead\Leadhub\Support;

use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Models\Contact;
use Illuminate\Support\Carbon;

/**
 * Driver-agnostic rule engine for contact segments.
 *
 * A rule set is a boolean tree:
 *
 *   {
 *     "match": "all" | "any",
 *     "conditions": [
 *       { "type": "field", "field": "status", "operator": "eq", "value": "qualified" },
 *       { "type": "tag",   "operator": "has", "value": "vip" },
 *       { "type": "event", "operator": "has", "event": "purchase", "within_days": 30 },
 *       { "match": "any", "conditions": [ ... ] }   // nested group
 *     ]
 *   }
 *
 * An empty rule set (no conditions) matches NOBODY — a segment must express at
 * least one condition to have members. This is deliberate: an "everyone"
 * segment is better expressed as no segment at all (see marketing send path).
 *
 * IMPORTANT (flat-file safety): the evaluator resolves tags and event history
 * through the repositories, NOT through Eloquent relations. Flat-file-hydrated
 * contacts have no `->tags` / `->events` relations, so touching them there
 * would throw. See CHANGELOG note in leadhub v1.1.0.
 */
class SegmentEvaluator
{
    /** Contact columns exposed to `field` conditions. */
    public const FIELDS = [
        'status', 'source', 'source_form', 'assigned_to', 'engagement_score',
        'do_not_contact', 'created_at', 'last_activity_at',
        'full_name', 'first_name', 'last_name', 'email', 'company',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        // Cached from the revenue ledger. Here rather than as `custom` fields
        // because a segment over "paid more than X" has to compare a number the
        // database can index, and because a host must not have to define a
        // field by hand before a contributed total becomes segmentable.
        'revenue_cent', 'revenue_refunded_cent', 'purchase_count',
        'first_purchase_at', 'last_purchase_at',
    ];

    public function __construct(
        protected TagRepository $tags,
        protected EventRepository $events,
    ) {}

    /**
     * Does a contact satisfy a rule set?
     *
     * @param  array<string,mixed>  $rules
     */
    public function matches(Contact $contact, array $rules): bool
    {
        $conditions = $rules['conditions'] ?? [];

        // No conditions → matches nobody (see class docblock).
        if (empty($conditions)) {
            return false;
        }

        return $this->evaluateGroup($contact, $rules);
    }

    /**
     * @param  array<string,mixed>  $group
     */
    protected function evaluateGroup(Contact $contact, array $group): bool
    {
        $match = ($group['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $conditions = $group['conditions'] ?? [];

        if (empty($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            $result = $this->evaluateCondition($contact, $condition);

            if ($match === 'any' && $result) {
                return true;
            }

            if ($match === 'all' && ! $result) {
                return false;
            }
        }

        return $match === 'all';
    }

    /**
     * @param  array<string,mixed>  $condition
     */
    protected function evaluateCondition(Contact $contact, array $condition): bool
    {
        // Nested group.
        if (isset($condition['conditions'])) {
            return $this->evaluateGroup($contact, $condition);
        }

        return match ($condition['type'] ?? 'field') {
            'tag' => $this->evaluateTag($contact, $condition),
            'event' => $this->evaluateEvent($contact, $condition),
            'custom' => $this->evaluateCustom($contact, $condition),
            default => $this->evaluateField($contact, $condition),
        };
    }

    protected function evaluateField(Contact $contact, array $condition): bool
    {
        $field = (string) ($condition['field'] ?? '');

        if (! in_array($field, self::FIELDS, true)) {
            return false;
        }

        $actual = $contact->getAttribute($field);
        $operator = (string) ($condition['operator'] ?? 'eq');
        $value = $condition['value'] ?? null;

        return $this->compare($actual, $operator, $value);
    }

    /**
     * A field the site defined for itself.
     *
     * Separate from `field` on purpose: FIELDS is a fixed allow-list of contact
     * columns, and a custom field is neither a column nor fixed. Folding them
     * together would mean either opening that list to arbitrary attribute
     * access — a rule could then read any column on the model — or maintaining
     * the allow-list from the database on every evaluation.
     *
     * A handle nobody defined matches nobody, exactly as an unknown column
     * does. That is the safer direction: a segment whose field was deleted
     * empties out rather than quietly matching everybody.
     */
    protected function evaluateCustom(Contact $contact, array $condition): bool
    {
        $handle = (string) ($condition['field'] ?? '');

        if ($handle === '') {
            return false;
        }

        // Read off the attribute rather than a relation: a flat-file contact
        // has no relations to hydrate, and this evaluator has been bitten by
        // that before (see the class docblock).
        $werte = $contact->getAttribute('custom_fields');
        $werte = is_array($werte) ? $werte : (is_string($werte) ? (json_decode($werte, true) ?: []) : []);

        return $this->compare(
            $werte[$handle] ?? null,
            (string) ($condition['operator'] ?? 'eq'),
            $condition['value'] ?? null,
        );
    }

    protected function evaluateTag(Contact $contact, array $condition): bool
    {
        $needle = $this->normalize($condition['value'] ?? '');
        $operator = (string) ($condition['operator'] ?? 'has');

        $has = $this->tags->forContact($contact)->contains(function ($tag) use ($needle) {
            $id = is_array($tag) ? ($tag['id'] ?? null) : $tag->id;
            $slug = is_array($tag) ? ($tag['slug'] ?? null) : $tag->slug;
            $name = is_array($tag) ? ($tag['name'] ?? null) : $tag->name;

            return $this->normalize($id) === $needle
                || $this->normalize($slug) === $needle
                || $this->normalize($name) === $needle;
        });

        return $operator === 'has_not' ? ! $has : $has;
    }

    protected function evaluateEvent(Contact $contact, array $condition): bool
    {
        $type = (string) ($condition['event'] ?? '');
        $operator = (string) ($condition['operator'] ?? 'has');
        $withinDays = isset($condition['within_days']) ? (int) $condition['within_days'] : null;

        if ($type === '') {
            return false;
        }

        $count = $withinDays === null
            ? $this->events->countOfType($contact, $type)
            : $this->countRecentEvents($contact, $type, $withinDays);

        $has = $count > 0;

        return $operator === 'has_not' ? ! $has : $has;
    }

    /**
     * Count events of a type within the last N days. Paginated through the
     * repository so it works across both drivers without relation access.
     */
    protected function countRecentEvents(Contact $contact, string $type, int $withinDays): int
    {
        $cutoff = now()->subDays($withinDays);
        $count = 0;
        $page = 1;

        do {
            $paginator = $this->events->forContact($contact, perPage: 100, page: $page);

            foreach ($paginator->items() as $event) {
                if (($event->type ?? null) !== $type) {
                    continue;
                }

                $occurred = $event->occurred_at ?? $event->created_at ?? null;

                if ($occurred === null) {
                    continue;
                }

                $occurred = $occurred instanceof \DateTimeInterface
                    ? Carbon::instance($occurred)
                    : Carbon::parse((string) $occurred);

                if ($occurred->greaterThanOrEqualTo($cutoff)) {
                    $count++;
                }
            }

            $page++;
        } while ($paginator->hasMorePages());

        return $count;
    }

    protected function compare(mixed $actual, string $operator, mixed $value): bool
    {
        return match ($operator) {
            'eq' => $this->normalize($actual) === $this->normalize($value),
            'neq' => $this->normalize($actual) !== $this->normalize($value),
            'in' => in_array($this->normalize($actual), array_map([$this, 'normalize'], (array) $value), true),
            'not_in' => ! in_array($this->normalize($actual), array_map([$this, 'normalize'], (array) $value), true),
            'contains' => $actual !== null && str_contains($this->normalize($actual), $this->normalize($value)),
            'starts_with' => $actual !== null && str_starts_with($this->normalize($actual), $this->normalize($value)),
            'gt' => $actual !== null && (float) $actual > (float) $value,
            'gte' => $actual !== null && (float) $actual >= (float) $value,
            'lt' => $actual !== null && (float) $actual < (float) $value,
            'lte' => $actual !== null && (float) $actual <= (float) $value,
            'is_set' => ! $this->isEmpty($actual),
            'is_empty' => $this->isEmpty($actual),
            'is_true' => ! $this->isEmpty($actual) && (bool) $actual === true,
            // `! isEmpty` first, and it was missing: without it a value nobody
            // ever entered answered `is_false` as readily as one somebody set
            // to No. "Said no" and "said nothing" are different answers, and a
            // segment built on the first would quietly have contained the
            // second.
            'is_false' => ! $this->isEmpty($actual) && (bool) $actual === false,
            'before' => $this->compareDate($actual, $value, 'before'),
            'after' => $this->compareDate($actual, $value, 'after'),
            'within_days' => $this->withinDays($actual, (int) $value),
            'older_than_days' => ! $this->isEmpty($actual) && ! $this->withinDays($actual, (int) $value),
            default => false,
        };
    }

    protected function compareDate(mixed $actual, mixed $value, string $direction): bool
    {
        if ($this->isEmpty($actual)) {
            return false;
        }

        try {
            $a = $actual instanceof \DateTimeInterface ? Carbon::instance($actual) : Carbon::parse((string) $actual);
            $b = Carbon::parse((string) $value);
        } catch (\Throwable) {
            return false;
        }

        return $direction === 'before' ? $a->lessThan($b) : $a->greaterThan($b);
    }

    protected function withinDays(mixed $actual, int $days): bool
    {
        if ($this->isEmpty($actual)) {
            return false;
        }

        try {
            $a = $actual instanceof \DateTimeInterface ? Carbon::instance($actual) : Carbon::parse((string) $actual);
        } catch (\Throwable) {
            return false;
        }

        return $a->greaterThanOrEqualTo(now()->subDays($days));
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    protected function normalize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return mb_strtolower(trim((string) ($value ?? '')));
    }
}
