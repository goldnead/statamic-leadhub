<?php

use Goldnead\Leadhub\Events\LeadHubEmailLinkClicked;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\ClickTracking\ClickTrackingLinker;
use Goldnead\Leadhub\Services\ClickTracking\TrackingParameters;
use Illuminate\Support\Facades\Event as EventFacade;

// Scoring + the events table target the eloquent driver (CRM core), same as
// the ingestion pipeline. Skip the flat-file matrix job.
beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Click tracking + scoring target the eloquent driver.');
    }

    // Register the addon's $listen listeners so ScoreContactOnActivity fires.
    test()->bootAddonEvents();

    config()->set('leadhub.features.scoring', true);
    config()->set('leadhub.features.click_tracking', true);
    config()->set('leadhub.scoring.events.email_link_clicked', 3);
});

function trackedContact(array $overrides = []): Contact
{
    return Contact::factory()->create(array_merge([
        'consent' => true,
        'do_not_contact' => false,
        'engagement_score' => 0,
    ], $overrides));
}

it('emits an email_link_clicked event and 302-redirects on a valid signed click', function () {
    EventFacade::fake([LeadHubEmailLinkClicked::class]);

    $contact = trackedContact();
    $target = 'https://adriangoldner.com/kurse';
    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    $this->get($url)
        ->assertStatus(302)
        ->assertRedirect($target);

    EventFacade::assertDispatched(LeadHubEmailLinkClicked::class, function ($event) use ($contact) {
        return $event->contact->is($contact)
            && ($event->metadata['type'] ?? null) === LeadHubEmailLinkClicked::TYPE;
    });
});

it('scores the click and records a deduped timeline event', function () {
    $contact = trackedContact();
    $target = 'https://adriangoldner.com/kurse';
    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    $this->get($url)->assertRedirect($target);

    expect($contact->fresh()->engagement_score)->toBe(3);
    expect(Event::where('type', LeadHubEmailLinkClicked::TYPE)->count())->toBe(1);
});

it('redirects but does not score when the signature is invalid', function () {
    EventFacade::fake([LeadHubEmailLinkClicked::class]);

    $contact = trackedContact();
    $target = 'https://adriangoldner.com/kurse';
    $signed = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    // Tamper with the target after signing — signature no longer matches.
    $forged = str_replace('kurse', 'kurse-forged', $signed);

    $this->get($forged)->assertStatus(302);

    expect($contact->fresh()->engagement_score)->toBe(0);
    expect(Event::where('type', LeadHubEmailLinkClicked::TYPE)->count())->toBe(0);
    EventFacade::assertNotDispatched(LeadHubEmailLinkClicked::class);
});

it('redirects but does not score a contact without marketing consent', function () {
    EventFacade::fake([LeadHubEmailLinkClicked::class]);

    $contact = trackedContact(['consent' => false]);
    $target = 'https://adriangoldner.com/kurse';
    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    $this->get($url)->assertRedirect($target);

    expect($contact->fresh()->engagement_score)->toBe(0);
    expect(Event::where('type', LeadHubEmailLinkClicked::TYPE)->count())->toBe(0);
    EventFacade::assertNotDispatched(LeadHubEmailLinkClicked::class);
});

it('redirects but does not score a do-not-contact contact', function () {
    $contact = trackedContact(['do_not_contact' => true]);
    $target = 'https://adriangoldner.com/kurse';
    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    $this->get($url)->assertRedirect($target);

    expect($contact->fresh()->engagement_score)->toBe(0);
});

it('scores only once for repeated clicks within the dedupe window', function () {
    config()->set('leadhub.click_tracking.dedupe_window', 60);

    $contact = trackedContact();
    $target = 'https://adriangoldner.com/kurse';
    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    $this->get($url)->assertRedirect($target);
    $this->get($url)->assertRedirect($target);

    expect($contact->fresh()->engagement_score)->toBe(3);
    expect(Event::where('type', LeadHubEmailLinkClicked::TYPE)->count())->toBe(1);
});

it('rewrites normal links but leaves mailto, anchors and unsubscribe links alone', function () {
    $contact = trackedContact();

    $html = <<<'HTML'
    <a href="https://adriangoldner.com/kurse">Kurse</a>
    <a href="mailto:info@adriangoldner.com">Mail</a>
    <a href="#top">Nach oben</a>
    <a href="https://lists.example.com/unsubscribe?u=42">Abmelden</a>
    <a href="{{ unsubscribe_url }}">Newsletter abbestellen</a>
    HTML;

    $out = app(ClickTrackingLinker::class)->rewriteHtml($html, $contact);

    // Normal link is rewritten to the signed tracking path.
    expect($out)->toContain(ClickTrackingLinker::PATH);
    expect($out)->toContain('signature=');

    // Everything that must never be tracked is untouched.
    expect($out)->toContain('href="mailto:info@adriangoldner.com"');
    expect($out)->toContain('href="#top"');
    expect($out)->toContain('https://lists.example.com/unsubscribe?u=42');
    expect($out)->toContain('{{ unsubscribe_url }}');

    // The original bare target no longer appears as a naked href.
    expect($out)->not->toContain('href="https://adriangoldner.com/kurse"');
});

