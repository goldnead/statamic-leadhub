<?php

namespace Goldnead\Leadhub;

use Goldnead\Leadhub\Console\StacheWarmCommand;
use Goldnead\Leadhub\Console\StorageMigrateCommand;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
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
use Goldnead\Leadhub\Repositories\Eloquent\EloquentContactRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentEventRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFollowupRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFormMappingRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentNoteRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentTagRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FileStore;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileContactRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileEventRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFollowupRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFormMappingRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileNoteRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileTagRepository;
use Goldnead\Leadhub\Repositories\FlatFile\Index;
use Goldnead\Leadhub\Repositories\FlatFile\IndexBuilder;
use Goldnead\Leadhub\Repositories\FlatFile\ModelHydrator;
use Illuminate\Support\Facades\Gate;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
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

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $viewNamespace = 'leadhub';

    protected $translations = true;

    protected $config = true;

    protected $commands = [
        StacheWarmCommand::class,
        StorageMigrateCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->bindRepositories();
    }

    public function bootAddon(): void
    {
        $this
            ->registerMigrations()
            ->registerNavigation()
            ->registerPermissions()
            ->registerPolicies()
            ->registerPublishables();
    }

    /**
     * Bind repository interfaces to their concrete implementations
     * based on the configured storage driver.
     */
    protected function bindRepositories(): void
    {
        // Eloquent driver: bind directly. Always available — used as a fallback
        // and as the migration source/target.
        $this->app->bind(EloquentContactRepository::class);
        $this->app->bind(EloquentEventRepository::class);
        $this->app->bind(EloquentNoteRepository::class);
        $this->app->bind(EloquentFollowupRepository::class);
        $this->app->bind(EloquentTagRepository::class);
        $this->app->bind(EloquentFormMappingRepository::class);

        // Flat-file driver: shared FileStore + per-entity Indexes.
        $this->app->singleton(FileStore::class, function ($app) {
            return new FileStore((string) config('leadhub.storage.flat.path', base_path('content/leadhub')));
        });

        $this->app->singleton(IndexBuilder::class, function ($app) {
            return new IndexBuilder($app->make(FileStore::class));
        });

        $this->app->singleton(ModelHydrator::class, function ($app) {
            return new ModelHydrator();
        });

        $this->app->bind('leadhub.index.contacts', function ($app) {
            return new Index(
                'contacts',
                (string) config('leadhub.storage.flat.index_disk', 'local'),
                (string) config('leadhub.storage.flat.index_path', 'leadhub/index'),
            );
        });
        $this->app->bind('leadhub.index.tags', function ($app) {
            return new Index(
                'tags',
                (string) config('leadhub.storage.flat.index_disk', 'local'),
                (string) config('leadhub.storage.flat.index_path', 'leadhub/index'),
            );
        });
        $this->app->bind('leadhub.index.form_mappings', function ($app) {
            return new Index(
                'form_mappings',
                (string) config('leadhub.storage.flat.index_disk', 'local'),
                (string) config('leadhub.storage.flat.index_path', 'leadhub/index'),
            );
        });

        // Flat-file repositories — wired with their shared dependencies.
        $this->app->singleton(FlatFileTagRepository::class, function ($app) {
            return new FlatFileTagRepository(
                $app->make(FileStore::class),
                $app->make('leadhub.index.tags'),
                $app->make(IndexBuilder::class),
                $app->make(ModelHydrator::class),
            );
        });
        $this->app->singleton(FlatFileFollowupRepository::class, function ($app) {
            return new FlatFileFollowupRepository(
                $app->make(FileStore::class),
                $app->make('leadhub.index.contacts'),
                $app->make(IndexBuilder::class),
                $app->make(ModelHydrator::class),
            );
        });
        $this->app->singleton(FlatFileContactRepository::class, function ($app) {
            return new FlatFileContactRepository(
                $app->make(FileStore::class),
                $app->make('leadhub.index.contacts'),
                $app->make(IndexBuilder::class),
                $app->make(ModelHydrator::class),
                $app->make(FlatFileTagRepository::class),
                $app->make(FlatFileFollowupRepository::class),
            );
        });
        $this->app->singleton(FlatFileEventRepository::class, function ($app) {
            return new FlatFileEventRepository(
                $app->make(FileStore::class),
                $app->make(ModelHydrator::class),
            );
        });
        $this->app->singleton(FlatFileNoteRepository::class, function ($app) {
            return new FlatFileNoteRepository(
                $app->make(FileStore::class),
                $app->make(ModelHydrator::class),
            );
        });
        $this->app->singleton(FlatFileFormMappingRepository::class, function ($app) {
            return new FlatFileFormMappingRepository(
                $app->make(FileStore::class),
                $app->make('leadhub.index.form_mappings'),
                $app->make(IndexBuilder::class),
                $app->make(ModelHydrator::class),
            );
        });

        // Driver selection.
        $driver = config('leadhub.storage.driver', 'eloquent');

        if ($driver === 'flat') {
            $this->app->bind(ContactRepository::class, FlatFileContactRepository::class);
            $this->app->bind(EventRepository::class, FlatFileEventRepository::class);
            $this->app->bind(NoteRepository::class, FlatFileNoteRepository::class);
            $this->app->bind(FollowupRepository::class, FlatFileFollowupRepository::class);
            $this->app->bind(TagRepository::class, FlatFileTagRepository::class);
            $this->app->bind(FormMappingRepository::class, FlatFileFormMappingRepository::class);
        } else {
            $this->app->bind(ContactRepository::class, EloquentContactRepository::class);
            $this->app->bind(EventRepository::class, EloquentEventRepository::class);
            $this->app->bind(NoteRepository::class, EloquentNoteRepository::class);
            $this->app->bind(FollowupRepository::class, EloquentFollowupRepository::class);
            $this->app->bind(TagRepository::class, EloquentTagRepository::class);
            $this->app->bind(FormMappingRepository::class, EloquentFormMappingRepository::class);
        }
    }

    protected function registerMigrations(): self
    {
        // Migrations only matter for the eloquent driver.
        if (config('leadhub.storage.driver', 'eloquent') === 'eloquent') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

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
