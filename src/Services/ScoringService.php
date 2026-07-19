<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Events\LeadHubContactScoreChanged;
use Goldnead\Leadhub\Models\Contact;

/**
 * Lightweight lead scoring. Each activity type maps to a point value (config
 * leadhub.scoring.events), and points accumulate on the contact's
 * engagement_score. Disabled unless leadhub.features.scoring is true.
 *
 * All mutations of engagement_score funnel through the single {@see applyScore}
 * seam so that BOTH activity-based scoring and explicit adjust/set calls
 * persist consistently and emit {@see LeadHubContactScoreChanged} exactly once
 * per real change.
 */
class ScoringService
{
    public function __construct(protected ContactRepository $contacts)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('leadhub.features.scoring', false);
    }

    /** Points configured for a given activity/event type. */
    public function pointsFor(string $type): int
    {
        $map = (array) config('leadhub.scoring.events', []);

        return (int) ($map[$type] ?? config('leadhub.scoring.default', 1));
    }

    /** Add points for an activity type and persist. Returns the new score. */
    public function award(Contact $contact, string $type): int
    {
        if (! $this->enabled()) {
            return (int) $contact->engagement_score;
        }

        $points = $this->pointsFor($type);

        if ($points === 0) {
            return (int) $contact->engagement_score;
        }

        return $this->applyScore($contact, (int) $contact->engagement_score + $points, $type);
    }

    /**
     * Explicit relative score change (e.g. the automations "change score"
     * action). Not gated by the scoring feature flag — it is a deliberate
     * caller-driven mutation. Returns the new (clamped) score.
     */
    public function adjust(Contact $contact, int $delta, ?string $reason = null): int
    {
        return $this->applyScore($contact, (int) $contact->engagement_score + $delta, $reason);
    }

    /** Explicit absolute score set. Returns the new (clamped) score. */
    public function set(Contact $contact, int $score, ?string $reason = null): int
    {
        return $this->applyScore($contact, $score, $reason);
    }

    /**
     * Single source of truth for mutating + persisting engagement_score.
     * Clamps at 0, persists only on a real change, and fires
     * {@see LeadHubContactScoreChanged} exactly once per actual change. A no-op
     * (newScore === oldScore) neither writes nor emits.
     */
    protected function applyScore(Contact $contact, int $newScore, ?string $reason = null): int
    {
        $oldScore = (int) $contact->engagement_score;
        $newScore = max(0, $newScore);

        if ($newScore === $oldScore) {
            return $oldScore;
        }

        $contact->engagement_score = $newScore;
        $this->contacts->save($contact);

        LeadHubContactScoreChanged::dispatch(
            $contact,
            $oldScore,
            $newScore,
            $newScore - $oldScore,
            $reason,
        );

        return $newScore;
    }
}
