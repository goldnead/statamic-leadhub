<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Events\LeadHubOpportunityLost;
use Goldnead\Leadhub\Events\LeadHubOpportunityStageChanged;
use Goldnead\Leadhub\Events\LeadHubOpportunityWon;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Stage;
use Goldnead\Leadhub\Models\StageTransition;
use Illuminate\Support\Facades\DB;

/**
 * Moves an opportunity between stages, recording the transition history,
 * applying terminal-stage outcomes (won/lost), and writing the contact
 * timeline. Eloquent driver only.
 */
class StageTransitionService
{
    public function __construct(protected TimelineService $timeline) {}

    public function transition(Opportunity $opportunity, Stage $toStage, ?string $note = null, ?string $actorId = null): StageTransition
    {
        if ($toStage->pipeline_id !== $opportunity->pipeline_id) {
            throw new \InvalidArgumentException('Target stage belongs to a different pipeline.');
        }

        $fromStageId = $opportunity->stage_id;

        $transition = DB::transaction(function () use ($opportunity, $toStage, $fromStageId, $note, $actorId) {
            $transition = StageTransition::query()->create([
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStage->id,
                'actor_type' => $actorId ? 'user' : 'system',
                'actor_id' => $actorId,
                'note' => $note,
                'occurred_at' => now(),
            ]);

            $opportunity->stage_id = $toStage->id;
            $opportunity->last_activity_at = now();

            // Apply terminal outcome.
            //
            // `won_at` and `lost_at` describe the deal as it stands, exactly
            // like `status`, `outcome` and `closed_at` beside them — not the
            // history, which is the rows in `leadhub_stage_transitions` and is
            // never rewritten. So each branch below sets the stamp that applies
            // and clears the one that does not.
            //
            // Until v2.4.0 only the setting half existed. A deal reopened out
            // of a terminal stage kept the `won_at` of the close it had come
            // back from, so `status = open` sat next to a win date; a deal
            // moved from Won straight to Lost carried both. Nothing displayed
            // those columns, which is why it went unnoticed for eight releases
            // — and `won_at` is precisely the column somebody sums for "what
            // did we win this quarter". See the repair migration
            // 2026_08_15_000001 for what happened to the rows already stored.
            if ($toStage->is_terminal) {
                $opportunity->status = Opportunity::STATUS_CLOSED;
                $opportunity->outcome = $toStage->terminal_outcome;
                $opportunity->closed_at = now();
                $opportunity->won_at = $toStage->isWon() ? now() : null;
                $opportunity->lost_at = $toStage->isLost() ? now() : null;
            } else {
                // Re-opening a previously closed opportunity.
                $opportunity->status = Opportunity::STATUS_OPEN;
                $opportunity->outcome = null;
                $opportunity->closed_at = null;
                $opportunity->won_at = null;
                $opportunity->lost_at = null;
            }

            $opportunity->save();

            return $transition;
        });

        $this->writeTimeline($opportunity, $transition, $fromStageId, $toStage);
        $this->fireEvents($opportunity, $toStage, $fromStageId);

        return $transition;
    }

    protected function writeTimeline(Opportunity $opportunity, StageTransition $transition, ?int $fromStageId, Stage $toStage): void
    {
        $contact = $opportunity->contact;

        if (! $contact) {
            return;
        }

        $fromName = $fromStageId ? optional(Stage::find($fromStageId))->name : null;

        $this->timeline->recordSource(
            $contact,
            Event::TYPE_OPPORTUNITY_STAGE_CHANGED,
            __('leadhub::timeline.opportunity_stage_changed', ['from' => $fromName ?? '—', 'to' => $toStage->name]),
            [
                'opportunity_id' => $opportunity->id,
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStage->id,
                'transition_id' => $transition->id,
            ],
        );
    }

    protected function fireEvents(Opportunity $opportunity, Stage $toStage, ?int $fromStageId): void
    {
        event(new LeadHubOpportunityStageChanged($opportunity, null, [
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $toStage->id,
        ]));

        if ($toStage->isWon()) {
            event(new LeadHubOpportunityWon($opportunity));
        } elseif ($toStage->isLost()) {
            event(new LeadHubOpportunityLost($opportunity));
        }
    }
}
