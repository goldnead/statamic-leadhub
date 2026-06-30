<?php

use Goldnead\Leadhub\Http\Controllers\Cp\CompanyController;
use Goldnead\Leadhub\Http\Controllers\Cp\ContactController;
use Goldnead\Leadhub\Http\Controllers\Cp\DashboardController;
use Goldnead\Leadhub\Http\Controllers\Cp\ExportController;
use Goldnead\Leadhub\Http\Controllers\Cp\PipelineController;
use Goldnead\Leadhub\Http\Controllers\Cp\TaskController;
use Goldnead\Leadhub\Http\Controllers\Cp\FollowupController;
use Goldnead\Leadhub\Http\Controllers\Cp\FormMappingController;
use Goldnead\Leadhub\Http\Controllers\Cp\NoteController;
use Goldnead\Leadhub\Http\Controllers\Cp\SettingsController;
use Goldnead\Leadhub\Http\Controllers\Cp\SyncLogController;
use Goldnead\Leadhub\Http\Controllers\Cp\TagController;
use Illuminate\Support\Facades\Route;

Route::prefix('leadhub')->name('leadhub.')->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Contacts
    Route::prefix('contacts')->name('contacts.')->group(function () {
        Route::get('/', [ContactController::class, 'index'])->name('index');
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

    // Companies (CRM-core, eloquent)
    Route::prefix('companies')->name('companies.')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->name('index');
        Route::get('/{company}', [CompanyController::class, 'show'])->name('show');
    });

    // Tasks (CRM-core, eloquent)
    Route::prefix('tasks')->name('tasks.')->group(function () {
        Route::get('/', [TaskController::class, 'index'])->name('index');
        Route::post('/{task}/complete', [TaskController::class, 'complete'])->name('complete');
    });

    // Pipelines / opportunities (CRM-core, eloquent)
    Route::prefix('pipelines')->name('pipelines.')->group(function () {
        Route::get('/', [PipelineController::class, 'board'])->name('board');
        Route::get('/{pipeline}', [PipelineController::class, 'board'])->name('board.show');
        Route::post('/opportunities/{opportunity}/move', [PipelineController::class, 'move'])->name('move');
    });

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');

    // CRM sync log
    Route::get('/sync-log', [SyncLogController::class, 'index'])->name('sync-log');

    // Export
    Route::post('/export', [ExportController::class, 'store'])->name('export');
});
