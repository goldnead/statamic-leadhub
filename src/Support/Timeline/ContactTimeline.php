<?php

namespace Goldnead\Leadhub\Support\Timeline;

use Carbon\Carbon;
use DateTimeInterface;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\TimelineSource;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Support\ContactPanels;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * The merged timeline of one person, and the numbers above it.
 *
 * LeadHub's own events come from the event repository; everything else comes
 * from the registered {@see TimelineSource}s, each asked whether its neighbour
 * is installed before it is asked anything else. The result is one list,
 * newest first, plus the headline numbers: first and last contact, purchases,
 * lifetime value per currency, active access.
 *
 * A source that throws is logged and left out, never propagated — this runs
 * while the contact screen renders, and a sibling addon mid-upgrade must not
 * be able to 500 the page somebody opened to read a phone number. The same
 * rule {@see ContactPanels} applies to panels.
 */
class ContactTimeline
{
    /** @var array<string, TimelineSource> */
    protected array $sources = [];

    public function __construct(protected EventRepository $events) {}

    /** Register a source under its key. The same key twice replaces the first. */
    public function register(TimelineSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /** @return array<string, TimelineSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Which sources take part, by key. False for a neighbour that is not
     * installed or switched off in `leadhub.timeline.sources`.
     *
     * @return array<string, bool>
     */
    public function availability(): array
    {
        $out = [];

        foreach ($this->sources as $key => $source) {
            $out[$key] = $this->enabled($source) && $this->safely($key, fn () => $source->available(), false);
        }

        return $out;
    }

    /**
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     sources: array<string, bool>,
     *     stats: array<string, mixed>,
     *     total: int,
     * }
     */
    public function build(Contact $contact, ?int $limit = null): array
    {
        $limit ??= (int) config('leadhub.timeline.limit', 200);
        $emails = $this->emailsFor($contact);
        $available = $this->availability();

        /** @var list<TimelineEntry> $entries */
        $entries = [];
        $stats = [];
        $superseded = [];

        foreach ($this->sources as $key => $source) {
            if (! $available[$key]) {
                continue;
            }

            $entries = array_merge($entries, $this->safely($key, fn () => array_values(array_filter(
                $source->entries($contact, $emails),
                fn ($entry) => $entry instanceof TimelineEntry,
            )), []));

            $stats = array_replace($stats, $this->safely($key, fn () => $source->stats($contact, $emails), []));
            $superseded = array_merge($superseded, $this->safely($key, fn () => $source->supersedes(), []));
        }

        $entries = array_merge($entries, $this->ownEvents($contact, $limit, $superseded));

        usort($entries, function (TimelineEntry $a, TimelineEntry $b) {
            $ta = $a->at?->getTimestamp() ?? 0;
            $tb = $b->at?->getTimestamp() ?? 0;

            return $tb <=> $ta ?: strcmp($b->id, $a->id);
        });

        $total = count($entries);
        $entries = array_slice($entries, 0, $limit);

        return [
            'entries' => array_map(fn (TimelineEntry $e) => $e->toArray(), $entries),
            'sources' => $available,
            'stats' => $this->stats($contact, $entries, $stats),
            'total' => $total,
        ];
    }

    /**
     * The addresses to match a neighbour's rows on.
     *
     * @return list<string>
     */
    public function emailsFor(Contact $contact): array
    {
        $email = $contact->getAttribute('email');
        $candidates = [
            $contact->getAttribute('email_normalized'),
            EmailNormalizer::normalize(is_string($email) ? $email : null),
            is_string($email) ? mb_strtolower(trim($email)) : null,
        ];

        $emails = [];
        foreach ($candidates as $candidate) {
            $candidate = is_string($candidate) ? mb_strtolower(trim($candidate)) : '';
            if ($candidate !== '' && ! in_array($candidate, $emails, true)) {
                $emails[] = $candidate;
            }
        }

        return $emails;
    }

    /**
     * LeadHub's own events as entries, minus the ones an available source
     * makes redundant.
     *
     * @param  list<string>  $superseded
     * @return list<TimelineEntry>
     */
    protected function ownEvents(Contact $contact, int $limit, array $superseded): array
    {
        $page = $this->events->forContact($contact, max($limit, 1), 1);
        $out = [];

        foreach ($page->items() as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            $type = (string) $event->getAttribute('type');
            foreach ($superseded as $prefix) {
                if ($prefix !== '' && str_starts_with($type, $prefix)) {
                    continue 2;
                }
            }

            $payload = $event->getAttribute('payload');
            $payload = is_array($payload) ? $payload : [];
            $detail = [];
            foreach ((array) ($payload['detail'] ?? []) as $line) {
                if (is_array($line) && isset($line['label']) && array_key_exists('value', $line) && $line['value'] !== null) {
                    $detail[] = ['label' => (string) $line['label'], 'value' => (string) $line['value']];
                }
            }

            $at = $event->getAttribute('occurred_at') ?? $event->getAttribute('created_at');

            $out[] = new TimelineEntry(
                id: 'leadhub:'.$event->getAttribute('uuid'),
                source: 'leadhub',
                kind: 'leadhub.'.$type,
                at: $at instanceof DateTimeInterface ? $at : null,
                summary: (string) ($event->getAttribute('summary') ?? $type),
                actor: $event->getAttribute('actor_type') === 'user'
                    ? __('leadhub::timeline.actor_user')
                    : __('leadhub::timeline.actor_system'),
                detail: $detail,
                payload: $payload,
            );
        }

        return $out;
    }

    /**
     * @param  list<TimelineEntry>  $entries
     * @param  array<string, mixed>  $fromSources
     * @return array<string, mixed>
     */
    protected function stats(Contact $contact, array $entries, array $fromSources): array
    {
        $times = array_values(array_filter(array_map(
            fn (TimelineEntry $e) => $e->at?->getTimestamp(),
            $entries,
        )));

        $createdAt = $contact->getAttribute('created_at');
        $lastActivityAt = $contact->getAttribute('last_activity_at');
        $first = $createdAt instanceof DateTimeInterface ? $createdAt->getTimestamp() : null;
        $last = $lastActivityAt instanceof DateTimeInterface ? $lastActivityAt->getTimestamp() : null;

        if ($times !== []) {
            $first = $first === null ? min($times) : min($first, min($times));
            $last = $last === null ? max($times) : max($last, max($times));
        }

        // Without the payments source, LeadHub's own revenue ledger is the
        // best answer it has — it is what `recordRevenue()` maintains.
        $purchaseCount = array_key_exists('purchase_count', $fromSources)
            ? (int) $fromSources['purchase_count']
            : (int) ($contact->purchase_count ?? 0);

        $lifetime = array_key_exists('lifetime_value', $fromSources)
            ? (array) $fromSources['lifetime_value']
            : (((int) ($contact->revenue_cent ?? 0)) > 0
                ? [strtoupper((string) ($contact->revenue_currency ?: 'EUR')) => (int) $contact->revenue_cent]
                : []);

        ksort($lifetime);

        return [
            'first_contact_at' => $first !== null ? Carbon::createFromTimestamp($first)->toIso8601String() : null,
            'first_contact_human' => $first !== null ? Carbon::createFromTimestamp($first)->diffForHumans() : null,
            'last_contact_at' => $last !== null ? Carbon::createFromTimestamp($last)->toIso8601String() : null,
            'last_contact_human' => $last !== null ? Carbon::createFromTimestamp($last)->diffForHumans() : null,
            'purchase_count' => $purchaseCount,
            'lifetime_value' => array_map(
                fn (string $currency, $cent) => [
                    'currency' => $currency,
                    'cent' => (int) $cent,
                    'formatted' => Amount::format((int) $cent, $currency),
                ],
                array_keys($lifetime),
                array_values($lifetime),
            ),
            'active_access' => array_key_exists('active_access', $fromSources)
                ? (int) $fromSources['active_access']
                : null,
        ];
    }

    protected function enabled(TimelineSource $source): bool
    {
        return (bool) config('leadhub.timeline.sources.'.$source->key(), true);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    protected function safely(string $key, \Closure $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            Log::warning("leadhub: the timeline source [{$key}] failed and was left out.", ['exception' => $e]);

            return $fallback;
        }
    }
}
