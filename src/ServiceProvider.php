<?php

namespace Goldnead\Leadhub;

use Goldnead\Leadhub\Events\LeadHubContactArchived;
use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubContactDeleted;
use Goldnead\Leadhub\Events\LeadHubContactUpdated;
use Goldnead\Leadhub\Events\LeadHubFollowupCompleted;
use Goldnead\Leadhub\Events\LeadHubFollowupSet;
use Goldnead\Leadhub\Events\LeadHubNoteAdded;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Events\LeadHubSubmissionAttached;
use Goldnead\Leadhub\Events\LeadHubTagAdded;
use Goldnead\Leadhub\Events\LeadHubTagRemoved;
use Goldnead\Leadhub\Listeners\CreateOrUpdateLeadFromSubmission;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Policies\LeadHubPolicy;
use Illuminate\Support\Facades\Gate;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    /**
     * Event => [Listener] mapping for the Statamic form submission flow
     * and our internal LeadHub events.
     */
    protected $listen = [
        SubmissionCreated::class => [
            CreateOrUpdateLeadFromSubmission::class,
        ],
        LeadHubContactCreated::class => [],
        LeadHubContactUpdated::class => [],
        LeadHubSubmissionAttached::class => [],
        LeadHubStatusChanged::class => [],
        LeadHubTagAdded::class => [],
        LeadHubTagRemoved::class => [],
        LeadHubNoteAdded::class => [],
        LeadHubFollowupSet::class => [],
        LeadHubFollowupCompleted::class => [],
        LeadHubContactArchived::class => [],
        LeadHubContactDeleted::class => [],
    ];

    /**
     * Routes are loaded by Statamic when the addon boots. The 'cp' key
     * registers routes under the /cp prefix with the statamic.cp middleware.
     */
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    /**
     * View namespace — referenced as `leadhub::dashboard` etc.
     */
    protected $viewNamespace = 'leadhub';

    /**
     * Translations (resources/lang/{en,de}/) are auto-loaded.
     */
    protected $translations = true;

    /**
     * The config file is auto-published when running
     * `php artisan vendor:publish --tag=leadhub-config`.
     */
    protected $config = true;

    public function bootAddon(): void
    {
        $this
            ->registerMigrations()
            ->registerNavigation()
            ->registerPermissions()
            ->registerPolicies()
            ->registerPublishables();
    }

    protected function registerMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    protected function registerNavigation(): self
    {
        Nav::extend(function ($nav) {
            $nav->create('LeadHub')
                ->section('Tools')
                ->icon('list')
                ->route('leadhub.dashboard')
                ->can('view leadhub')
                ->children([
                    $nav->item(__('leadhub::nav.dashboard'))
                        ->route('leadhub.dashboard'),
                    $nav->item(__('leadhub::nav.contacts'))
                        ->route('leadhub.contacts.index'),
                    $nav->item(__('leadhub::nav.followups'))
                        ->route('leadhub.followups.index'),
                    $nav->item(__('leadhub::nav.forms'))
                        ->route('leadhub.forms.index'),
                    $nav->item(__('leadhub::nav.tags'))
                        ->route('leadhub.tags.index'),
                    $nav->item(__('leadhub::nav.settings'))
                        ->route('leadhub.settings'),
                ]);
        });

        return $this;
    }

    protected function registerPermissions(): self
    {
        Permission::extend(function () {
            Permission::group('leadhub', 'LeadHub', function () {
                Permission::register('view leadhub')
                    ->label(__('leadhub::permissions.view_leadhub'))
                    ->children([
                        Permission::make('view leadhub contacts')
                            ->label(__('leadhub::permissions.view_contacts'))
                            ->children([
                                Permission::make('create leadhub contacts')
                                    ->label(__('leadhub::permissions.create_contacts')),
                                Permission::make('edit leadhub contacts')
                                    ->label(__('leadhub::permissions.edit_contacts')),
                                Permission::make('delete leadhub contacts')
                                    ->label(__('leadhub::permissions.delete_contacts')),
                                Permission::make('archive leadhub contacts')
                                    ->label(__('leadhub::permissions.archive_contacts')),
                                Permission::make('export leadhub contacts')
                                    ->label(__('leadhub::permissions.export_contacts')),
                            ]),
                        Permission::make('manage leadhub tags')
                            ->label(__('leadhub::permissions.manage_tags')),
                        Permission::make('manage leadhub form mappings')
                            ->label(__('leadhub::permissions.manage_form_mappings')),
                        Permission::make('manage leadhub settings')
                            ->label(__('leadhub::permissions.manage_settings')),
                    ]);
            });
        });

        return $this;
    }

    protected function registerPolicies(): self
    {
        Gate::policy(Contact::class, LeadHubPolicy::class);

        return $this;
    }

    protected function registerPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../config/leadhub.php' => config_path('leadhub.php'),
        ], 'leadhub-config');

        $this->mergeConfigFrom(__DIR__.'/../config/leadhub.php', 'leadhub');

        return $this;
    }
}
