<?php

namespace Goldnead\Leadhub;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Leadhub\Console\BrandIntegrityCommand;
use Goldnead\Leadhub\Console\FireDueFollowupsCommand;
use Goldnead\Leadhub\Console\ImportScoringRulesCommand;
use Goldnead\Leadhub\Console\MigrateFlatBrandsCommand;
use Goldnead\Leadhub\Console\SendFollowupDigestCommand;
use Goldnead\Leadhub\Console\StacheWarmCommand;
use Goldnead\Leadhub\Console\StorageMigrateCommand;
use Goldnead\Leadhub\Console\SweepSegmentsCommand;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Contracts\SenderIdentityResolver;
use Goldnead\Leadhub\Crm\DestinationManager;
use Goldnead\Leadhub\Events\LeadHubContactArchived;
use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubContactDeleted;
use Goldnead\Leadhub\Events\LeadHubContactEnteredSegment;
use Goldnead\Leadhub\Events\LeadHubContactLeftSegment;
use Goldnead\Leadhub\Events\LeadHubContactScoreChanged;
use Goldnead\Leadhub\Events\LeadHubContactsMerged;
use Goldnead\Leadhub\Events\LeadHubContactUpdated;
use Goldnead\Leadhub\Events\LeadHubEmailLinkClicked;
use Goldnead\Leadhub\Events\LeadHubFollowupCompleted;
use Goldnead\Leadhub\Events\LeadHubFollowupDue;
use Goldnead\Leadhub\Events\LeadHubFollowupSet;
use Goldnead\Leadhub\Events\LeadHubNoteAdded;
use Goldnead\Leadhub\Events\LeadHubSourceIngested;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Events\LeadHubSubmissionAttached;
use Goldnead\Leadhub\Events\LeadHubTagAdded;
use Goldnead\Leadhub\Events\LeadHubTagRemoved;
use Goldnead\Leadhub\Integrations\Entitlements\AccessGranter;
use Goldnead\Leadhub\Integrations\Insights\ContactsActive;
use Goldnead\Leadhub\Integrations\Insights\ContactsCreated;
use Goldnead\Leadhub\Integrations\Insights\OpportunitiesWon;
use Goldnead\Leadhub\Integrations\Insights\OpportunityValueWon;
use Goldnead\Leadhub\Integrations\Insights\ScoreChanges;
use Goldnead\Leadhub\Integrations\Insights\TasksCompleted;
use Goldnead\Leadhub\Integrations\Notifications\NotificationsBridge;
use Goldnead\Leadhub\Integrations\Timeline\BookingSource;
use Goldnead\Leadhub\Integrations\Timeline\ConsentSource;
use Goldnead\Leadhub\Integrations\Timeline\EntitlementsSource;
use Goldnead\Leadhub\Integrations\Timeline\PaymentsSource;
use Goldnead\Leadhub\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Leadhub\Listeners\CreateOrUpdateLeadFromSubmission;
use Goldnead\Leadhub\Listeners\DispatchCrmSync;
use Goldnead\Leadhub\Listeners\RecordScoreChangeOnTimeline;
use Goldnead\Leadhub\Listeners\ReevaluateSegmentMembership;
use Goldnead\Leadhub\Listeners\ScoreContactOnActivity;
use Goldnead\Leadhub\Listeners\SendNewLeadNotification;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Policies\LeadHubPolicy;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentContactRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentEventRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFollowupRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFormMappingRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentNoteRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentSegmentRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentTagRepository;
use Goldnead\Leadhub\Repositories\FlatFile\BrandSegments;
use Goldnead\Leadhub\Repositories\FlatFile\FileStore;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileContactRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileEventRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFollowupRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFormMappingRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileNoteRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileSegmentRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileTagRepository;
use Goldnead\Leadhub\Repositories\FlatFile\Index;
use Goldnead\Leadhub\Repositories\FlatFile\IndexBuilder;
use Goldnead\Leadhub\Repositories\FlatFile\ModelHydrator;
use Goldnead\Leadhub\Sending\BrandMailer;
use Goldnead\Leadhub\Sending\BrandSenderIdentity;
use Goldnead\Leadhub\Services\ClickTracking\ClickTrackingLinker;
use Goldnead\Leadhub\Services\ClickTracking\RecipientResolver;
use Goldnead\Leadhub\Services\IngestionService;
use Goldnead\Leadhub\Support\ContactPanels;
use Goldnead\Leadhub\Support\Settings;
use Goldnead\Leadhub\Support\Timeline\ContactTimeline;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [
        SubmissionCreated::class => [
            CreateOrUpdateLeadFromSubmission::class,
        ],
        LeadHubContactCreated::class => [
            SendNewLeadNotification::class,
            DispatchCrmSync::class,
            ReevaluateSegmentMembership::class,
        ],
        LeadHubContactUpdated::class => [
            DispatchCrmSync::class,
            ReevaluateSegmentMembership::class,
        ],
        LeadHubSubmissionAttached::class => [
            ScoreContactOnActivity::class,
        ],
        LeadHubSourceIngested::class => [
            ScoreContactOnActivity::class,
            ReevaluateSegmentMembership::class,
        ],
        LeadHubEmailLinkClicked::class => [
            ScoreContactOnActivity::class,
            ReevaluateSegmentMembership::class,
        ],
        LeadHubContactsMerged::class => [],
        // The score history. Until v1.8.0 this event fired into an empty room:
        // it was dispatched on every real change and nothing recorded it, so a
        // contact's score had a value and no past.
        LeadHubContactScoreChanged::class => [
            RecordScoreChangeOnTimeline::class,
        ],
        LeadHubStatusChanged::class => [
            DispatchCrmSync::class,
            ReevaluateSegmentMembership::class,
        ],
        LeadHubTagAdded::class => [
            ReevaluateSegmentMembership::class,
        ],
        LeadHubTagRemoved::class => [
            ReevaluateSegmentMembership::class,
        ],
        LeadHubNoteAdded::class => [],
        LeadHubContactEnteredSegment::class => [],
        LeadHubContactLeftSegment::class => [],
        LeadHubFollowupSet::class => [],
        LeadHubFollowupCompleted::class => [],
        LeadHubFollowupDue::class => [],
        LeadHubContactArchived::class => [],
        LeadHubContactDeleted::class => [],
    ];

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    // Translations are registered manually in registerTranslations() under the
    // exact `leadhub` namespace so __('leadhub::nav.dashboard') etc. resolve.
    protected $translations = false;

    protected $config = true;

    // Statamic 6 Vite entry points. The compiled assets are shipped with the
    // package under resources/dist/build/ (built via `npm run build` in this
    // package directory). On install Statamic publishes them from there to the
    // host's public/vendor/<package>/build/ and serves them in the CP.
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $commands = [
        StacheWarmCommand::class,
        StorageMigrateCommand::class,
        MigrateFlatBrandsCommand::class,
        SendFollowupDigestCommand::class,
        FireDueFollowupsCommand::class,
        SweepSegmentsCommand::class,
        ImportScoringRulesCommand::class,
        BrandIntegrityCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeAddonConfig();

        // Two layers, and they are not the same mechanism.
        //
        // `addNamespace` serves __('leadhub::nav.dashboard') — the PHP layer,
        // rendered server-side. `addJsonPath` serves __('Tasks') — Statamic's
        // *string* translations, which is what the Vue components call and
        // what reaches the browser through Statamic's JavascriptComposer. The
        // addon shipped only the first for eight releases, which is why a
        // German install had German navigation and English headings.
        //
        // Note that JSON strings from every package merge into one global
        // dictionary: a key here overrides that string across the whole
        // Control Panel, not only inside this addon. Which is why the addon's
        // en.json/de.json deliberately contain no key that Statamic itself
        // already translates, and TranslationParityTest fails if one appears.
        //
        // Register the leadhub:: translation namespace eagerly. Using
        // loadTranslationsFrom() alone is unreliable because Statamic's boot
        // sequence resolves the translator early — before our after-resolving
        // callback runs — so the namespace is silently never added.
        // Force-adding via the translator instance (and via after-resolving
        // for the case where it isn't resolved yet) covers both cases.
        $langPath = __DIR__.'/../resources/lang';

        $this->app->resolving('translator', function ($translator) use ($langPath) {
            $translator->addNamespace('leadhub', $langPath);
            $translator->addJsonPath($langPath);
        });

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('leadhub', $langPath);
            $this->app['translator']->addJsonPath($langPath);
        }

        $this->bindRepositories();

        // CRM destinations — singleton so other addons can extend() it.
        $this->app->singleton(DestinationManager::class);

        // Singleton so the bridge's boot guard holds across resolutions —
        // booting it twice must never double-register triggers/listeners.
        $this->app->singleton(WebhookManagerBridge::class);

        // Ingestion service + public manager facade target. Singletons so that
        // host-app source projectors registered at boot persist for the request.
        $this->app->singleton(IngestionService::class);
        $this->app->singleton(LeadHubManager::class);

        // Must be a singleton, or the registry a sibling addon registers into
        // at boot is not the registry the contact screen reads from later —
        // every panel would be silently dropped, with nothing to see but a page
        // that is missing something.
        $this->app->singleton(ContactPanels::class);

        // The merged timeline on the contact screen. One reader per sibling,
        // each guarded by class_exists inside — the siblings are suggested,
        // never required. A singleton for the same reason as ContactPanels: a
        // source a host registers at boot has to be the one the screen reads.
        $this->app->singleton(ContactTimeline::class, function ($app) {
            $timeline = new ContactTimeline($app->make(EventRepository::class));

            foreach ([
                PaymentsSource::class,
                EntitlementsSource::class,
                BookingSource::class,
                ConsentSource::class,
            ] as $source) {
                $timeline->register($app->make($source));
            }

            return $timeline;
        });
        $this->app->singleton(AccessGranter::class);

        // Click-tracking surface. Public singletons so sibling addons (the
        // automations / email-templates send path) can resolve the linker to
        // rewrite email links: app(ClickTrackingLinker::class).
        $this->app->singleton(ClickTrackingLinker::class);
        $this->app->singleton(RecipientResolver::class);

        // Who this package's mail goes out as. Bindable per addon on purpose:
        // a host may answer "who does internal staff post come from"
        // differently from marketing post to customers, and one shared binding
        // for every addon could not express that. The default reads
        // `brands.settings.mail`; a brand that declares nothing there resolves
        // the config identity, so a single-brand install is unaffected.
        $this->app->singleton(SenderIdentityResolver::class, BrandSenderIdentity::class);
        $this->app->singleton(BrandMailer::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Announce this addon's settings to the suite's shared screen.
        //
        // In boot(), not bootAddon(), and that is not a style choice.
        // brand-context applies the stored overrides from an `app->booted()`
        // callback, deliberately, so that every provider's boot() has had its
        // turn to register first. bootAddon() runs from an `app->booted()`
        // callback of its own (Statamic's AppServiceProvider), and which of
        // the two fires first depends on package load order — registering
        // there would mean LeadHub's settings reach the live config on some
        // installs and not on others, with nothing on screen to say which.
        app(SettingsRegistry::class)->register(Settings::class);

        // Must be queued from the provider's boot() — NOT from bootAddon().
        // Statamic runs bootAddon() inside an app->booted() callback, where
        // the application already reports "booted" and a nested
        // $this->app->booted() would fire immediately — i.e. still before a
        // sibling addon's bootAddon() has run. Queuing here, while the app is
        // still booting, guarantees the callback runs after ALL providers and
        // addons have booted.
        $this->registerWebhookManagerBridge();
        $this->registerNotificationTypes();
        $this->registerInsightsMetrics();
    }

    /**
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can store the class name without
     * constructing anything to find out what it is called. Naming the handle
     * twice is the price of that laziness, and it is the cheaper half of the
     * trade: an install with twenty addons would otherwise build every metric
     * object of every one of them on a request that renders none.
     *
     * The handles are frozen from the moment they are registered — they end up
     * in saved dashboards and in URLs. Renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        ContactsCreated::class => 'leadhub.contacts_created',
        ContactsActive::class => 'leadhub.contacts_active',
        OpportunitiesWon::class => 'leadhub.opportunities_won',
        OpportunityValueWon::class => 'leadhub.opportunity_value_won',
        TasksCompleted::class => 'leadhub.tasks_completed',
        ScoreChanges::class => 'leadhub.score_changes',
    ];

    /**
     * Offer the CRM figures to the analytics addon, if it is there.
     *
     * From an `app->booted()` callback rather than from `bootAddon()`: the
     * sibling's container bindings only exist once its own provider has booted,
     * and this one may boot first. Registering earlier registers into nothing,
     * silently — an empty screen with no error anywhere, which is the worst
     * shape this failure could take.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a few tiles on a screen nobody has open, never
     * a form submission. The guards are three, and each one answers a real
     * variation of "installed but not quite": the class may be absent, the
     * container may refuse to build the manager, and an older release of the
     * sibling may have the facade without this method on it.
     *
     * The metric classes name the sibling's contract in their `extends` and
     * their type hints, which is safe precisely because of the first guard: PHP
     * loads a class when something touches it, and nothing touches these unless
     * the facade exists. Hence `suggest` in composer.json rather than `require`
     * — installing LeadHub alone must not drag an analytics package in.
     *
     * Registering twice is harmless. The sibling keys its registry by handle,
     * and this addon deliberately queues a second `booted()` pass elsewhere for
     * the bridges that need one.
     */
    protected function registerInsightsMetrics(): void
    {
        $this->app->booted(function (): void {
            $facade = '\Goldnead\StatamicInsights\Facades\Insights';

            if (! class_exists($facade)) {
                return;
            }

            try {
                $manager = $facade::getFacadeRoot();

                // Asked of the object, never of the facade: a facade forwards
                // through `__callStatic` and declares none of what it forwards,
                // so the probe on the facade itself is always false.
                if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                    return;
                }

                foreach (self::INSIGHTS_METRICS as $class => $handle) {
                    $manager->registerMetric($class, $handle);
                }
            } catch (Throwable $e) {
                Log::warning('leadhub: the insights metrics could not be registered.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Register the task-assignment notification type and the open-task digest
     * source with goldnead/statamic-notifications. No-op when it is absent.
     *
     * This has to happen in a provider, and it has to happen in every process.
     * The notifications type registry is per process: a type registered where
     * the notification is produced is unknown to the scheduler, falls back to
     * the in_app default there and is silently dropped from the digest. Queued
     * behind app->booted() for the same reason as the webhook bridge — sibling
     * addon boot order is not guaranteed, and `notifications` may not be bound
     * yet when this provider boots.
     */
    protected function registerNotificationTypes(): self
    {
        $boot = fn () => $this->app->make(NotificationsBridge::class)->boot();

        $this->app->booted(function () use ($boot): void {
            $boot();
            $this->app->booted($boot);
        });

        return $this;
    }

    public function bootAddon(): void
    {
        $this
            ->registerMigrations()
            ->registerNavigation()
            ->registerPermissions()
            ->registerPolicies()
            ->registerSchedule()
            ->bootCommands()
            ->registerPublishables();

        // No `applySettingOverrides()` any more. brand-context pushes the
        // stored values onto the live config from its own `app->booted()`
        // callback, once, for every registered addon — which is after
        // `registerPublishables()` has merged `config/leadhub.php`, and so
        // still before anything reads a setting: the navigation reads config
        // from inside `Nav::extend()`, the schedule from inside
        // `callAfterResolving(Schedule::class)`, and a queue worker boots the
        // whole application before it takes a job.
        //
        // `registerMigrations()` is the one reader that runs too early to see
        // an override. It reads `storage.driver`, which is deliberately not an
        // editable setting.
    }

    /**
     * Wire LeadHub's lifecycle events into goldnead/statamic-webhook-manager
     * when that addon is installed. No-op otherwise.
     *
     * Deferred until all providers have booted: sibling addon boot order is
     * not guaranteed, and when LeadHub boots before webhook-manager the
     * 'webhook-manager' container binding does not exist yet, so every
     * trigger registration would fail. If the application is already booted,
     * Laravel runs the callback immediately, so behaviour in already-booted
     * contexts (e.g. tests) is unchanged.
     */
    protected function registerWebhookManagerBridge(): self
    {
        $boot = function (): void {
            $this->app->make(WebhookManagerBridge::class)
                ->boot($this->app->make('events'));
        };

        $this->app->booted(function () use ($boot): void {
            // First attempt. May still be too early: depending on package
            // discovery order this callback can fire before Statamic's own
            // booted callback has run the addons' bootAddon() methods, i.e.
            // before webhook-manager has bound its service. The bridge then
            // bails without marking itself booted.
            $boot();

            // Retry at the very end of the booted queue. Laravel appends
            // callbacks registered during the booted phase to the live queue
            // (and fires them once immediately), so this runs again after
            // every other provider/addon callback — including Statamic's,
            // which boots all addons. The bridge's guards make the extra
            // invocations no-ops.
            $this->app->booted($boot);
        });

        return $this;
    }

    /**
     * Schedule the daily follow-up digest (when notifications are enabled).
     */
    /**
     * Registers the scheduled commands exactly once.
     *
     * Not `app->booted()`: in a Statamic application those callbacks fire
     * twice, which this package already knew — the sibling bridges above are
     * queued through a deliberate double `booted()` and survive it because they
     * are idempotent. A schedule registration is not. Measured on a real
     * install: `registerSchedule()` is called once and the booted callback runs
     * twice, so `schedule:list` carried all three of these commands twice.
     *
     * It caused no damage, and only by accident: `onOneServer()` with a fixed
     * name means the second copy loses the mutex and is skipped. That is luck,
     * not design. The digest is the one that shows what the luck was worth — an
     * entry added later without `onOneServer()` would run twice, and that is
     * two follow-up digests to the same person on the same morning.
     *
     * `callAfterResolving()` binds to the Schedule singleton instead, so the
     * callback runs when it is resolved, once, no matter how often the
     * application announces that it has booted.
     */
    protected function registerSchedule(): self
    {
        $this->callAfterResolving(Schedule::class, function ($schedule) {
            $time = (string) config('leadhub.notifications.digest.time', '08:00');
            $schedule->command('leadhub:followups:digest')
                ->dailyAt($time)
                ->onOneServer()
                ->name('leadhub-followup-digest');

            // Fire LeadHubFollowupDue events for automations / webhooks.
            $schedule->command('leadhub:followups:due')
                ->dailyAt($time)
                ->onOneServer()
                ->name('leadhub-followups-due');

            // Re-materialize segment membership for time-based rules that the
            // reactive listener can't catch without a mutation to trigger it.
            $sweepTime = (string) config('leadhub.segments.sweep_time', '03:00');
            $schedule->command('leadhub:segments:sweep')
                ->dailyAt($sweepTime)
                ->onOneServer()
                ->name('leadhub-segments-sweep');
        });

        return $this;
    }

    protected function registerTranslations(): self
    {
        // Kept for backwards compatibility with the v0.2.2 method signature.
        // The actual loadTranslationsFrom now happens in register() so the
        // namespace is available everywhere before bootAddon() runs.
        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/leadhub'),
        ], 'leadhub-translations');

        return $this;
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
        $this->app->bind(EloquentSegmentRepository::class);

        // Flat-file driver: shared FileStore + per-entity Indexes.
        // One memo, one flush point: FileStore and every Index share it.
        $this->app->singleton(BrandSegments::class);

        $this->app->singleton(FileStore::class, function ($app) {
            return new FileStore(
                (string) config('leadhub.storage.flat.path', base_path('content/leadhub')),
                $app->make(BrandSegments::class),
            );
        });

        $this->app->singleton(IndexBuilder::class, function ($app) {
            return new IndexBuilder($app->make(FileStore::class));
        });

        $this->app->singleton(ModelHydrator::class, function ($app) {
            return new ModelHydrator;
        });

        $this->app->bind('leadhub.index.contacts', function ($app) {
            return new Index(
                'contacts',
                (string) config('leadhub.storage.flat.index_disk', 'local'),
                (string) config('leadhub.storage.flat.index_path', 'leadhub/index'),
                $app->make(BrandSegments::class),
            );
        });
        $this->app->bind('leadhub.index.tags', function ($app) {
            return new Index(
                'tags',
                (string) config('leadhub.storage.flat.index_disk', 'local'),
                (string) config('leadhub.storage.flat.index_path', 'leadhub/index'),
                $app->make(BrandSegments::class),
            );
        });
        $this->app->bind('leadhub.index.form_mappings', function ($app) {
            return new Index(
                'form_mappings',
                (string) config('leadhub.storage.flat.index_disk', 'local'),
                (string) config('leadhub.storage.flat.index_path', 'leadhub/index'),
                $app->make(BrandSegments::class),
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
        $this->app->singleton(FlatFileSegmentRepository::class, function ($app) {
            return new FlatFileSegmentRepository(
                $app->make(FileStore::class),
                $app->make(ModelHydrator::class),
            );
        });

        // Driver selection — resolved lazily on each `app()` call so that
        // config changes after `register()` (e.g. orchestra/testbench's
        // defineEnvironment hook, or runtime config changes) take effect.
        $bind = function (string $contract, string $eloquent, string $flat): void {
            $this->app->bind($contract, function ($app) use ($eloquent, $flat) {
                return config('leadhub.storage.driver', 'eloquent') === 'flat'
                    ? $app->make($flat)
                    : $app->make($eloquent);
            });
        };

        $bind(ContactRepository::class, EloquentContactRepository::class, FlatFileContactRepository::class);
        $bind(EventRepository::class, EloquentEventRepository::class, FlatFileEventRepository::class);
        $bind(NoteRepository::class, EloquentNoteRepository::class, FlatFileNoteRepository::class);
        $bind(FollowupRepository::class, EloquentFollowupRepository::class, FlatFileFollowupRepository::class);
        $bind(TagRepository::class, EloquentTagRepository::class, FlatFileTagRepository::class);
        $bind(FormMappingRepository::class, EloquentFormMappingRepository::class, FlatFileFormMappingRepository::class);
        $bind(SegmentRepository::class, EloquentSegmentRepository::class, FlatFileSegmentRepository::class);
    }

    protected function registerMigrations(): self
    {
        // Migrations only matter for the eloquent driver.
        if (config('leadhub.storage.driver', 'eloquent') === 'eloquent') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            return $this;
        }

        // Except this one. The flat driver keeps *lead data* in YAML, which is
        // what "migrations are not required" is about. A flat install running an
        // earlier release does have a `leadhub_settings` table — it was the one
        // migration registered here — and the rows in it have to reach
        // `brand_settings`, or every setting the operator changed silently
        // reverts to the config file on upgrade. Registered as a single file
        // rather than the directory, so `php artisan migrate` on a flat install
        // moves those rows and touches nothing else.
        //
        // The table's own create migration is deliberately no longer registered
        // here: brand-context owns the settings store now, and creating an empty
        // `leadhub_settings` on a fresh flat install would build a table nothing
        // reads. On an install that already ran it the migration stays recorded,
        // so nothing tries to create it twice.
        $this->loadMigrationsFrom(
            __DIR__.'/../database/migrations/2026_09_06_000001_move_leadhub_settings_to_brand_settings.php'
        );

        return $this;
    }

    protected function registerNavigation(): self
    {
        Nav::extend(function ($nav) {
            $items = [
                $nav->item(__('leadhub::nav.dashboard'))
                    ->route('leadhub.dashboard'),
                $nav->item(__('leadhub::nav.contacts'))
                    ->route('leadhub.contacts.index'),
            ];

            // CRM-core sections — only shown when their feature flag is on
            // (and, implicitly, the eloquent driver is in use).
            if (config('leadhub.features.pipelines', false)) {
                $items[] = $nav->item(__('leadhub::nav.pipelines'))
                    ->route('leadhub.pipelines.board');
            }
            if (config('leadhub.features.tasks', false)) {
                $items[] = $nav->item(__('leadhub::nav.tasks'))
                    ->route('leadhub.tasks.index');
            }
            if (config('leadhub.features.companies', false)) {
                $items[] = $nav->item(__('leadhub::nav.companies'))
                    ->route('leadhub.companies.index');
            }

            if (config('leadhub.features.scoring', false)
                && config('leadhub.storage.driver', 'eloquent') === 'eloquent') {
                $items[] = $nav->item(__('leadhub::nav.scoring'))
                    ->route('leadhub.scoring.index');
            }

            $items = array_merge($items, [
                $nav->item(__('leadhub::nav.followups'))
                    ->route('leadhub.followups.index'),
                $nav->item(__('leadhub::nav.forms'))
                    ->route('leadhub.forms.index'),
                $nav->item(__('leadhub::nav.tags'))
                    ->route('leadhub.tags.index'),
                // Eloquent only: the definitions are a table and the values a
                // JSON column. On the flat-file driver the screen would offer
                // to create something that has nowhere to go, which is worse
                // than not offering it.
                ...(config('leadhub.storage.driver', 'eloquent') === 'eloquent' ? [
                    $nav->item(__('leadhub::nav.custom_fields'))
                        ->route('leadhub.custom-fields.index'),
                ] : []),
                $nav->item(__('leadhub::nav.segments'))
                    ->route('leadhub.segments.index')
                    ->can('view leadhub segments'),
                // Points at the suite's shared settings screen, not at a
                // LeadHub route: the screen lives in brand-context now and
                // shows one section per addon. The entry stays under LeadHub
                // because that is where an operator looks for LeadHub's
                // settings, and it carries the permission the section is gated
                // on — the shared page hides sections the user may not manage,
                // so without the check this would be a link to a page with no
                // LeadHub on it.
                $nav->item(__('leadhub::nav.settings'))
                    ->route('brand-context.settings.index')
                    ->can('manage leadhub settings'),
                $nav->item(__('leadhub::nav.sync_log'))
                    ->route('leadhub.sync-log'),
            ]);

            $nav->create('LeadHub')
                ->section('Tools')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 5.5H20.5L14 13V17.5L10 19.5V13Z"/></svg>')
                ->route('leadhub.dashboard')
                ->can('view leadhub')
                ->children($items);
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
                                // Writes into a neighbour (entitlements), so
                                // its own authority: reading a contact and
                                // opening a paid course for them are not the
                                // same thing.
                                Permission::make('grant leadhub access')
                                    ->label(__('leadhub::permissions.grant_access')),
                            ]),
                        Permission::make('manage leadhub tags')
                            ->label(__('leadhub::permissions.manage_tags')),
                        Permission::make('view leadhub segments')
                            ->label(__('leadhub::permissions.view_segments'))
                            ->children([
                                Permission::make('manage leadhub segments')
                                    ->label(__('leadhub::permissions.manage_segments')),
                            ]),
                        // CRM-core write access. Separate from the contact
                        // permissions on purpose: "may edit a contact" and
                        // "may delete the company behind fifty contacts" are
                        // not the same authority, and the read side of these
                        // modules already rides on `view leadhub`.
                        Permission::make('manage leadhub companies')
                            ->label(__('leadhub::permissions.manage_companies')),
                        Permission::make('manage leadhub tasks')
                            ->label(__('leadhub::permissions.manage_tasks')),
                        Permission::make('manage leadhub opportunities')
                            ->label(__('leadhub::permissions.manage_opportunities')),
                        // Scoring rules are their own authority: the point
                        // table decides segment membership for every contact at
                        // once, which is a different blast radius from editing
                        // one record.
                        Permission::make('manage leadhub scoring')
                            ->label(__('leadhub::permissions.manage_scoring')),
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

        // The merge itself is in register(), not here. See mergeAddonConfig().

        return $this;
    }

    /**
     * Merge `config/leadhub.php` into the live config.
     *
     * In `register()`, where Laravel puts config merging, and not in
     * `bootAddon()` where it used to sit. brand-context takes its snapshot of
     * "what the config files say" the first time it applies stored settings,
     * from an `app->booted()` callback — and `bootAddon()` runs from an
     * `app->booted()` callback too, registered by Statamic's own provider.
     * Which of the two fires first depends on package order in
     * `vendor/composer/installed.json`. Lose that race and the snapshot is
     * taken before this file is merged: every packaged default reads as null,
     * so no saved value ever equals its default, and the store fills up with
     * rows pinning every field to the value it already had.
     *
     * The register phase is over before any booted callback runs, so there is
     * no race to lose.
     */
    protected function mergeAddonConfig(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/leadhub.php', 'leadhub');
    }
}
