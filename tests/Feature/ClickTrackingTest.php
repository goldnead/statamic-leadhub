<?php

use Goldnead\Leadhub\Events\LeadHubEmailLinkClicked;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\ClickTracking\ClickTrackingLinker;
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
