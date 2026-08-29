<?php

namespace Goldnead\Leadhub\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How many live contacts there were at the end of the period.
 *
 * A stock, not a flow, and the only one this addon contributes. Every other
 * figure here counts things that happened inside the window; this one counts
 * what stood at the end of it. The two are read side by side and answer
 * different questions: "forty arrived in August" and "there are three hundred
 * and ten" are both true and neither implies the other.
 *
 * Live means two things, and they are recorded differently.
 *
 * **Archived is dated.** `archived_at` is a timestamp, so an archived contact
 * can be taken out of the count on the day it was archived and left in every
 * day before it. That is what makes a monthly series of this figure mean
 * anything at all.
 *
 * **Merged is not.** `merged_into_contact_id` is a pointer with no date beside
 * it, so a merge cannot be placed in time. Merged rows are therefore left out
 * of every bucket, past ones included — which is the reading that matches what
 * a merge actually asserts: that the two rows were one person all along. The
 * alternative, counting the loser until the day of the merge, would put a
 * person on the list twice for every month before it.
 *
 * Both facts are the row as it stands today rather than an immutable ledger:
 * un-archiving clears `archived_at`, and a contact restored today reappears in
 * every past bucket. This chart is a picture of the list as it is now, in the
 * same sense and for the same reason as `SubscriptionGrowth` in the newsletter
 * addon — the columns it reads are the current state, and the honest thing is
 * to say so rather than to imply a history the schema does not keep.
 *
 * **The series returns every bucket, including the unchanged ones**, and that
 * is deliberate. Insights fills a missing bucket with a zero, which is right
 * for a flow and catastrophic for a stock: a quiet week would draw the list
 * collapsing to nobody and recovering. So the running total is emitted for
 * every bucket in the window, whether anything moved in it or not.
 */
class ContactsActive extends LeadhubMetric
{
    protected function table(): string
    {
        return 'leadhub_contacts';
    }

    /**
     * The timestamp a bucket is cut on: when the contact arrived.
     *
     * Only half of what this metric reads — the leaving half is `archived_at`
     * and is asked for separately below. The base class needs one column to
     * window on, and this is the one that decides which contacts exist at all.
     */
    protected function timestamp(): string
    {
        return 'created_at';
    }

    public function handle(): string
    {
        return 'leadhub.contacts_active';
    }

    public function label(): string
    {
        return __('leadhub::insights.contacts_active');
    }

    public function description(): ?string
    {
        return __('leadhub::insights.contacts_active_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return $this->stockAt($query->period->to);
    }

    /**
     * The stock at the end of every bucket, in three queries.
     *
     * Not one count per bucket: a year of daily buckets would be three hundred
     * and sixty-five counts over the whole table. The stock before the window
     * is counted once, and the movements inside it are added on — arrivals
     * minus departures, bucket by bucket, which is the same number by a
     * cheaper road.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $buckets = $this->bucketsIn($query);

        if ($buckets === []) {
            return [];
        }

        $arrived = $this->bucketed($this->live($query, 'created_at'), $query, 'count(*)', 'created_at');
        $left = $this->bucketed($this->live($query, 'archived_at'), $query, 'count(*)', 'archived_at');

        // One instant before the first bucket opens, so a contact created in
        // that first bucket is counted by the movement and not by the baseline
        // as well.
        $stock = $this->stockAt(reset($buckets)['from']->copy()->subSecond());

        $series = [];

        foreach ($buckets as $key => $bucket) {
            $stock += (int) ($arrived[$key] ?? 0) - (int) ($left[$key] ?? 0);
            $series[$key] = $stock;
        }

        return $series;
    }

    /**
     * How many contacts stood on the list at that instant.
     *
     * Its own `where` clauses rather than the base class's window, and that is
     * not a shortcut. `inPeriod()` refuses a row whose timestamp is null,
     * because a row that cannot be placed in time is in no period — right for
     * every flow figure and exactly wrong here, where `archived_at IS NULL` is
     * precisely the condition for still being on the list.
     *
     * The end of an open-ended period is **this moment**, not the end of time.
     * A stock is asked as of an instant, and the table can hold dates ahead of
     * it; without the bound, a contact somebody imported with next month's date
     * would already be on today's list.
     */
    protected function stockAt(?Carbon $at): int
    {
        $at ??= Carbon::now();

        return (int) $this->brandScoped(DB::table($this->table()))
            ->whereNull('merged_into_contact_id')
            ->where('created_at', '<=', $at)
            ->where(fn ($still) => $still->whereNull('archived_at')->orWhere('archived_at', '>', $at))
            ->count();
    }

    /**
     * Movements of one kind inside the window: arrivals, or departures.
     *
     * The merge condition is on both halves. A row that was merged away is not
     * an arrival and not a departure — it is a person who was already there
     * under another name.
     *
     * Clamped to this moment like every other flow query here, and for the same
     * reason: a movement dated next week has not happened, and the buckets this
     * series walks stop at today anyway — a movement outside them would be
     * counted by the headline and drawn by no column.
     */
    protected function live(MetricQuery $query, string $column)
    {
        return $this->untilNow($query, $column)->whereNull('merged_into_contact_id');
    }

    /**
     * Every bucket the window covers, in order, with the instant each opens.
     *
     * Built from the period rather than from the data, because the whole point
     * of a stock series is the buckets in which nothing happened.
     *
     * An open-ended period has no first bucket to start from, so the earliest
     * contact provides one. With no contacts at all there is nothing to draw
     * and the series is empty — which is the honest shape, not a flat line at
     * zero stretching back to the epoch.
     *
     * @return array<string, array{from: Carbon, to: Carbon}>
     */
    protected function bucketsIn(MetricQuery $query): array
    {
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;

        $from = $query->period->from
            ?? $this->earliestArrival()
            ?? null;

        if ($from === null) {
            return [];
        }

        $to = $query->period->to ?? Carbon::now();
        $from = $from->copy();
        $cursor = $monthly ? $from->copy()->startOfMonth() : $from->copy()->startOfDay();

        $buckets = [];

        // A guard rather than a promise about the data: a period is at most a
        // year of days or a decade of months on any screen Insights draws, and
        // an unbounded loop over a bad period would take the request with it.
        $limit = 4000;

        while ($cursor <= $to && count($buckets) < $limit) {
            $end = $monthly ? $cursor->copy()->endOfMonth() : $cursor->copy()->endOfDay();

            $buckets[$cursor->format($monthly ? 'Y-m' : 'Y-m-d')] = [
                // The first bucket starts where the period does, not where its
                // calendar month does — otherwise the baseline is taken from
                // before the month and the first column counts arrivals the
                // period never asked about.
                'from' => $cursor < $from ? $from->copy() : $cursor->copy(),
                'to' => $end,
            ];

            $cursor = $monthly ? $cursor->copy()->addMonth()->startOfMonth() : $cursor->copy()->addDay();
        }

        return $buckets;
    }

    protected function earliestArrival(): ?Carbon
    {
        $earliest = $this->brandScoped(DB::table($this->table()))
            ->whereNull('merged_into_contact_id')
            ->min('created_at');

        return $earliest === null ? null : Carbon::parse((string) $earliest);
    }
}
