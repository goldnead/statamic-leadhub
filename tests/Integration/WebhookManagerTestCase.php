<?php

namespace Goldnead\Leadhub\Tests\Integration;

use Goldnead\Leadhub\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Leadhub\ServiceProvider;
use Goldnead\Leadhub\Tests\Feature\WebhookManagerBridgeTest;
use Goldnead\Leadhub\Tests\TestCase as BaseTestCase;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;

/**
 * Test case for the live LeadHub ↔ goldnead/statamic-webhook-manager path.
 *
 * Unlike the base {@see BaseTestCase} (which deliberately runs with the
 * webhook-manager addon ABSENT so the no-op contract in
 * {@see WebhookManagerBridgeTest} stays
 * honest), this case boots the real addon's service provider so the bridge's
 * registration + dispatch path can be exercised against the genuine
 * WebhookManager API.
 *
 * It is only usable when the optional peer addon is installed; the tests that
 * use it skip themselves otherwise (see the test file).
 */
abstract class WebhookManagerTestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        // Register the real webhook-manager provider ahead of LeadHub's, so its
        // registries/facade are bound before LeadHub boots its bridge.
        if (class_exists(WebhookManagerServiceProvider::class)) {
            array_splice(
                $providers,
                array_search(ServiceProvider::class, $providers, true),
                0,
                [WebhookManagerServiceProvider::class],
            );
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The bridge is gated on this flag; make sure it is on for the live path.
        $app['config']->set('leadhub.features.webhook_manager', true);
    }

    /**
     * Run the webhook-manager addon's migrations (webhook_outbounds,
     * webhook_deliveries, …) alongside LeadHub's, so the end-to-end delivery
     * path has tables to write to. Testbench calls this while RefreshDatabase
     * is migrating, which is the right moment to register the paths.
     */
    protected function defineDatabaseMigrations(): void
    {
        if (! class_exists(WebhookManagerServiceProvider::class)) {
            return;
        }

        $packageRoot = dirname(
            (new \ReflectionClass(WebhookManagerServiceProvider::class))->getFileName(),
            2,
        );

        $this->loadMigrationsFrom($packageRoot.'/database/migrations');
    }

    /**
     * Boot the webhook-manager addon BEFORE LeadHub, so its registries and the
     * `webhook-manager` facade binding exist when LeadHub's bridge registers
     * its triggers and listeners.
     */
    protected function bootAddons(): void
    {
        if (class_exists(WebhookManagerServiceProvider::class)) {
            $provider = $this->app->getProvider(WebhookManagerServiceProvider::class);

            if ($provider) {
                $provider->bootAddon();

                // Statamic's AddonServiceProvider registers its $listen map (incl.
                // TriggerDetected → DispatchTriggerListener, the heart of the
                // delivery pipeline) inside a Statamic::booted() callback that
                // testbench never fires. Wire those listeners up by hand.
                $bootEvents = new \ReflectionMethod($provider, 'bootEvents');
                $bootEvents->setAccessible(true);
                $bootEvents->invoke($provider);
            }
        }

        parent::bootAddons();

        $this->bootWebhookManagerBridge();
    }

    /**
     * Give LeadHub's webhook-manager bridge the boot attempt that production
     * gives it and testbench does not.
     *
     * ServiceProvider::registerWebhookManagerBridge() defers the bridge behind
     * `$this->app->booted()` twice over, because the `webhook-manager` binding
     * is created in the sibling's bootAddon() and sibling boot order is not
     * guaranteed. In a real application that works out: Statamic's
     * AppServiceProvider runs `Statamic::runBootedCallbacks()` — which is what
     * calls every addon's bootAddon() — from inside an `app->booted()` callback
     * of its own, and Laravel's fireAppCallbacks() walks that queue by index,
     * so the retry LeadHub appends *during* the booted phase runs after
     * Statamic has booted the addons. The binding exists by then.
     *
     * Testbench has no such phase. bootAddons() is called from setUp(), long
     * after the application finished booting, so both of the bridge's attempts
     * have already fired and — correctly — bailed on `! app()->bound(
     * 'webhook-manager')` without marking themselves booted. Nothing then tries
     * again, and the suite saw a webhook-manager with only its own seven core
     * triggers registered and none of LeadHub's eighteen.
     *
     * This is the missing retry, not a shortcut around one: the bridge's own
     * guards (feature flag, class_exists, bound-check, $booted latch) all still
     * run. Skipping it would leave the whole point of this suite untested.
     */
    protected function bootWebhookManagerBridge(): void
    {
        if (! class_exists(WebhookManagerServiceProvider::class)) {
            return;
        }

        $this->app->make(WebhookManagerBridge::class)
            ->boot($this->app->make('events'));
    }
}
