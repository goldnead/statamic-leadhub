<?php

namespace Goldnead\Leadhub\Crm;

use Goldnead\Leadhub\Contracts\CrmDestination;

/**
 * Builds the configured CRM destinations. Register additional drivers with
 * extend() (e.g. from another addon's service provider) to plug in Pipedrive,
 * ActiveCampaign, or a custom endpoint.
 */
class DestinationManager
{
    /** @var array<string, class-string<CrmDestination>> */
    protected array $drivers = [
        'webhook' => WebhookDestination::class,
        'hubspot' => HubSpotDestination::class,
        'brevo' => BrevoDestination::class,
    ];

    public function extend(string $driver, string $class): void
    {
        $this->drivers[$driver] = $class;
    }

    public function drivers(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * All enabled destinations, keyed by config key.
     *
     * @return array<string, CrmDestination>
     */
    public function enabled(): array
    {
        if (! config('leadhub.features.crm_destinations', false)) {
            return [];
        }

        $out = [];
        foreach ((array) config('leadhub.crm.destinations', []) as $key => $cfg) {
            if (empty($cfg['enabled'])) {
                continue;
            }
            $class = $this->drivers[$cfg['driver'] ?? ''] ?? null;
            if ($class) {
                $out[$key] = new $class((string) $key, (array) $cfg);
            }
        }

        return $out;
    }

    /**
     * Enabled destinations that listen for the given event.
     *
     * @return array<string, CrmDestination>
     */
    public function for(string $event): array
    {
        $destinations = $this->enabled();

        return array_filter($destinations, function (CrmDestination $d, string $key) use ($event) {
            $triggers = (array) config("leadhub.crm.destinations.{$key}.triggers", ['created', 'updated', 'status_changed']);

            return in_array($event, $triggers, true);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
