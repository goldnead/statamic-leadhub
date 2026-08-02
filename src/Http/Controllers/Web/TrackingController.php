<?php

namespace Goldnead\Leadhub\Http\Controllers\Web;

use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Events\LeadHubEmailLinkClicked;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\ClickTracking\RecipientResolver;
use Goldnead\Leadhub\Services\ClickTracking\TrackingParameters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public endpoint that email link-clicks redirect through.
 *
 * Golden rule: the recipient ALWAYS reaches their link. Signature validation,
 * consent checks, dedupe and scoring are all best-effort side effects — any
 * failure short-circuits to a plain 302 redirect, never an error page.
 *
 * Scoring is gated three ways:
 *   1. features.click_tracking must be on (opt-in master switch),
 *   2. the URL signature must be valid (anti-forgery),
 *   3. the resolved contact must have marketing consent (GDPR).
 * A dedupe window then guarantees repeated clicks score at most once.
 */
class TrackingController
{
    public function __construct(
        protected RecipientResolver $resolver,
        protected EventRepository $events,
    ) {}

    public function click(Request $request): RedirectResponse
    {
        $target = $this->safeTarget($request->query('url'));

        // Everything below is a side effect: never let it break the redirect.
        try {
            $this->maybeTrack($request, $target);
        } catch (\Throwable $e) {
            // Swallow — tracking must never cost the recipient their click.
            report($e);
        }

        return redirect()->away($target, 302);
    }

    /**
     * Emit + score the click when all gates pass. No-op (silent) otherwise.
     */
    protected function maybeTrack(Request $request, string $target): void
    {
        // Master switch. Off by default (consent-first opt-in).
        if (! config('leadhub.features.click_tracking', false)) {
            return;
        }

        // Anti-forgery: an invalid/absent signature still redirects (handled by
        // the caller) but is never scored.
        //
        // Sending services append their own parameter when forwarding a click
        // (Brevo `_se`, Mailchimp `mc_eid`, …). Those are excluded from the
        // check so an ESP-forwarded click still scores. Everything that carries
        // meaning — the redirect target above all — stays signed; see
        // TrackingParameters::RESERVED.
        if (! $request->hasValidSignatureWhileIgnoring(TrackingParameters::ignored())) {
            return;
        }

        $contact = $this->resolver->resolve(
            $request->query('c'),
            $request->query('e'),
        );

        if (! $contact instanceof Contact) {
            return;
        }

        // GDPR consent gate — never track transactional-only contacts.
        if (! $this->hasMarketingConsent($contact)) {
            return;
        }

        $dedupeKey = $this->dedupeKey($contact, $target);

        // Dedupe window: a matching key means we already recorded + scored a
        // click of this link by this contact in the current window.
        if ($this->events->findByDedupeKey($dedupeKey) instanceof Event) {
            return;
        }

        $this->events->recordSource($contact, LeadHubEmailLinkClicked::TYPE, [
            'summary' => 'Clicked a tracked email link',
            'payload' => array_filter([
                'url' => $target,
                'tpl' => $request->query('tpl'),
                'eml' => $request->query('eml'),
            ], fn ($v) => $v !== null),
            'source_type' => 'email',
            'source_id' => $request->query('eml') ?? $request->query('tpl'),
            'dedupe_key' => $dedupeKey,
            'occurred_at' => now(),
        ]);

        // Dispatch the scored activity. ScoreContactOnActivity awards the points
        // (metadata['type'] maps to leadhub.scoring.events.email_link_clicked).
        event(new LeadHubEmailLinkClicked($contact, null, [
            'type' => LeadHubEmailLinkClicked::TYPE,
            'url' => $target,
            'dedupe_key' => $dedupeKey,
        ]));
    }

    /** Marketing consent given and contact not on the do-not-contact list. */
    protected function hasMarketingConsent(Contact $contact): bool
    {
        return (bool) $contact->consent && ! (bool) $contact->do_not_contact;
    }

    /**
     * Stable key that collapses repeated clicks of the same link by the same
     * contact into one scored event per dedupe window.
     */
    protected function dedupeKey(Contact $contact, string $target): string
    {
        $windowMinutes = (int) config('leadhub.click_tracking.dedupe_window', 60);
        $windowSeconds = max(1, $windowMinutes) * 60;
        $bucket = (int) floor(now()->getTimestamp() / $windowSeconds);

        return implode(':', [
            LeadHubEmailLinkClicked::TYPE,
            $contact->uuid,
            sha1($target),
            $bucket,
        ]);
    }

    /**
     * Sanitize the redirect target. Only http(s) URLs are forwarded verbatim;
     * anything else (missing, javascript:, data:, …) falls back to the site
     * root so this endpoint can never be abused as an open redirect to a
     * dangerous scheme.
     */
    protected function safeTarget(mixed $url): string
    {
        $url = is_string($url) ? trim($url) : '';

        if ($url !== '' && Str::startsWith(Str::lower($url), ['http://', 'https://'])) {
            return $url;
        }

        return url('/');
    }
}
