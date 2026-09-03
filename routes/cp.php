<?php

use Goldnead\Leadhub\Http\Controllers\Cp\AccessGrantController;
use Goldnead\Leadhub\Http\Controllers\Cp\CompanyController;
use Goldnead\Leadhub\Http\Controllers\Cp\ContactController;
use Goldnead\Leadhub\Http\Controllers\Cp\CustomFieldController;
use Goldnead\Leadhub\Http\Controllers\Cp\DashboardController;
use Goldnead\Leadhub\Http\Controllers\Cp\ExportController;
use Goldnead\Leadhub\Http\Controllers\Cp\FollowupController;
use Goldnead\Leadhub\Http\Controllers\Cp\FormMappingController;
use Goldnead\Leadhub\Http\Controllers\Cp\NoteController;
use Goldnead\Leadhub\Http\Controllers\Cp\OpportunityController;
use Goldnead\Leadhub\Http\Controllers\Cp\PipelineController;
use Goldnead\Leadhub\Http\Controllers\Cp\ScoringController;
use Goldnead\Leadhub\Http\Controllers\Cp\SegmentController;
use Goldnead\Leadhub\Http\Controllers\Cp\SettingsController;
use Goldnead\Leadhub\Http\Controllers\Cp\SyncLogController;
use Goldnead\Leadhub\Http\Controllers\Cp\TagController;
use Goldnead\Leadhub\Http\Controllers\Cp\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('leadhub')->name('leadhub.')->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Contacts
    Route::prefix('contacts')->name('contacts.')->group(function () {
        Route::get('/', [ContactController::class, 'index'])->name('index');
        // `create` and `options` must be registered before the `/{contact}`
        // wildcard so they aren't swallowed as a contact id.
        Route::get('/create', [ContactController::class, 'create'])->name('create');
        // Option feed for the contact pickers on the task and opportunity
        // forms. Brand-scoped through the repository like every other read.
        Route::get('/options', [ContactController::class, 'options'])->name('options');
        Route::post('/', [ContactController::class, 'store'])->name('store');
        Route::get('/{contact}', [ContactController::class, 'show'])->name('show');
        Route::patch('/{contact}', [ContactController::class, 'update'])->name('update');
        Route::delete('/{contact}', [ContactController::class, 'destroy'])->name('destroy');
        Route::post('/{contact}/archive', [ContactController::class, 'archive'])->name('archive');
        Route::post('/{contact}/restore', [ContactController::class, 'restore'])->name('restore');

        // Linking a company to this contact. The pivot has existed since
        // v1.0; until now nothing in the CP could write to it, so the
        // "Linked companies" panel could only ever show what an import or a
        // sibling addon had put there.
        Route::post('/{contact}/companies', [ContactController::class, 'attachCompany'])
            ->whereNumber('contact')->name('companies.attach');
        Route::delete('/{contact}/companies/{company}', [ContactController::class, 'detachCompany'])
            ->whereNumber('contact')->whereNumber('company')->name('companies.detach');

        Route::post('/{contact}/notes', [NoteController::class, 'store'])->name('notes.store');
        Route::post('/{contact}/followup', [FollowupController::class, 'store'])->name('followup.store');
        // "Grant access" from the contact screen. Writes through the
        // entitlements facade; 404 when that addon is not installed.
        Route::post('/{contact}/grant-access', [AccessGrantController::class, 'store'])->name('grant-access');
    });

    // Follow-ups
    Route::prefix('followups')->name('followups.')->group(function () {
        Route::get('/', [FollowupController::class, 'index'])->name('index');
        Route::patch('/{followup}', [FollowupController::class, 'update'])->name('update');
        Route::post('/{followup}/complete', [FollowupController::class, 'complete'])->name('complete');
        Route::delete('/{followup}', [FollowupController::class, 'destroy'])->name('destroy');
    });

    // Form mappings
    Route::prefix('forms')->name('forms.')->group(function () {
        Route::get('/', [FormMappingController::class, 'index'])->name('index');
        Route::get('/{formHandle}', [FormMappingController::class, 'edit'])->name('edit');
        Route::patch('/{formHandle}', [FormMappingController::class, 'update'])->name('update');
    });

    // Tags
    Route::prefix('tags')->name('tags.')->group(function () {
        Route::get('/', [TagController::class, 'index'])->name('index');
        Route::post('/', [TagController::class, 'store'])->name('store');
        Route::patch('/{tag}', [TagController::class, 'update'])->name('update');
        Route::delete('/{tag}', [TagController::class, 'destroy'])->name('destroy');
    });

    // Custom fields (eloquent only)
    //
    // Registered unconditionally, and that is the whole point of 2.7.1. The
    // navigation entry for this screen appears only on the eloquent driver,
    // and `NavItem->route()` resolves through `cp_route()` at the moment the
    // navigation is built — which happens on EVERY Control Panel page, not
    // just this one. A route that exists under a narrower condition than its
    // navigation entry does not hide a screen, it throws
    // RouteNotFoundException while the nav is assembled and answers the whole
    // CP with a 500.
    //
    // The driver gate belongs where every other one in this addon sits: in the
    // controller, as `abortUnlessEloquent()`. A route that resolves and answers
    // 404 is harmless; a route name that does not resolve is not.
    //
    // `route:cache` is the second reason and the one that would bring this
    // back. A conditional route freezes the driver as it stood when the cache
    // was written, while the navigation is evaluated per request. Cache on
    // eloquent, switch to flat, and the entry disappears with the route still
    // there — harmless. Cache on flat and switch to eloquent, and the entry
    // returns to a route the cache does not contain, which is this outage
    // again, on an installation that changed nothing but a config value.
    //
    // `customField`, not `field`: a route parameter name is an
    // application-wide namespace, and any sibling addon's `Route::bind('field')`
    // would capture these two writes (see the note on the scoring group below).
    Route::prefix('custom-fields')->name('custom-fields.')->group(function () {
        Route::get('/', [CustomFieldController::class, 'index'])->name('index');
        Route::post('/', [CustomFieldController::class, 'store'])->name('store');
        Route::patch('/{customField}', [CustomFieldController::class, 'update'])
            ->whereNumber('customField')->name('update');
        Route::delete('/{customField}', [CustomFieldController::class, 'destroy'])
            ->whereNumber('customField')->name('destroy');
    });

    // Segments
    Route::prefix('segments')->name('segments.')->group(function () {
        Route::get('/', [SegmentController::class, 'index'])->name('index');
        Route::get('/create', [SegmentController::class, 'create'])->name('create');
        Route::post('/', [SegmentController::class, 'store'])->name('store');
        // A GET, and deliberately so. The rule builder's live member count
        // reads; it writes nothing. As a POST the Vue side had to reach for
        // axios, which bypasses Inertia's progress bar, flash toasts and
        // dirty-state guard — and `router.post()` was no alternative, because
        // this endpoint answers JSON, not an Inertia page. A read on GET is
        // both the honest verb and the one that stays out of Inertia's way.
        Route::get('/preview', [SegmentController::class, 'preview'])->name('preview');
        Route::get('/{segment}', [SegmentController::class, 'edit'])->name('edit');
        Route::patch('/{segment}', [SegmentController::class, 'update'])->name('update');
        Route::delete('/{segment}', [SegmentController::class, 'destroy'])->name('destroy');
    });

    // Companies (CRM-core, eloquent)
    Route::prefix('companies')->name('companies.')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->name('index');
        // `create` before the `/{company}` wildcard, or it is read as an id.
        Route::get('/create', [CompanyController::class, 'create'])->name('create');
        // Option feed for the company picker on the contact screen. Before
        // the `/{company}` wildcard, same reason as `create`.
        Route::get('/options', [CompanyController::class, 'options'])->name('options');
        Route::post('/', [CompanyController::class, 'store'])->name('store');
        Route::get('/{company}/edit', [CompanyController::class, 'edit'])->whereNumber('company')->name('edit');
        Route::get('/{company}', [CompanyController::class, 'show'])->whereNumber('company')->name('show');
        Route::patch('/{company}', [CompanyController::class, 'update'])->whereNumber('company')->name('update');
        Route::delete('/{company}', [CompanyController::class, 'destroy'])->whereNumber('company')->name('destroy');
    });

    // Tasks (CRM-core, eloquent)
    Route::prefix('tasks')->name('tasks.')->group(function () {
        Route::get('/', [TaskController::class, 'index'])->name('index');
        Route::get('/create', [TaskController::class, 'create'])->name('create');
        // Option feed for the opportunity picker on the task form. The
        // contact is a query parameter, not a route parameter, deliberately:
        // a route parameter named `contact` or `opportunity` is generic
        // enough for a sibling addon to have claimed with Route::bind(), and
        // that binding is application-wide (v1.8.1). A query string cannot be
        // captured that way. Registered before the `/{task}` wildcards.
        Route::get('/opportunity-options', [TaskController::class, 'opportunityOptions'])
            ->name('opportunityOptions');
        Route::post('/', [TaskController::class, 'store'])->name('store');
        Route::post('/{task}/complete', [TaskController::class, 'complete'])->whereNumber('task')->name('complete');
        Route::get('/{task}/edit', [TaskController::class, 'edit'])->whereNumber('task')->name('edit');
        Route::patch('/{task}', [TaskController::class, 'update'])->whereNumber('task')->name('update');
        Route::delete('/{task}', [TaskController::class, 'destroy'])->whereNumber('task')->name('destroy');
    });

    // Pipelines / opportunities (CRM-core, eloquent)
    Route::prefix('pipelines')->name('pipelines.')->group(function () {
        Route::get('/', [PipelineController::class, 'board'])->name('board');
        Route::get('/manage', [PipelineController::class, 'manage'])->name('manage');
        Route::post('/', [PipelineController::class, 'store'])->name('store');

        // Opportunity CRUD. Its own controller: PipelineController is already
        // the board, the management screen, stage editing and the move
        // endpoint. `create` is registered before the `{opportunity}` wildcard.
        Route::get('/opportunities/create', [OpportunityController::class, 'create'])
            ->name('opportunities.create');
        Route::post('/opportunities', [OpportunityController::class, 'store'])
            ->name('opportunities.store');
        Route::get('/opportunities/{opportunity}/edit', [OpportunityController::class, 'edit'])
            ->whereNumber('opportunity')->name('opportunities.edit');
        // The deal's own screen. Registered before the `/{pipeline}` board
        // route at the bottom of this group, and after `opportunities/create`
        // — `whereNumber` already keeps "create" out of this one, but the
        // ordering says so without relying on the constraint. The parameter
        // stays `opportunity`, the name every other route in this group uses:
        // a second name for the same thing is what a sibling addon's
        // application-wide `Route::bind()` eventually eats (see the comment on
        // the scoring group).
        Route::get('/opportunities/{opportunity}', [OpportunityController::class, 'show'])
            ->whereNumber('opportunity')->name('opportunities.show');
        Route::patch('/opportunities/{opportunity}', [OpportunityController::class, 'update'])
            ->whereNumber('opportunity')->name('opportunities.update');
        Route::delete('/opportunities/{opportunity}', [OpportunityController::class, 'destroy'])
            ->whereNumber('opportunity')->name('opportunities.destroy');

        Route::post('/opportunities/{opportunity}/move', [PipelineController::class, 'move'])->name('move');

        // Stage management. Registered before the `/{pipeline}` board route —
        // different verbs, but keeping them together makes the ordering
        // intentional rather than accidental.
        Route::post('/{pipeline}/stages', [PipelineController::class, 'storeStage'])
            ->whereNumber('pipeline')->name('stages.store');
        Route::post('/{pipeline}/stages/reorder', [PipelineController::class, 'reorderStages'])
            ->whereNumber('pipeline')->name('stages.reorder');
        Route::patch('/{pipeline}/stages/{stage}', [PipelineController::class, 'updateStage'])
            ->whereNumber('pipeline')->whereNumber('stage')->name('stages.update');
        Route::delete('/{pipeline}/stages/{stage}', [PipelineController::class, 'destroyStage'])
            ->whereNumber('pipeline')->whereNumber('stage')->name('stages.destroy');

        Route::get('/{pipeline}', [PipelineController::class, 'board'])->whereNumber('pipeline')->name('board.show');
    });

    // Lead scoring rules (eloquent, gated on features.scoring)
    //
    // The parameter is `scoringRule`, not `rule`, and that is not cosmetic.
    // Route::bind() is application-wide: goldnead/statamic-webhook-manager
    // registers a binding for `rule` (its own Rule model) in its provider, and
    // a binding applies to EVERY route with that parameter name in every
    // addon. With `{rule}` here, both write routes were resolved against the
    // webhook manager's rule repository and 404'd on a LeadHub id that
    // repository has never heard of — on installs that happen to have both
    // addons, and nowhere else. Parameter names are shared namespace; a
    // generic one is a collision waiting for a sibling.
    Route::prefix('scoring')->name('scoring.')->group(function () {
        Route::get('/', [ScoringController::class, 'index'])->name('index');
        Route::post('/', [ScoringController::class, 'store'])->name('store');
        Route::patch('/{scoringRule}', [ScoringController::class, 'update'])
            ->whereNumber('scoringRule')->name('update');
        Route::delete('/{scoringRule}', [ScoringController::class, 'destroy'])
            ->whereNumber('scoringRule')->name('destroy');
    });

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

    // CRM sync log
    Route::get('/sync-log', [SyncLogController::class, 'index'])->name('sync-log');
    // Data endpoint for the sync log's <Listing> in server mode.
    Route::get('/sync-log/data', [SyncLogController::class, 'data'])->name('sync-log.data');

    // Export
    Route::post('/export', [ExportController::class, 'store'])->name('export');
});
