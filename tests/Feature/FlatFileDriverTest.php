<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\EventRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Contracts\Repositories\FormMappingRepository;
use Goldnead\Leadhub\Contracts\Repositories\NoteRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Goldnead\Leadhub\Models\Event as TimelineEvent;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileContactRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileEventRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFollowupRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFormMappingRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileNoteRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileTagRepository;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    // Use a temp dir for content + an in-memory disk for the index.
    $tmpDir = sys_get_temp_dir().'/leadhub-test-'.uniqid();
    File::makeDirectory($tmpDir, 0755, recursive: true);

    config(['leadhub.storage' => [
        'driver' => 'flat',
        'flat' => [
            'path' => $tmpDir,
            'index_disk' => 'leadhub-test',
            'index_path' => 'index',
        ],
    ]]);

    config(['filesystems.disks.leadhub-test' => [
        'driver' => 'local',
        'root' => $tmpDir.'/_index',
    ]]);

    // Re-bind repositories with flat-file driver.
    app()->bind(ContactRepository::class, FlatFileContactRepository::class);
    app()->bind(EventRepository::class, FlatFileEventRepository::class);
    app()->bind(NoteRepository::class, FlatFileNoteRepository::class);
    app()->bind(FollowupRepository::class, FlatFileFollowupRepository::class);
    app()->bind(TagRepository::class, FlatFileTagRepository::class);
    app()->bind(FormMappingRepository::class, FlatFileFormMappingRepository::class);

    $this->tmpDir = $tmpDir;
});

afterEach(function (): void {
    if (isset($this->tmpDir) && File::isDirectory($this->tmpDir)) {
        File::deleteDirectory($this->tmpDir);
    }
});

it('creates a contact via the flat-file driver and finds it by email', function (): void {
    $contacts = app(ContactRepository::class);

    $contact = $contacts->create([
        'email' => 'jane@example.com',
        'email_normalized' => 'jane@example.com',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'status' => 'new',
    ]);

    expect($contact->email)->toBe('jane@example.com');
    expect($contact->uuid)->not->toBeEmpty();

    $found = $contacts->findByEmailNormalized('jane@example.com');
    expect($found)->not->toBeNull();
    expect($found->first_name)->toBe('Jane');
});

it('returns null for unknown email', function (): void {
    $contacts = app(ContactRepository::class);

    expect($contacts->findByEmailNormalized('nobody@example.com'))->toBeNull();
});

it('paginates contacts with status filter', function (): void {
    $contacts = app(ContactRepository::class);

    $contacts->create(['email' => 'a@x.com', 'email_normalized' => 'a@x.com', 'status' => 'new']);
    $contacts->create(['email' => 'b@x.com', 'email_normalized' => 'b@x.com', 'status' => 'qualified']);
    $contacts->create(['email' => 'c@x.com', 'email_normalized' => 'c@x.com', 'status' => 'qualified']);

    $page = $contacts->paginate(['status' => 'qualified'], perPage: 10, page: 1);

    expect($page->total())->toBe(2);
});

it('counts new contacts since a given window', function (): void {
    $contacts = app(ContactRepository::class);

    $contacts->create([
        'email' => 'old@x.com',
        'email_normalized' => 'old@x.com',
        'created_at' => now()->subDays(30)->toIso8601String(),
    ]);
    $contacts->create([
        'email' => 'recent@x.com',
        'email_normalized' => 'recent@x.com',
        'created_at' => now()->subDays(2)->toIso8601String(),
    ]);

    expect($contacts->countNewSince(7))->toBe(1);
});

it('records an event and reads it back for a contact', function (): void {
    $contacts = app(ContactRepository::class);
    $events = app(EventRepository::class);

    $c = $contacts->create(['email' => 'x@x.com', 'email_normalized' => 'x@x.com']);

    $events->record($c, TimelineEvent::TYPE_CONTACT_CREATED, 'Created');
    $events->record($c, TimelineEvent::TYPE_NOTE_ADDED, 'Note 1');

    $page = $events->forContact($c);
    expect($page->total())->toBe(2);
});

it('attaches and detaches a tag', function (): void {
    $contacts = app(ContactRepository::class);
    $tags = app(TagRepository::class);

    $c = $contacts->create(['email' => 'tagger@x.com', 'email_normalized' => 'tagger@x.com']);
    $tag = $tags->findOrCreate('VIP');

    expect($tags->attach($c, $tag))->toBeTrue();
    expect($tags->isAttached($c, $tag))->toBeTrue();

    expect($tags->detach($c, $tag))->toBeTrue();
    expect($tags->isAttached($c, $tag))->toBeFalse();
});

it('sets and completes a follow-up', function (): void {
    $contacts = app(ContactRepository::class);
    $followups = app(FollowupRepository::class);

    $c = $contacts->create(['email' => 'fu@x.com', 'email_normalized' => 'fu@x.com']);
    $fu = $followups->create($c, now()->addDays(2), 'Send proposal');

    expect($fu->note)->toBe('Send proposal');
    expect($followups->activeForOne($c))->not->toBeNull();

    $completed = $followups->markCompleted($fu, 'user-1');
    expect($completed->completed_at)->not->toBeNull();
    expect($followups->activeForOne($c))->toBeNull();
});

it('replaces an existing active follow-up when set is called twice', function (): void {
    $contacts = app(ContactRepository::class);
    $followups = app(FollowupRepository::class);

    $c = $contacts->create(['email' => 'fu2@x.com', 'email_normalized' => 'fu2@x.com']);

    $followups->create($c, now()->addDays(1), 'First');
    $followups->removeActiveFor($c);
    $followups->create($c, now()->addDays(5), 'Second');

    $active = $followups->activeForOne($c);
    expect($active)->not->toBeNull();
    expect($active->note)->toBe('Second');
});

it('creates and updates a form mapping', function (): void {
    $mappings = app(FormMappingRepository::class);

    $m = $mappings->firstOrCreate('contact', ['enabled' => false, 'email_field' => 'email']);
    expect($m->form_handle)->toBe('contact');
    expect($m->enabled)->toBeFalse();

    $updated = $mappings->update($m, ['enabled' => true]);
    expect($updated->enabled)->toBeTrue();

    expect($mappings->anyEnabled())->toBeTrue();
});
