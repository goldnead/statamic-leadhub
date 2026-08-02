<?php

namespace Goldnead\Leadhub\Services\ClickTracking;

/**
 * Which query parameters a signature check may ignore.
 *
 * Sending services rewrite every href of an email into their own click counter
 * and append a parameter when they forward the recipient (Brevo adds `_se`,
 * Mailchimp `mc_eid`, HubSpot `_hsenc`, …). Laravel signs the whole query, so
 * one appended parameter changes what was signed and the signature no longer
 * matches — the click goes unscored (see TrackingController::maybeTrack).
 *
 * Ignoring those parameters restores the check. Ignoring the wrong one hands an
 * attacker the endpoint, which is what RESERVED prevents.
 *
 * SECURITY: the tracking link carries its redirect target in the QUERY
 * (`?url=…`). A parameter excluded from the signature can be set to anything by
 * anyone, so excluding `url` would turn this endpoint into an open redirect
 * that forwards to any attacker-chosen address under a valid signature. The
 * same reasoning covers the contact identifiers (`c`, `e` — forged attribution
 * and score inflation), the source context (`tpl`, `eml` — forged attribution)
 * and Laravel's own `signature`/`expires`. None of them can ever be ignored,
 * whatever the config says.
 */
class TrackingParameters
{
    /**
     * Query parameters that carry meaning and therefore MUST stay signed.
     * A config entry naming one of these is dropped, not honoured.
     *
     * @var list<string>
     */
    public const RESERVED = ['url', 'c', 'e', 'tpl', 'eml', 'signature', 'expires'];

    /**
     * Parameters a signature check may safely ignore: the configured list
     * minus anything reserved, deduplicated.
     *
     * @return list<string>
     */
    public static function ignored(): array
    {
        $configured = config('leadhub.click_tracking.ignored_query_parameters', []);

        if (! is_array($configured)) {
            return [];
        }

        $ignored = [];

        foreach ($configured as $param) {
            if (! is_string($param)) {
                continue;
            }

            $param = trim($param);

            if ($param === '' || self::isReserved($param)) {
                continue;
            }

            $ignored[] = $param;
        }

        return array_values(array_unique($ignored));
    }

    /** True when this parameter must never be excluded from the signature. */
    public static function isReserved(string $param): bool
    {
        return in_array(strtolower($param), self::RESERVED, true);
    }
}
