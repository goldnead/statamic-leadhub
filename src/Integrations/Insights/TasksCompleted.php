<?php

namespace Goldnead\Leadhub\Integrations\Insights;

use Goldnead\Leadhub\Models\Task;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Query\Builder;

/**
 * How much follow-up work was actually finished.
 *
 * On `completed_at`, the only column that dates the finishing. `due_at` says
 * when it was meant to happen and `created_at` when somebody wrote it down;
 * neither is the question. A task created in July and done in August belongs to
 * August.
 *
 * `status = 'done'` beside the timestamp, because the two can disagree: a task
 * that was completed and then reopened keeps whatever `completed_at` it was
 * given, and a cancelled task is not a finished one. The stamp says a person
 * once ticked it off; the status says whether it is ticked off now, and this
 * figure is about work that stands as done.
 *
 * A task with no contact still counts. `contact_id` is nullable and an internal
 * task is work like any other — the number is about what got done, not about
 * whom it was done for.
 */
class TasksCompleted extends LeadhubMetric
{
    protected function table(): string
    {
        return 'leadhub_tasks';
    }

    protected function timestamp(): string
    {
        return 'completed_at';
    }

    protected function feature(): ?string
    {
        return 'tasks';
    }

    public function handle(): string
    {
        return 'leadhub.tasks_completed';
    }

    public function label(): string
    {
        return __('leadhub::insights.tasks_completed');
    }

    public function description(): ?string
    {
        return __('leadhub::insights.tasks_completed_description');
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

        return (int) $this->doneInPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->doneInPeriod($query), $query, 'count(*)'),
        );
    }

    protected function doneInPeriod(MetricQuery $query): Builder
    {
        return $this->untilNow($query)->where('status', Task::STATUS_DONE);
    }
}
