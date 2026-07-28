<?php

namespace Goldnead\Leadhub\Listeners;

use Goldnead\Leadhub\Events\LeadHubContactScoreChanged;
use Goldnead\Leadhub\Services\TimelineService;

/**
 * Writes a timeline entry whenever a contact's engagement score really changes.
 *
 * Until v1.8.0 `LeadHubContactScoreChanged` fired into an empty room: nothing
 * listened for the purpose of recording it, so a contact's score history did
 * not exist anywhere. The event is only ever dispatched on an actual change
 * (see ScoringService::applyScore), so there is no no-op entry to filter out
 * here.
 *
 * On the noise question raised in GAPS: the score moves on nearly every
 * activity, and the contact timeline is already the busiest thing on that page.
 * Aggregation (one entry per day, or only on threshold crossings) was the
 * alternative and was rejected — a summarized history cannot answer "what
 * exactly awarded these 3 points", which is the only question anybody opens a
 * score history for. Installs that disagree set `leadhub.scoring.timeline` to
 * false and keep the event without the entries.
 */
class RecordScoreChangeOnTimeline
{
    public function __construct(protected TimelineService $timeline)
    {
    }

    public function handle(LeadHubContactScoreChanged $event): void
    {
        if (! config('leadhub.scoring.timeline', true)) {
            return;
        }

        $this->timeline->recordScoreChanged(
            $event->contact,
            $event->oldScore,
            $event->newScore,
            $event->delta,
            $event->reason,
        );
    }
}