it('generates a signed tracked URL carrying the contact identifier', function () {
    $contact = trackedContact();
    $target = 'https://adriangoldner.com/kurse';

    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact, ['tpl' => 7]);

    expect($url)->toContain(ClickTrackingLinker::PATH)
        ->toContain('c='.$contact->uuid)
        ->toContain('tpl=7')
        ->toContain('signature=');

    // And the signed link actually validates + scores end-to-end.
    $this->get($url)->assertRedirect($target);
    expect($contact->fresh()->engagement_score)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| ESP-appended query parameters
|--------------------------------------------------------------------------
|
| Sending services rewrite every href onto their own click counter and append
| a parameter when forwarding the recipient (Brevo appends `_se`). Laravel
| signs the whole query, so the appended parameter used to break the signature
| check and the click went unscored — the recipient still arrived, the click
| simply vanished. Measured 2026-07-31: score 3 → 0 with `_se` appended.
|
*/

it('scores a click that a sending service forwarded with an appended parameter', function () {
    $contact = trackedContact();
    $target = 'https://adriangoldner.com/kurse';
    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    // Exactly what Brevo does: recipient address, base64, appended to the query.
    $forwarded = $url.'&_se='.rtrim(base64_encode('leser@example.com'), '=');

    $this->get($forwarded)->assertStatus(302)->assertRedirect($target);

    expect($contact->fresh()->engagement_score)->toBe(3);
    expect(Event::where('type', LeadHubEmailLinkClicked::TYPE)->count())->toBe(1);
});

it('scores a click carrying several appended tracking parameters', function () {
    $contact = trackedContact();
    $target = 'https://adriangoldner.com/kurse';
    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact, ['tpl' => 7]);

    $forwarded = $url.'&_se=abc&mc_eid=123&fbclid=xyz';

    $this->get($forwarded)->assertRedirect($target);

    expect($contact->fresh()->engagement_score)->toBe(3);
    expect(Event::where('type', LeadHubEmailLinkClicked::TYPE)->count())->toBe(1);
});

