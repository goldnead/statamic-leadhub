<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Events\LeadHubContactScoreChanged;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\ScoringRule;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight lead scoring. Each activity type maps to a point value, and
 * points accumulate on the contact's engagement_score. Disabled unless
 * leadhub.features.scoring is true.
 *
 * Since v1.8.0 the point table lives in `leadhub_scoring_rules` and is editable
 * per brand in the Control Panel. `leadhub.scoring` in the config file is still
 * read, as the fallback described in {@see rulesFor}: an install that upgrades
 * without running `leadhub:scoring:import` must keep computing exactly the
 * scores it computed before, because a silent change here would move segment
 * membership, and segment membership decides who gets mail.
 *
 * All mutations of engagement_score funnel through the single {@see applyScore}
 * seam so that BOTH activity-based scoring and explicit adjust/set calls
 * persist consistently and emit {@see LeadHubContactScoreChanged} exactly once
 * per real change.
 */
class ScoringService
{
    /**
     * Rules for the brand they were loaded for, keyed by event type. Memoized
     * per instance (the listener resolves a fresh one per event), so a CP edit
     * is never served from a stale cache.
     *
     * @var array<string, ScoringRule>|null
     */
    protected ?array $ruleCache = null;

    protected ?int $ruleCacheBrand = null;

    public function __construct(protected ContactRepository $contacts)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('leadhub.features.scoring', false);
    }

    /**
     * Points awarded for a given activity/event type in the current brand.
     *
     * Resolution order: an enabled rule for the exact type, then the enabled
     * catch-all rule (`*`), then the config default. A DISABLED rule is treated
     * as an absent one and falls through the same chain — "off" has to mean
     * "as if it were never written", or deactivating would be a hidden way of
     * awarding zero.
     */
    public function pointsFor(string $type): int
    {
        $rules = $this->rulesFor($type);

        if ($rules === null) {
            $map = (array) config('leadhub.scoring.events', []);

            return (int) ($map[$type] ?? config('leadhub.scoring.default', 1));
        }

        $rule = $rules[$type] ?? null;

        if ($rule && $rule->enabled) {
            return (int) $rule->points;
        }

        $catchAll = $rules[ScoringRule::CATCH_ALL] ?? null;

        if ($catchAll && $catchAll->enabled) {
            return (int) $catchAll->points;
        }

        return (int) config('leadhub.scoring.default', 1);
    }

    /**
     * The current brand's rules keyed by event type, or NULL when the database
     * has nothing to say and the config table must be used verbatim.
     *
     * NULL is returned in three cases, and each of them is the safe answer:
     *
     * - the table does not exist (flat driver, or migrations not run yet),
     * - the query fails for any reason at all — scoring must never be the thing
     *   that takes a page down,
     * - the brand has no rules at all. This is the upgrade path: v1.7.0 → v1.8.0
     *   leaves the table empty, and until somebody runs `leadhub:scoring:import`
     *   the config file keeps deciding. An empty table meaning "everything
     *   scores the default" would be a silent rescoring of every install that
     *   upgraded — the single worst outcome this feature could have.
     *
     * @return array<string, ScoringRule>|null
     */
    protected function rulesFor(string $type): ?array
    {
        $brandId = $this->currentBrandId();

        if ($this->ruleCache !== null && $this->ruleCacheBrand === $brandId) {
            return $this->ruleCache ?: null;
        }

        $this->ruleCacheBrand = $brandId;
        $this->ruleCache = [];

        if (! $this->rulesTableExists()) {
            return null;
        }

        try {
            $this->ruleCache = ScoringRule::query()->get()->keyBy('event_type')->all();
        } catch (\Throwable) {
            $this->ruleCache = [];
        }

        return $this->ruleCache ?: null;
    }

    protected function currentBrandId(): ?int
    {
        try {
            return app('brand-context')->currentId();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Cached per request: Schema::hasTable() is a real round trip, and
     * pointsFor() runs on every scored activity.
     */
    protected function rulesTableExists(): bool
    {
        static $exists = null;

        if ($exists === null) {
            try {
                $exists = Schema::hasTable('leadhub_scoring_rules');
            } catch (\Throwable) {
                $exists = false;
            }
        }

        return $exists;
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
