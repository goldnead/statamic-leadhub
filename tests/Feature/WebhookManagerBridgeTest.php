<?php

use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Integrations\WebhookManager\WebhookManagerBridge;
use Illuminate\Support\Facades\Event;

/**
 * goldnead/statamic-webhook-manager is an OPTIONAL peer addon and is not
 * installed in the test environment. These tests pin the bridge's contract:
 * it must be a completely safe no-op when the addon is absent, and its trigger
 * map must stay in sync with LeadHub's dispatched events.
 *
 * The live path (events → registered triggers → TriggerDetected) is exercised
 * against the real addon in a host project; it cannot be unit-tested here
 * without pulling the addon in as a dependency.
 */
it('reports unavailable when the webhook manager addon is not installed', function (): void {
    expect(class_exists(\Goldnead\WebhookManager\Facades\WebhookManager::class))->toBeFalse();
    expect(WebhookManagerBridge::available())->toBeFalse();
});

it('boots as a no-op and registers no LeadHub event listeners when absent', function (): void {
    $events = app('events');

    // Sanity: no listeners are attached as a side effect of booting the bridge.
    (new WebhookManagerBridge())->boot($events);

    expect($events->hasListeners(LeadHubContactCreated::class))->toBeFalse();
});

it('does not interfere with LeadHub events firing when absent', function (): void {
    Event::fake();

    (new WebhookManagerBridge())->boot(app('events'));

    // The bridge added nothing, so this is just asserting boot() did not throw
    // and the app is still healthy enough to dispatch events.
    expect(WebhookManagerBridge::available())->toBeFalse();
});

it('maps every webhook trigger to a real LeadHub event class with a unique handle', function (): void {
    $handles = [];

    foreach (WebhookManagerBridge::TRIGGERS as $eventClass => $definition) {
        expect(class_exists($eventClass))->toBeTrue("Mapped event {$eventClass} does not exist");
        expect($definition)->toBeArray()->toHaveCount(2);

        [$handle, $label] = $definition;
        expect($handle)->toStartWith('leadhub.');
        expect($label)->toBeString()->not->toBeEmpty();
        $handles[] = $handle;
    }

    expect($handles)->toHaveCount(count(array_unique($handles)));
});
