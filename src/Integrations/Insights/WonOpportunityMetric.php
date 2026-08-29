<?php

namespace Goldnead\Leadhub\Integrations\Insights;

use Goldnead\Leadhub\Models\Stage;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Illuminate\Database\Query\Builder;

/**
 * What the two won-deal figures share: which rows count as a win, and when.
 *
 * On `won_at`, which is the day the deal closed and not the day it was opened.
 * A deal that ran from March to August belongs to August, because that is when
 * it became a win — the same rule the payment figures use for `paid_at`, and
 * the reason the two can be read side by side.
 *
 * **`won_at` describes the deal as it stands, not its history.** Moving an
 * opportunity back out of a terminal stage clears it, and moving it to Lost
 * clears it too (see `StageTransitionService`). So a deal won in August and
 * reopened in September leaves August's figure when it is reopened. That is a
 * property of the column and it is the right one for this question: the answer
 * to "what did we win in August" should stop counting a deal that turned out
 * not to be won. The immutable alternative — counting arrivals in a won stage
 * out of `leadhub_stage_transitions` — would count a deal twice if it was won,
 * reopened and won again, which is a worse wrong for the same money.
 *
 * `outcome = 'won'` is asserted beside the timestamp although the two are
 * written together. It costs nothing and it refuses exactly the row this addon
 * has already had to repair once: a stamp left behind by an outcome that was
 * changed afterwards (see the 2026_08_15 repair migration).
 *
 * Both figures need the pipelines feature. Its tables exist after a migration
 * whether it was ever switched on or not, and would report zero forever on an
 * install that does not use deals at all.
 */
abstract class WonOpportunityMetric extends LeadhubMetric
{
    protected function table(): string
    {
        return 'leadhub_opportunities';
    }

    protected function timestamp(): string
    {
        return 'won_at';
    }

    protected function feature(): ?string
    {
        return 'pipelines';
    }

    /** Every deal that closed as a win inside the window. */
    protected function wonInPeriod(MetricQuery $query): Builder
    {
        return $this->untilNow($query)->where('outcome', Stage::OUTCOME_WON);
    }
}
