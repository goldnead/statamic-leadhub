<?php

namespace Goldnead\Leadhub\Integrations\Notifications;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Leadhub\Models\Task;
use Goldnead\Notifications\Contracts\DigestSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Open tasks, contributed to the notifications digest.
 *
 * A digest source answers "what should this person also see for this window?"
 * — things nobody was notified about. An open task is the archetype: it was
 * assigned once, possibly weeks ago, and the fact that it is still open is not
 * an event anybody fires.
 *
 * Read through the query builder rather than through `Task`, mirroring the
 * bundled follow-up source: the digest must not break when this addon changes
 * a model, and it runs in a console process where the CRM feature flags of the
 * host are the host's business. The price is that the `HasBrand` global scope
 * does not apply, so the brand filter is written out by hand below — without
 * it this source would count another brand's tasks into somebody's digest.
 */
class TaskDigestSource implements DigestSource
{
    /**
     * Since statamic-notifications 1.10.0 only `line` reaches the mail: one
     * finished sentence. Anything else in this array is ignored, and a source
     * that returns no `line` contributes nothing at all — not a row in the
     * body, and not a reason to send the digest. The counts stay because they
     * are what the sentence is built from and what this addon's own tests read.
     *
     * Under an older notifications release the extra key is simply unused, so
     * this runs either way.
     *
     * @return array{line?: string, open_tasks?: int, overdue_tasks?: int}
     */
    public function collect(Identity $recipient, Carbon $windowStart, Carbon $windowEnd): array
    {
        // Tasks are keyed by the Statamic user id. A recipient identified only
        // by a contact uuid or an e-mail address cannot own one.
        if ($recipient->userId === null || ! $this->tableExists('leadhub_tasks')) {
            return [];
        }

        $base = fn () => DB::table('leadhub_tasks')
            ->where('assignee_id', $recipient->userId)
            ->where('status', Task::STATUS_OPEN)
            ->where('brand_id', BrandContext::hasCurrent()
                ? BrandContext::currentId()
                : BrandContext::defaultId());

        $open = $base()->count();

        if ($open === 0) {
            return [];
        }

        // Overdue is measured against the end of the window, not against now():
        // a digest for a past window has to describe that window.
        $overdue = $base()
            ->whereNotNull('due_at')
            ->where('due_at', '<', $windowEnd)
            ->count();

        return array_filter([
            'line' => $this->line($open, $overdue),
            'open_tasks' => $open,
            'overdue_tasks' => $overdue,
        ]);
    }

    /** What the recipient actually reads. */
    protected function line(int $open, int $overdue): string
    {
        $line = trans_choice('leadhub::tasks.notifications.digest_open', $open, ['count' => $open]);

        if ($overdue === 0) {
            return $line;
        }

        return $line.' '.trans_choice(
            'leadhub::tasks.notifications.digest_overdue',
            $overdue,
            ['count' => $overdue],
        );
    }

    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
