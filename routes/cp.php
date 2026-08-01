<?php

use Goldnead\Leadhub\Http\Controllers\Cp\CompanyController;
use Goldnead\Leadhub\Http\Controllers\Cp\ContactController;
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

        Route::post('/{contact}/notes', [NoteController::class, 'store'])->name('notes.store');
        Route::post('/{contact}/followup', [FollowupController::class, 'store'])->name('followup.store');
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

    // CRM sync log
    Route::get('/sync-log', [SyncLogController::class, 'index'])->name('sync-log');
    // Data endpoint for the sync log's <Listing> in server mode.
    Route::get('/sync-log/data', [SyncLogController::class, 'data'])->name('sync-log.data');

    // Export
    Route::post('/export', [ExportController::class, 'store'])->name('export');
});
