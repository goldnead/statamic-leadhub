<?php

namespace Goldnead\Leadhub\Support;

use Goldnead\Leadhub\Services\LeadHubNotifier;

/**
 * "A bulk write is in progress" — a contact import, today.
 *
 * Contact events still fire for every row, because segments and the CRM sync
 * rightly want to know about each contact. What this flag tells the two
 * consumers that should not react per row is: the staff "new lead" alert
 * ({@see LeadHubNotifier::newLead()}) and the
 * outbound webhook bridge. An import of ten thousand rows would otherwise be
 * ten thousand emails and ten thousand webhook calls, none of them about a lead
 * that just walked in. Static, because those consumers are built by the
 * container on their own and share no instance with the importer.
 */
final class BulkWrite
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    public static function run(\Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
