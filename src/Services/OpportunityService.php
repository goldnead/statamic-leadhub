<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Events\LeadHubOpportunityCreated;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;

/**
 * Creates and updates opportunities within a pipeline. Eloquent driver only.
 */
class OpportunityService
{
    public function __construct(
        protected TimelineService $timeline,
        protected StageTransitionService $transitions,
    ) {
    }

    /**
     * Create a new opportunity for a contact in the given pipeline, defaulting
     * to the pipeline's first stage unless one is specified.
     */
    public function create(Contact $contact, Pipeline $pipeline, array $attributes = []): Opportunity
    {
        $stage = null;

        if (! empty($attributes['stage_slug'])) {
            $stage = $pipeline->stages()->where('slug', $attributes['stage_slug'])->first();
        } elseif (! empty($attributes['stage_id'])) {
            $stage = $pipeline->stages()->whereKey($attributes['stage_id'])->first();
        }

        $stage ??= $pipeline->defaultStage();

        if (! $stage instanceof Stage) {
            throw new \RuntimeException("Pipeline [{$pipeline->slug}] has no stages.");
        }

        $opportunity = Opportunity::query()->create([
            'contact_id' => $contact->id,
            'company_id' => $attributes['company_id'] ?? null,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'title' => $attributes['title'] ?? ($contact->displayName().' — '.$pipeline->name),
            'value_estimate' => $attributes['value_estimate'] ?? null,
            'confidence' => $attributes['confidence'] ?? 0,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => $attributes['source_id'] ?? null,
            'owner_id' => $attributes['owner_id'] ?? null,
            'status' => $stage->is_terminal ? Opportunity::STATUS_CLOSED : Opportunity::STATUS_OPEN,
            'outcome' => $stage->is_terminal ? $stage->terminal_outcome : null,
        ]);

        $this->timeline->recordSource(
            $contact,
            Event::TYPE_OPPORTUNITY_CREATED,
            __('leadhub::timeline.opportunity_created', ['title' => $opportunity->title]),
            ['opportunity_id' => $opportunity->id, 'pipeline' => $pipeline->slug],
        );

        event(new LeadHubOpportunityCreated($opportunity));

        return $opportunity;
    }

    /**
     * Idempotent upsert keyed by (contact, pipeline, source). Used by source
     * projectors so a repeated signal updates the same opportunity.
     */
    public function createOrUpdate(Contact $contact, Pipeline $pipeline, array $attributes = []): Opportunity
    {
        $existing = null;

        if (! empty($attributes['source_type']) && ! empty($attributes['source_id'])) {
            $existing = Opportunity::query()
                ->where('contact_id', $contact->id)
                ->where('pipeline_id', $pipeline->id)
                ->where('source_type', $attributes['source_type'])
                ->where('source_id', (string) $attributes['source_id'])
                ->first();
        }

        if (! $existing) {
            return $this->create($contact, $pipeline, $attributes);
        }

        // Update mutable fields; never auto-move a manually overridden deal.
        foreach (['title', 'value_estimate', 'confidence', 'owner_id', 'company_id'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $existing->setAttribute($field, $attributes[$field]);
            }
        }
        $existing->last_activity_at = now();
        $existing->save();

        if (! empty($attributes['stage_slug']) && ! $existing->manual_override) {
            $stage = $pipeline->stages()->where('slug', $attributes['stage_slug'])->first();
            if ($stage && $stage->id !== $existing->stage_id) {
                $this->transitions->transition($existing, $stage);
            }
        }

        return $existing->refresh();
    }
}
