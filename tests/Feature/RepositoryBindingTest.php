<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentContactRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentEventRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFollowupRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFormMappingRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentNoteRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentTagRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileContactRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileEventRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFollowupRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFormMappingRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileNoteRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileTagRepository;

/**
 * These tests prove that the LEADHUB_DRIVER env var (set by the CI matrix)
 * actually affects the container bindings — not just config values.
 *
 * The expected concrete implementation is derived from the env var so the
 * SAME test runs in every matrix cell and exercises the binding logic
 * specific to that cell.
 */
it('binds the configured driver implementations for every repository contract', function (): void {
    $driver = env('LEADHUB_DRIVER', 'eloquent');

    $expected = $driver === 'flat'
        ? [
            ContactRepository::class => FlatFileContactRepository::class,
            EventRepository::class => FlatFileEventRepository::class,
            NoteRepository::class => FlatFileNoteRepository::class,
            FollowupRepository::class => FlatFileFollowupRepository::class,
            TagRepository::class => FlatFileTagRepository::class,
            FormMappingRepository::class => FlatFileFormMappingRepository::class,
        ]
        : [
            ContactRepository::class => EloquentContactRepository::class,
            EventRepository::class => EloquentEventRepository::class,
            NoteRepository::class => EloquentNoteRepository::class,
            FollowupRepository::class => EloquentFollowupRepository::class,
            TagRepository::class => EloquentTagRepository::class,
            FormMappingRepository::class => EloquentFormMappingRepository::class,
        ];

    foreach ($expected as $contract => $concrete) {
        $resolved = app($contract);
        expect($resolved)
            ->toBeInstanceOf($concrete);
    }
});

it('exposes the matrix-configured driver via config', function (): void {
    $driver = env('LEADHUB_DRIVER', 'eloquent');

    expect(config('leadhub.storage.driver'))->toBe($driver);
});
