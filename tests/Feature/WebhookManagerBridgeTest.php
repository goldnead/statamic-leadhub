<?php

use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Leadhub\ServiceProvider;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

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
    expect(class_exists(WebhookManager::class))->toBeFalse();
    expect(WebhookManagerBridge::available())->toBeFalse();
});

it('boots as a no-op and registers no LeadHub event listeners when absent', function (): void {
    $events = app('events');

    // Sanity: no listeners are attached as a side effect of booting the bridge.
    (new WebhookManagerBridge)->boot($events);

    expect($events->hasListeners(LeadHubContactCreated::class))->toBeFalse();
});

it('does not interfere with LeadHub events firing when absent', function (): void {
    Event::fake();

    (new WebhookManagerBridge)->boot(app('events'));

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

it('is bound as a singleton so the boot guard holds across resolutions', function (): void {
    expect(app(WebhookManagerBridge::class))->toBe(app(WebhookManagerBridge::class));
});

/*
 * Boot-order regression: sibling addon boot order is not guaranteed. When
 * LeadHub's provider boots before webhook-manager's, the 'webhook-manager'
 * container binding does not exist yet, so eager registration would fail for
 * every trigger ("Target class [webhook-manager] does not exist", swallowed
 * as a warning). The provider must therefore defer the bridge boot into an
 * app->booted() callback instead of running it inline.
 */
it('defers bridge registration into an app->booted callback', function (): void {
    $app = new class extends Container
    {
        /** @var array<int, callable> */
        public array $bootedCallbacks = [];

        public function booted($callback): void
        {
            $this->bootedCallbacks[] = $callback;
        }
    };

    $spy = new class extends WebhookManagerBridge
    {
        public int $bootCalls = 0;

        public function boot(Dispatcher $events): void
        {
            $this->bootCalls++;
        }
    };

    $app->instance(WebhookManagerBridge::class, $spy);
    $app->instance('events', app('events'));

    $provider = new ServiceProvider($app);
    $method = new ReflectionMethod($provider, 'registerWebhookManagerBridge');
    $method->invoke($provider);

    // Nothing runs inline — the work is queued for after all providers booted.
    expect($spy->bootCalls)->toBe(0);
    expect($app->bootedCallbacks)->toHaveCount(1);

    // Firing the booted callback performs the bridge boot AND queues a retry
    // at the end of the booted queue (covers the case where the first run
    // fires before sibling addons finished booting — the bridge's own guards
    // make redundant invocations no-ops).
    ($app->bootedCallbacks[0])();
    expect($spy->bootCalls)->toBe(1);
    expect($app->bootedCallbacks)->toHaveCount(2);

    ($app->bootedCallbacks[1])();
    expect($spy->bootCalls)->toBe(2);
});

it('does not mark itself booted while the webhook-manager binding is absent, so a retry can succeed', function (): void {
    Log::spy();

    // Force availability so boot() reaches the binding check even though the
    // webhook-manager addon is absent in this test environment.
    $bridge = new class extends WebhookManagerBridge
    {
        public static function available(): bool
        {
            return true;
        }
    };

    $events = app('events');

    // 'webhook-manager' is not bound → boot must be a silent no-op (no
    // half-registration attempts, no warnings) and must NOT set the guard.
    $bridge->boot($events);
    Log::shouldNotHaveReceived('warning');

    // Once the binding appears (as it does after the webhook-manager provider
    // boots), a retry proceeds into the registration loop.
    app()->instance('webhook-manager', new stdClass);
    $bridge->boot($events);
    Log::shouldHaveReceived('warning')->times(count(WebhookManagerBridge::TRIGGERS));
});

it('boots idempotently: a second boot never re-registers triggers or listeners', function (): void {
    Log::spy();

    // Force availability and bind a stand-in for the 'webhook-manager'
    // service so boot() enters its registration loop even though the addon
    // is absent in this test environment. Each registration attempt then
    // fails (facade class missing) and is logged as a warning — which makes
    // the warnings a precise probe: a second boot must add zero new
    // registration attempts.
    app()->instance('webhook-manager', new stdClass);

    $bridge = new class extends WebhookManagerBridge
    {
        public static function available(): bool
        {
            return true;
        }
    };

    $events = app('events');

    $bridge->boot($events);
    $bridge->boot($events);

    Log::shouldHaveReceived('warning')->times(count(WebhookManagerBridge::TRIGGERS));
    expect($events->hasListeners(LeadHubContactCreated::class))->toBeFalse();
});