it('ignores an unknown appended parameter only when it is configured', function () {
    config()->set('leadhub.click_tracking.ignored_query_parameters', []);

    // This is the golden rule's own case: a sending service appends its
    // parameter, the signature stops matching, and the reader must still
    // arrive. Since the open-redirect fix that holds for hosts we know — which
    // in production is the site's own domain, and here has to be said out loud.
    config()->set('leadhub.click_tracking.allowed_redirect_hosts', ['adriangoldner.com']);

    $contact = trackedContact();
    $target = 'https://adriangoldner.com/kurse';
    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    $this->get($url.'&_se=abc')->assertRedirect($target);

    // Not configured → still signed → still unscored. The list is the switch.
    expect($contact->fresh()->engagement_score)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| SECURITY: the redirect target must never leave the signature
|--------------------------------------------------------------------------
|
| The target rides in the query (`?url=…`). A parameter excluded from the
| signature can be chosen freely by anyone, so excluding `url` would let an
| attacker point a validly signed link anywhere and have the click attributed.
| TrackingParameters::RESERVED refuses that regardless of configuration.
|
*/

it('refuses to drop the redirect target from the signature even when configured to', function () {
    // A misconfiguration (or a malicious config edit) naming the target.
    config()->set('leadhub.click_tracking.ignored_query_parameters', ['url', '_se']);

    expect(TrackingParameters::ignored())->toBe(['_se'])
        ->and(TrackingParameters::ignored())->not->toContain('url');

    $contact = trackedContact();
    $signed = app(ClickTrackingLinker::class)->trackedUrl('https://adriangoldner.com/kurse', $contact);

    // Valid signature, target swapped for an attacker's host.
    $forged = str_replace(
        urlencode('https://adriangoldner.com/kurse'),
        urlencode('https://angreifer.example/phish'),
        $signed
    );

    $this->get($forged.'&_se=abc')->assertStatus(302);

    // Rejected: nothing scored, nothing recorded, no attribution gained.
    expect($contact->fresh()->engagement_score)->toBe(0);
    expect(Event::where('type', LeadHubEmailLinkClicked::TYPE)->count())->toBe(0);
});

it('refuses to drop the contact and source identifiers from the signature', function () {
    config()->set('leadhub.click_tracking.ignored_query_parameters', [
        'url', 'c', 'e', 'tpl', 'eml', 'signature', 'expires', '_se',
    ]);

    expect(TrackingParameters::ignored())->toBe(['_se']);

    $victim = trackedContact();
    $other = trackedContact();
    $target = 'https://adriangoldner.com/kurse';

    // Tampering breaks the signature, and since 2026-08-24 a broken signature
    // means the host decides whether we forward at all. Listing our own site
    // here keeps this test about what it is about — that a swapped contact does
    // not score — while the golden rule (the reader still arrives) stays
    // exercised. A target we do NOT know now lands on the site root; that is
    // `it refuses to forward an unsigned link to a foreign host` below.
    config()->set('leadhub.click_tracking.allowed_redirect_hosts', ['adriangoldner.com']);

    $signed = app(ClickTrackingLinker::class)->trackedUrl($target, $victim);

    // Valid signature, contact swapped — score inflation attempt.
    $forged = str_replace('c='.$victim->uuid, 'c='.$other->uuid, $signed);

    $this->get($forged)->assertRedirect($target);

    expect($other->fresh()->engagement_score)->toBe(0);
    expect($victim->fresh()->engagement_score)->toBe(0);
});

it('treats reserved parameter names case-insensitively', function () {
    config()->set('leadhub.click_tracking.ignored_query_parameters', ['URL', 'Signature', '_se']);

    expect(TrackingParameters::ignored())->toBe(['_se']);
});

it('survives a malformed ignore list without ignoring anything', function () {
    config()->set('leadhub.click_tracking.ignored_query_parameters', 'not-an-array');
    expect(TrackingParameters::ignored())->toBe([]);

    config()->set('leadhub.click_tracking.ignored_query_parameters', ['_se', '', '  ', 42, '_se']);
    expect(TrackingParameters::ignored())->toBe(['_se']);
});

it('never signs a parameter that will later be ignored', function () {
    $contact = trackedContact();

    // A caller passing ESP noise as context would otherwise produce a link
    // whose signature can never validate (signed with it, checked without it).
    $url = app(ClickTrackingLinker::class)->trackedUrl(
        'https://adriangoldner.com/kurse',
        $contact,
        ['_se' => 'abc', 'tpl' => 7]
    );

    expect($url)->not->toContain('_se=')
        ->and($url)->toContain('tpl=7');

    $this->get($url)->assertRedirect('https://adriangoldner.com/kurse');
    expect($contact->fresh()->engagement_score)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Open redirect (fixed 2026-08-24)
|--------------------------------------------------------------------------
|
| Until this fix the endpoint checked the scheme and nothing else, so anyone
| could hand out `https://<our-domain>/lh/track/click?url=https://phishing.example`
| and the link wore our domain all the way to the attacker.
|
| The rule now: a valid signature means we wrote the URL ourselves and it is
| forwarded verbatim. Without one, only a host we recognise.
|
*/

it('forwards a signed link to any host, because we signed it ourselves', function () {
    $contact = trackedContact();
    $target = 'https://ein-partner.example/kurse';

    $url = app(ClickTrackingLinker::class)->trackedUrl($target, $contact);

    $this->get($url)->assertRedirect($target);
});

it('refuses to forward an unsigned link to a foreign host', function () {
    $response = $this->get('/'.ClickTrackingLinker::PATH.'?url=https://phishing.example/login');

    $response->assertRedirect(url('/'));
    expect($response->headers->get('Location'))->not->toContain('phishing.example');
});

it('still forwards an unsigned link to our own host', function () {
    // The golden rule case: a sending service mangled the parameters so the
    // signature broke, but the link points where it always did.
    $target = url('/kurse');

    $this->get('/'.ClickTrackingLinker::PATH.'?url='.urlencode($target))
        ->assertRedirect($target);
});

it('forwards an unsigned link to a host the site listed', function () {
    config()->set('leadhub.click_tracking.allowed_redirect_hosts', ['partner.example']);

    $target = 'https://partner.example/anmeldung';

    $this->get('/'.ClickTrackingLinker::PATH.'?url='.urlencode($target))
        ->assertRedirect($target);
});

it('matches the host exactly and not by suffix', function () {
    config()->set('leadhub.click_tracking.allowed_redirect_hosts', ['partner.example']);

    // str_ends_with() would accept this one. parse_url plus an exact compare
    // does not.
    $this->get('/'.ClickTrackingLinker::PATH.'?url='.urlencode('https://boese-partner.example/x'))
        ->assertRedirect(url('/'));
});

it('is not fooled by a host smuggled into the userinfo', function () {
    config()->set('leadhub.click_tracking.allowed_redirect_hosts', ['partner.example']);

    // A browser goes to evil.example here; the part that looks like the partner
    // is userinfo. Comparing the raw URL instead of the parsed host would pass.
    $this->get('/'.ClickTrackingLinker::PATH.'?url='.urlencode('https://partner.example@evil.example/x'))
        ->assertRedirect(url('/'));
});

it('never forwards a dangerous scheme', function () {
    foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', '//evil.example/x'] as $bad) {
        $this->get('/'.ClickTrackingLinker::PATH.'?url='.urlencode($bad))
            ->assertRedirect(url('/'));
    }
});

it('sends the recipient home rather than nowhere when the url is missing', function () {
    $this->get('/'.ClickTrackingLinker::PATH)->assertRedirect(url('/'));
});
