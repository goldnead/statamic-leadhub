<?php

namespace Goldnead\Leadhub\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP listing runs before its first query.
 *
 * LeadHub is a CRM: its screens are nothing but queries, and every one of them
 * is a table this addon brings along in `database/migrations/`. An install that
 * composer-required the addon and never migrated therefore does not get a
 * half-working Control Panel, it gets HTTP 500 on `/cp/leadhub` and on every
 * page below it — a stack trace saying `no such table: leadhub_contacts` where
 * the reader expected the CRM. A missing table is an operator's unfinished
 * setup, not a bug, and it owes that reader one sentence instead.
 *
 * The reason must not vanish with the 500, though: every guarded page that
 * turns somebody away writes why to the log first. A page that renders an empty
 * state and says nothing anywhere would be worse than the crash it replaced —
 * the site would look installed and never work.
 */
final class Setup
{
    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        // The flat-file driver keeps contacts, events, notes, follow-ups, tags,
        // segments and form mappings in YAML and ships no migrations for them:
        // README calls that install "zero database required". Its tables are
        // *supposed* to be absent, so checking for them there would replace
        // every working screen with a "run php artisan migrate" that would not
        // help. Same reasoning, and the same line, as
        // CustomFieldService::definitions().
        if (config('leadhub.storage.driver', 'eloquent') !== 'eloquent') {
            return null;
        }

        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-leadhub: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('leadhub::SetupRequired', [
            'title' => $title,
            'heading' => __('leadhub::messages.setup_required_heading'),
            'description' => __('leadhub::messages.setup_required_description'),
            'tables' => $missing,
        ]);
    }
}
