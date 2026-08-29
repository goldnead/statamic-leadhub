<?php

namespace Goldnead\Leadhub\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many deals were won in the period.
 *
 * Deals, not their value: a month of six small wins and a month of one large
 * one are different months, and only one of the two figures says so. The
 * estimated value is {@see OpportunityValueWon}, and the two are meant to be
 * read together.
 *
 * Which rows count, and on what date, is {@see WonOpportunityMetric}.
 */
class OpportunitiesWon extends WonOpportunityMetric
{
    public function handle(): string
    {
        return 'leadhub.opportunities_won';
    }

    public function label(): string
    {
        return __('leadhub::insights.opportunities_won');
    }

    public function description(): ?string
    {
        return __('leadhub::insights.opportunities_won_description');
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

        return (int) $this->wonInPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->wonInPeriod($query), $query, 'count(*)'),
        );
    }
}
