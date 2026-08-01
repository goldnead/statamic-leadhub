<?php

/**
 * Regression test: the leadhub:: translation namespace must be registered
 * with the actual content of resources/lang/, not just the literal keys.
 *
 * This was broken in 0.2.x — Statamic's `protected $translations = true`
 * auto-registers under the addon slug (e.g. `statamic-leadhub::`) instead
 * of the view namespace, so `__('leadhub::nav.dashboard')` returned the
 * key as-is and the CP showed raw translation keys.
 */
it('registers the leadhub:: namespace with real translations', function (): void {
    expect(__('leadhub::nav.dashboard'))->not->toBe('leadhub::nav.dashboard');
    expect(__('leadhub::dashboard.title'))->not->toBe('leadhub::dashboard.title');
    expect(__('leadhub::contacts.index.title'))->not->toBe('leadhub::contacts.index.title');
    expect(__('leadhub::permissions.view_leadhub'))->not->toBe('leadhub::permissions.view_leadhub');
});

it('returns sensible English defaults out of the box', function (): void {
    app()->setLocale('en');

    expect(__('leadhub::nav.dashboard'))->toBe('Dashboard');
    expect(__('leadhub::nav.contacts'))->toBe('Contacts');
    expect(__('leadhub::nav.followups'))->toBe('Follow-ups');
});

it('falls through to German when the locale is set to de', function (): void {
    app()->setLocale('de');

    expect(__('leadhub::nav.dashboard'))->toBe('Übersicht');
    expect(__('leadhub::nav.contacts'))->toBe('Kontakte');
});
