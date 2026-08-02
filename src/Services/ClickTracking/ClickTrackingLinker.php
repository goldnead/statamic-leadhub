<?php

namespace Goldnead\Leadhub\Services\ClickTracking;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Builds signed click-tracking links and rewrites the <a href> links in an
 * email body to point at them.
 *
 * This is the surface sibling addons (automations / email-templates) call from
 * the send path. It is bound as a public singleton so any consumer can resolve
 * it: `app(ClickTrackingLinker::class)`.
 *
 * The generated URL is a Laravel signed route — the target URL, contact id and
 * any context ride in the query string and are covered by the signature, so a
 * forged link fails signature validation and is never scored (see
 * TrackingController). Signatures do not expire (marketing emails are long
 * lived).
 */
class ClickTrackingLinker
{
    /** Named route the signed URL points at. */
    public const ROUTE_NAME = 'leadhub.track.click';

    /** URL path segment used to detect already-tracked links. */
    public const PATH = 'lh/track/click';

    /**
     * Query keys this class owns; a caller's $context may not overwrite them.
     *
     * Distinct from TrackingParameters::RESERVED, which answers a different
     * question — what may never be dropped from the signature check. `tpl` and
     * `eml` are legitimate caller context (settable here) yet still carry
     * meaning (never ignorable there), so the two lists are not the same.
     *
     * @var list<string>
     */
    public const RESERVED_CONTEXT_KEYS = ['url', 'c', 'e', 'signature', 'expires'];

    /**
     * Build a signed tracking URL that redirects to $targetUrl and, on hit,
     * scores an email_link_clicked event for $contact (consent permitting).
     *
     * @param  array<string,scalar>  $context  Optional extra signed params,
     *                                         e.g. ['tpl' => 42, 'eml' => 'welcome'].
     */
    public function trackedUrl(string $targetUrl, Contact $contact, array $context = []): string
    {
        $params = array_merge($this->sanitizeContext($context), [
            'url' => $targetUrl,
            'c' => $contact->uuid,
        ]);

        // Email fallback identifier — signed, so it cannot be tampered with.
        if ($contact->email) {
            $params['e'] = $contact->email;
        }

        return URL::signedRoute(self::ROUTE_NAME, $params);
    }

    /**
     * Rewrite every eligible <a href> in an HTML body to a tracked URL.
     *
     * Skips links that must never be tracked: mailto:/tel:, in-page anchors
     * (#…), javascript:, unsubscribe / abmelden links, template merge tags
     * ({{ … }}), already-tracked links, non-http(s) targets, and any anchor
     * carrying a `data-lh-notrack` opt-out attribute.
     *
     * @param  array<string,scalar>  $context
     */
    public function rewriteHtml(string $html, Contact $contact, array $context = []): string
    {
        if ($html === '') {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<a\b([^>]*?)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>/is',
            function (array $m) use ($contact, $context) {
                [$full, $pre, $quote, $href, $post] = $m;

                if ($this->shouldSkip($href, $pre.$post)) {
                    return $full;
                }

                $tracked = htmlspecialchars(
                    $this->trackedUrl(html_entity_decode($href, ENT_QUOTES), $contact, $context),
                    ENT_QUOTES,
                    'UTF-8'
                );

                return '<a'.$pre.'href='.$quote.$tracked.$quote.$post.'>';
            },
            $html
        );
    }

    /** True when this href must not be rewritten. */
    protected function shouldSkip(string $href, string $attrs): bool
    {
        $href = trim($href);

        if ($href === '') {
            return true;
        }

        // Explicit per-link opt-out marker on the anchor.
        if (Str::contains(Str::lower($attrs), 'data-lh-notrack')) {
            return true;
        }

        // Template merge tags — the real URL is not known yet.
        if (Str::contains($href, ['{{', '}}'])) {
            return true;
        }

        $lower = Str::lower($href);

        // Only ever track absolute http(s) targets.
        if (! Str::startsWith($lower, ['http://', 'https://'])) {
            return true;
        }

        // Already a tracking link.
        if (Str::contains($lower, self::PATH)) {
            return true;
        }

        // Unsubscribe links (EN + DE) must always reach the ESP untouched.
        if (Str::contains($lower, ['unsubscribe', 'abmelden', 'opt-out', 'optout'])) {
            return true;
        }

        return false;
    }

    /**
     * Whitelist + stringify context params. Reserved keys (url, c, e,
     * signature, expires) cannot be overridden by a caller.
     *
     * Parameters listed as ESP noise are dropped too: they are excluded from
     * the signature check on the way back in, so signing them here would
     * produce a link whose signature can never validate.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,scalar>
     */
    protected function sanitizeContext(array $context): array
    {
        $ignored = TrackingParameters::ignored();
        $out = [];

        foreach ($context as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (in_array($key, self::RESERVED_CONTEXT_KEYS, true) || in_array($key, $ignored, true)) {
                continue;
            }

            if (is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
