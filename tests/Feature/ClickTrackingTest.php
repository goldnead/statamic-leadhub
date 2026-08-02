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
