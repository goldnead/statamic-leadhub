<?php

namespace Goldnead\Leadhub\Integrations\Insights;

use Goldnead\Leadhub\Models\Event;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Query\Builder;

/**
 * How often a contact's engagement score moved.
 *
 * A measure of how alive the list is rather than of any one person: a score
 * changes when somebody submits, clicks, is ingested from a source or is
 * scored by a rule, so the count is a rough tally of interactions the scoring
 * rules considered worth reacting to. It is read next to the arrivals — a month
 * with few new contacts and many score changes is a month spent with the people
 * already on the list.
 *
 * Over the timeline rather than over the contacts, and it has to be: a contact
 * carries one score and no memory of the ones before it. The history exists
 * only as `score_changed` entries, which is why the event was given a listener
 * in v1.8.0 — until then it fired into an empty room and a contact's score had
 * a value and no past.
 *
 * **On `created_at`, not `occurred_at`.** The second column exists for events
 * projected in from elsewhere — a webhook delivering something that happened
 * yesterday — and is null for everything LeadHub writes itself, which is all of
 * these. A `COALESCE` would be the careful-looking choice and would buy nothing
 * but a column the index cannot be used for. Should a source ever back-date a
 * score change, this reads the day the score actually moved on this system,
 * which is the honest date for a figure about scoring.
 */
class ScoreChanges extends LeadhubMetric
{
    protected function table(): string
    {
        return 'leadhub_events';
    }

    protected function timestamp(): string
    {
        return 'created_at';
    }

    protected function feature(): ?string
    {
        return 'scoring';
    }

    public function handle(): string
    {
        return 'leadhub.score_changes';
    }

    public function label(): string
    {
        return __('leadhub::insights.score_changes');
    }

    public function description(): ?string
    {
        return __('leadhub::insights.score_changes_description');
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

        return (int) $this->scoredInPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->scoredInPeriod($query), $query, 'count(*)'),
        );
    }

    protected function scoredInPeriod(MetricQuery $query): Builder
    {
        return $this->untilNow($query)->where('type', Event::TYPE_SCORE_CHANGED);
    }
}
