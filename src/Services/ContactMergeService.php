<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Events\LeadHubContactsMerged;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Followup;
use Goldnead\Leadhub\Models\Note;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merges a duplicate contact (loser) into a surviving contact (winner):
 * re-parents the loser's timeline, notes, follow-ups and tags onto the winner,
 * fills any empty winner fields from the loser, then tombstones the loser via
 * merged_into_contact_id so it drops out of unmerged() listings.
 *
 * Eloquent driver only — relational re-parenting is not modelled in flat-file.
 */
class ContactMergeService
{
    public function __construct(protected TimelineService $timeline) {}

    public function merge(Contact $loser, Contact $winner): Contact
    {
        if ($loser->id === $winner->id) {
            throw new \InvalidArgumentException('Cannot merge a contact into itself.');
        }

        DB::transaction(function () use ($loser, $winner) {
            // Re-parent owned records.
            Event::query()->where('contact_id', $loser->id)->update(['contact_id' => $winner->id]);
            Note::query()->where('contact_id', $loser->id)->update(['contact_id' => $winner->id]);
            Followup::query()->where('contact_id', $loser->id)->update(['contact_id' => $winner->id]);

            // Move tags the winner does not already carry.
            $winnerTagIds = $winner->tags()->pluck('leadhub_tags.id')->all();
            $loserTagIds = $loser->tags()->pluck('leadhub_tags.id')->all();
            $toAttach = array_diff($loserTagIds, $winnerTagIds);
            if (! empty($toAttach)) {
                $winner->tags()->attach($toAttach);
            }

            // Re-parent CRM extensions when those tables are present.
            $this->reparentIfExists('leadhub_tasks', 'contact_id', $loser->id, $winner->id);
            $this->reparentIfExists('leadhub_opportunities', 'contact_id', $loser->id, $winner->id);

            // The money too. Left behind, it stays on a tombstoned contact and
            // the winner's lifetime total is quietly too low — which is exactly
            // the number a merge is supposed to make right. The cached totals
            // are rebuilt from the moved rows below, after the transaction.
            $this->reparentIfExists('leadhub_contact_revenue', 'contact_id', $loser->id, $winner->id);

            // Fill empty winner fields from the loser (never overwrite).
            $this->backfillWinnerFields($loser, $winner);

            // Merge metadata_json.
            $mergedMeta = array_merge((array) $loser->metadata_json, (array) $winner->metadata_json);
            $winner->metadata_json = $mergedMeta ?: null;

            // Highest engagement wins.
            $winner->engagement_score = max((int) $winner->engagement_score, (int) $loser->engagement_score);

            $winner->save();

            // Tombstone the loser.
            $loser->merged_into_contact_id = $winner->id;
            $loser->archived_at = $loser->archived_at ?? now();
            $loser->save();
        });

        $winner->refresh();

        // Outside the transaction, because it is a full recompute over the rows
        // the merge just moved — and because a cache that is briefly stale is a
        // smaller problem than a lock held across one.
        if (Schema::hasTable('leadhub_contact_revenue')) {
            app(RevenueService::class)->recalculate($winner);
        }

        $this->timeline->recordSource(
            $winner,
            Event::TYPE_CONTACTS_MERGED,
            __('leadhub::timeline.contacts_merged', ['name' => $loser->displayName()]),
            ['merged_contact_id' => $loser->id, 'merged_contact_uuid' => $loser->uuid],
        );

        event(new LeadHubContactsMerged($winner, null, [
            'merged_contact_id' => $loser->id,
            'merged_contact_uuid' => $loser->uuid,
        ]));

        return $winner;
    }

    protected function backfillWinnerFields(Contact $loser, Contact $winner): void
    {
        $fields = ['first_name', 'last_name', 'full_name', 'phone', 'phone_normalized', 'company', 'source', 'assigned_to', 'user_id', 'last_seen_at'];

        foreach ($fields as $field) {
            $current = $winner->getAttribute($field);
            if (($current === null || $current === '') && ! empty($loser->getAttribute($field))) {
                $winner->setAttribute($field, $loser->getAttribute($field));
            }
        }
    }

    protected function reparentIfExists(string $table, string $column, int $from, int $to): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }
    }
}
