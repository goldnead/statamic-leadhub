<?php

namespace Goldnead\Leadhub\Events;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a contact's engagement_score actually changes — via
 * activity-based scoring ({@see \Goldnead\Leadhub\Services\ScoringService::award})
 * or an explicit adjust/set (LeadHub::adjustScore / ::setScore).
 *
 * Only ever dispatched on a real change (newScore !== oldScore); no-op writes
 * never fire it. This is the stable contract sibling addons
 * (statamic-automations) subscribe to in order to react to score changes.
 *
 * Note: this does NOT extend {@see LeadHubEvent} — it carries a score-specific
 * payload (old/new/delta/reason) rather than the generic actor/metadata shape.
 */
class LeadHubContactScoreChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Contact $contact,
        public readonly int $oldScore,
        public readonly int $newScore,
        public readonly int $delta,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Stable array representation consumed by automations' flow context.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'contact_id' => $this->contact->id,
            'email' => $this->contact->email,
            'old_score' => $this->oldScore,
            'new_score' => $this->newScore,
            'delta' => $this->delta,
            'reason' => $this->reason,
        ];
    }
}
