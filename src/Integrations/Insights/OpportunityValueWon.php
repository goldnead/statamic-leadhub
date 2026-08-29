<?php

namespace Goldnead\Leadhub\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * What the deals won in the period were estimated to be worth.
 *
 * An estimate, and it says so: `value_estimate` is what a person typed when
 * they opened the opportunity. It is not revenue and must not be read as such
 * — LeadHub keeps what somebody actually paid in a separate ledger
 * (`leadhub_contact_revenue`), written by whoever took the money. Both can end
 * up on the same screen, and where they disagree badly it is the estimate that
 * needs looking at.
 *
 * **The column is decimal, in whole currency units, and this figure is in
 * cents.** `value_estimate` is `decimal(12,2)`: `1500.00` means one thousand
 * five hundred euros, where a payment's `amount_cent` of `1500` means fifteen.
 * The `CURRENCY` unit is minor units and always an integer, so a sum in euros
 * handed over unchanged would print 15,00 € for a deal worth 1.500 € — wrong by
 * a factor of a hundred and entirely plausible on the screen. The conversion
 * happens here, once, at the edge.
 *
 * Rounded after the sum rather than per row, which is exact rather than merely
 * close: the column carries two decimal places and nothing finer, so their
 * total has at most two, and `round()` only clears the binary float noise the
 * driver hands back for a decimal.
 *
 * **Which currency.** LeadHub records none — an opportunity has an amount and
 * no unit, because nothing in this addon has ever had to add two of them
 * together. The formatter needs one, so `leadhub.currency` provides it and
 * defaults to EUR. Naming it in config is honest about what it is: a statement
 * by the installation about the money it deals in, not a fact read off a row.
 */
class OpportunityValueWon extends WonOpportunityMetric
{
    public function handle(): string
    {
        return 'leadhub.opportunity_value_won';
    }

    public function label(): string
    {
        return __('leadhub::insights.opportunity_value_won');
    }

    public function description(): ?string
    {
        return __('leadhub::insights.opportunity_value_won_description');
    }

    public function unit(): string
    {
        return Unit::CURRENCY;
    }

    public function meta(MetricQuery $query): array
    {
        return ['currency' => $this->currency()];
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return $this->toCent((float) $this->wonInPeriod($query)->sum('value_estimate'));
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => $this->toCent((float) $measured),
            $this->bucketed($this->wonInPeriod($query), $query, 'sum(value_estimate)'),
        );
    }

    /** Whole currency units off the column, in the minor units the unit promises. */
    protected function toCent(float $amount): int
    {
        return (int) round($amount * 100);
    }

    protected function currency(): string
    {
        $currency = config('leadhub.currency', 'EUR');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'EUR';
    }
}
