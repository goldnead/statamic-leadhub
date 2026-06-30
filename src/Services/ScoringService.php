<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;

/**
 * Lightweight lead scoring. Each activity type maps to a point value (config
 * leadhub.scoring.events), and points accumulate on the contact's
 * engagement_score. Disabled unless leadhub.features.scoring is true.
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

        $contact->engagement_score = (int) $contact->engagement_score + $points;
        $this->contacts->save($contact);

        return (int) $contact->engagement_score;
    }
}
