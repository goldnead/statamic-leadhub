<?php

namespace Goldnead\Leadhub\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many contacts arrived.
 *
 * `created_at` and not `last_activity_at`: the question is when somebody
 * entered the CRM, which happens once and never moves. `last_activity_at` is
 * rewritten every time they do anything, so a chart built on it would redraw
 * its own past every day — a lead who filled in a form in March and clicked a
 * mail today would leave March and appear in August, and March would quietly
 * empty out over time.
 *
 * The splits are the four columns that say where a person came from. `source`
 * is what LeadHub itself recorded (the form, the import, the API); the three
 * `utm_*` columns are what the visit carried and are frozen on the contact at
 * capture. A contact with none of them is a row in every one of those splits,
 * never a dropped one — an untagged sign-up is the most ordinary thing on the
 * list and the split has to add up to the total.
 *
 * **A contact later merged into another still counts as an arrival**, which is
 * the one place this figure and {@see ContactsActive} deliberately disagree.
 * The stock is about people and drops a merged duplicate from every bucket,
 * because a merge asserts the two rows were one person all along. An arrival is
 * about a channel: somebody did fill in that form, on that day, from that
 * campaign, and dropping the row would take the credit for it away from the
 * source that earned it. So five arrivals can raise the stock by four, for the
 * same reason five arrivals raise it by four when one of them is archived on
 * the way.
 */
class ContactsCreated extends LeadhubMetric implements HasBreakdowns
{
    protected function table(): string
    {
        return 'leadhub_contacts';
    }

    protected function timestamp(): string
    {
        return 'created_at';
    }

    public function handle(): string
    {
        return 'leadhub.contacts_created';
    }

    public function label(): string
    {
        return __('leadhub::insights.contacts_created');
    }

    public function description(): ?string
    {
        return __('leadhub::insights.contacts_created_description');
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

        return (int) $this->untilNow($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->untilNow($query), $query, 'count(*)'),
        );
    }

    public function breakdowns(): array
    {
        return [
            'status' => __('leadhub::insights.breakdown.status'),
            'source' => __('leadhub::insights.breakdown.source'),
            'utm_source' => __('leadhub::insights.breakdown.utm_source'),
            'utm_campaign' => __('leadhub::insights.breakdown.utm_campaign'),
        ];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || ! array_key_exists($dimension, $this->breakdowns())) {
            return [];
        }

        $rows = $this->splitByColumn($this->untilNow($query), $query, $dimension, 'count(*)', $limit);

        if ($dimension !== 'status') {
            // A source and a campaign are their own label. There is nothing to
            // resolve them against, and inventing a prettier form of a UTM
            // value would stop it matching what the analytics tool beside it
            // shows.
            return $this->labelled($rows, $dimension);
        }

        // A status has a configured name, and it is the name the whole Control
        // Panel already calls it — the dashboard's own "Leads by status" list
        // reads the same map. A raw `qualified` beside a screen that says
        // "Qualified leads" reads as two different things.
        $names = (array) config('leadhub.statuses', []);

        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] === null
                ? $this->missingLabel($dimension)
                : (string) ($names[$row['key']] ?? $row['key']),
            'value' => $row['value'],
        ], $rows);
    }
}
