<?php

namespace Goldnead\Leadhub\Contracts;

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\ContactPanels;
use Goldnead\Leadhub\Support\Timeline\TimelineEntry;

/**
 * One feed of events about a person, read from somewhere other than
 * `leadhub_events`.
 *
 * The contact screen answers "what is going on with this person". The honest
 * answer lives in four tables across four addons: payments knows what they
 * bought, entitlements knows what they may open, booking knows when they were
 * here, consent knows what they agreed to. Each source reads its own table and
 * hands back plain entries; LeadHub merges them by time. The source never
 * writes, and it never runs when its addon is not installed —
 * {@see self::available()} is asked first, every time.
 *
 * Sources live inside LeadHub on purpose, one class per sibling, guarded by
 * `class_exists`. The alternative — each sibling registering into LeadHub — is
 * how {@see ContactPanels} works, and it is right for
 * a panel a sibling owns. The timeline is LeadHub's own screen, and reading a
 * neighbour's table with a guard costs a class here rather than a release in
 * four repositories. A host or a sibling may still contribute a source of its
 * own through `LeadHub::registerTimelineSource()`.
 */
interface TimelineSource
{
    /** Stable key, e.g. `payments`. Also the config switch under `leadhub.timeline.sources`. */
    public function key(): string;

    /** Whether the neighbour this reads from is installed and migrated. */
    public function available(): bool;

    /**
     * Every entry about this contact.
     *
     * `$emails` are the lower-cased, trimmed addresses to match on — the
     * normalized one LeadHub stores plus the raw one, deduplicated. Match on
     * `LOWER(TRIM(email))` in the neighbour's table; the demo data carries
     * `doppelt@` and `DOPPELT@` as two rows for exactly this reason.
     *
     * @param  list<string>  $emails
     * @return list<TimelineEntry>
     */
    public function entries(Contact $contact, array $emails): array;

    /**
     * Headline numbers this source can vouch for.
     *
     * Known keys: `purchase_count` (int), `lifetime_value` (array<string
     * currency, int cent>), `active_access` (int). Return only what you know;
     * the builder merges.
     *
     * @param  list<string>  $emails
     * @return array<string, mixed>
     */
    public function stats(Contact $contact, array $emails): array;

    /**
     * Prefixes of `leadhub_events.type` this source makes redundant.
     *
     * Payments already writes `payments.purchase_completed` into the LeadHub
     * timeline through its bridge. When the payments source is available the
     * same purchase would appear twice — once from the ledger, once from the
     * bridge event — so the bridge event is hidden. When the source is not
     * available the bridge event stays, because it is then the only record.
     *
     * @return list<string>
     */
    public function supersedes(): array;
}
