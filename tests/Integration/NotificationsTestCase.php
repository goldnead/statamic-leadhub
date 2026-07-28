<?php

namespace Goldnead\Leadhub\Tests\Integration;

use Goldnead\Leadhub\ServiceProvider;
use Goldnead\Leadhub\Tests\TestCase as BaseTestCase;

/**
 * Test case for the live LeadHub ↔ goldnead/statamic-notifications path.
 *
 * The base {@see BaseTestCase} deliberately runs with the notifications addon
 * ABSENT, which is what keeps the no-op contract in
 * {@see \Goldnead\Leadhub\Tests\Feature\TaskAssignmentNotificationTest} honest.
 * This case boots the real addon (and the identity contracts it resolves
 * recipients through) so the registration and the delivery can be exercised
 * against the genuine API rather than a stand-in.
 *
 * Only usable when the optional peer addon is installed; the test file skips
 * itself otherwise. Install it with scripts/test-notifications.sh.
 */
abstract class NotificationsTestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        $peers = array_values(array_filter([
            \Goldnead\IdentityContracts\ServiceProvider::class,
            \Goldnead\Notifications\ServiceProvider::class,
        ], 'class_exists'));

        if ($peers === []) {
            return $providers;
        }

        // Ahead of LeadHub's provider, so the `notifications` binding exists by
        // the time LeadHub's bridge registers its type and its digest source.
        array_splice($providers, array_search(ServiceProvider::class, $providers, true), 0, $peers);

        return $providers;
    }

    /**
     * The addon's own tables (notification_items, preferences, digest runs).
     * Registered while RefreshDatabase is migrating, exactly like the
     * webhook-manager case does it.
     */
    protected function defineDatabaseMigrations(): void
    {
        if (! class_exists(\Goldnead\Notifications\ServiceProvider::class)) {
            return;
        }

        $packageRoot = dirname(
            (new \ReflectionClass(\Goldnead\Notifications\ServiceProvider::class))->getFileName(),
            2,
        );

        $this->loadMigrationsFrom($packageRoot.'/database/migrations');
    }

    /**
     * Boot the notifications addon before LeadHub. Statamic runs bootAddon()
     * from a Statamic::booted() callback that testbench never fires, so both
     * sides are booted by hand here in the order production would boot them.
     */
    protected function bootAddons(): void
    {
        if (class_exists(\Goldnead\Notifications\ServiceProvider::class)) {
            $this->app->getProvider(\Goldnead\Notifications\ServiceProvider::class)?->bootAddon();
        }

        parent::bootAddons();
    }
}
