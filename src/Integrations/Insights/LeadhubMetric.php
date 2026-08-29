<?php

namespace Goldnead\Leadhub\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Support\Facades\Schema;

/**
 * What every LeadHub figure has in common.
 *
 * This addon already reports on itself: the dashboard shows six numbers as they
 * stand right now, and the pipeline board sums the deals per stage. Neither is
 * repeated here. What these metrics add is the one thing those screens cannot
 * give — a **period**, a comparison against the period before it, and a place
 * beside the revenue on somebody else's screen. The dashboard answers "how
 * many qualified leads are there"; this answers "how many arrived in August,
 * and where did they come from".
 *
 * Everything below reads the tables through {@see TableMetric}, which is the
 * sibling's own base class for exactly this shape of question. It is loaded
 * only when the sibling is installed — the classes in this directory are never
 * touched otherwise, which is what keeps `goldnead/statamic-insights` a
 * `suggest` and not a `require` (see the guard in the ServiceProvider).
 *
 * Three decisions shape every number in this directory.
 *
 * **1. The eloquent driver, or nothing.** LeadHub can keep its contacts in YAML
 * files instead of tables, and under that driver `php artisan migrate` is not
 * even required. Where the tables were created once and the driver switched
 * afterwards they are still there and stand empty — so `Schema::hasTable()`
 * alone would answer a confident zero for an install whose contacts are all on
 * disk. {@see available()} therefore asks the driver as well as the schema.
 *
 * **2. A feature that is switched off has nothing to measure.** Tasks,
 * pipelines and scoring are opt-in and default to off. Their tables exist after
 * a migration regardless, and would report zero forever on the installs that
 * never turned them on. A metric that cannot answer is left off the screen,
 * which is the difference between "not in use here" and "nobody did this".
 *
 * **3. One brand, and the metric says which.** See {@see brandColumn()}.
 *
 * Two further decisions are shared by every figure here and are written down
 * once rather than six times.
 *
 * **Every one of them clamps to this moment.** They all answer *what happened*
 * — somebody arrived, a deal was won, a task was finished — and none of them
 * answers *what is scheduled*. The widest period has no upper bound at all, so
 * without the clamp a date somebody typed by hand or an importer carried across
 * would be reported as a fact of the past. Where the column is stamped by the
 * system and cannot hold the future, the clamp costs a condition and changes no
 * number; a clamp that cannot be forgotten is worth more than six separate
 * judgements about which columns are safe. What LeadHub knows about the future
 * — a task due on Monday, a follow-up pending — is deliberately not contributed
 * here at all: it is the dashboard's question, and hiding the future from it
 * would be the same lie pointing the other way.
 *
 * **Time is the application's, on both sides of the fence.** Every date column
 * these queries read is written through Laravel's own `datetime` cast, which
 * stores and reads in `config('app.timezone')`, and every writer reaches for
 * `now()`. Insights builds its `Period` from `Carbon::now()`, which is the same
 * clock. So no conversion happens here and none is wanted: an addon that stored
 * UTC behind a cast of its own would need one, and a five-hour offset at every
 * period boundary is what its absence would cost. `InsightsMetricsTest` runs
 * the figures under a non-UTC timezone rather than leaving the claim to a
 * comment.
 */
abstract class LeadhubMetric extends TableMetric
{
    public function group(): string
    {
        return __('leadhub::insights.group');
    }

    /**
     * Nothing to measure without the tables, the driver and the feature.
     *
     * False rather than zero, and the difference is the point: "nothing to
     * measure" and "measured nothing" are different statements, and a zero for
     * the first is the quiet kind of wrong.
     */
    public function available(): bool
    {
        if (! $this->onTheEloquentDriver()) {
            return false;
        }

        if (! $this->featureEnabled()) {
            return false;
        }

        return Schema::hasTable($this->table());
    }

    /**
     * The feature flag this figure depends on, or null when it needs none.
     *
     * Contacts and their timeline are LeadHub's baseline and are always on.
     * Tasks, pipelines and scoring are not.
     */
    protected function feature(): ?string
    {
        return null;
    }

    protected function featureEnabled(): bool
    {
        $feature = $this->feature();

        return $feature === null || (bool) config('leadhub.features.'.$feature, false);
    }

    /**
     * Are the tables the ones actually being written to?
     *
     * The flat driver keeps contacts, timeline entries, notes, follow-ups and
     * tags in YAML. It does not merely bypass these tables — it does not need
     * them to exist at all.
     */
    protected function onTheEloquentDriver(): bool
    {
        return config('leadhub.storage.driver', 'eloquent') === 'eloquent';
    }

    // -- One brand, and the metric says which --------------------------------

    /**
     * The column these tables carry their brand on.
     *
     * Declaring it is the whole of it: {@see TableMetric::inPeriod()} then
     * narrows every figure, every chart and every split at once, by exactly the
     * rules `BrandScope` applies to every model in this addon — and the same
     * rules every other addon in the family now reads by, which is the point of
     * having them in one place rather than five.
     *
     * **An unresolved brand is a nought, not an absent tile**, and that is a
     * change from what this class used to do. It answered `available() === false`
     * for a multi-brand install with no brand in hand, which took six tiles off
     * the screen — and {@see Metric::available()} means "the tables are not
     * there, a feature is off, a sibling is missing", none of which a brand
     * nobody has picked yet is. A reader can understand a nought; a tile that
     * vanished he cannot even notice. The rows are still refused (`fail closed`),
     * so nothing sums across brands, and where the install has set `fail_mode`
     * to `open` the metric reads across them exactly as the scope does.
     */
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    /** The words for a row that has no value in the dimension it is split by. */
    protected function missingLabel(string $dimension): string
    {
        return __('leadhub::insights.missing.'.$dimension);
    }
}
