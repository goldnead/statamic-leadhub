<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileContactRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileSegmentRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\User;

beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        $path = (string) config('leadhub.storage.flat.path');
        if ($path && is_dir($path)) {
            File::deleteDirectory($path);
        }
        try {
            Storage::disk((string) config('leadhub.storage.flat.index_disk', 'local'))
                ->deleteDirectory((string) config('leadhub.storage.flat.index_path', 'leadhub/index'));
        } catch (Throwable) {
        }
        foreach ([
            FlatFileContactRepository::class,
            FlatFileSegmentRepository::class,
            'leadhub.index.contacts',
        ] as $abstract) {
            app()->forgetInstance($abstract);
        }
    }

    $this->user = User::make()->email('seg-admin@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

it('renders the segments index', function (): void {
    app(SegmentRepository::class)->create([
        'name' => 'Buyers',
        'rules' => ['match' => 'all', 'conditions' => [['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified']]],
    ]);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.segments.index'));

    $response->assertStatus(200);
    $payload = json_decode($response->getContent(), true);
    expect($payload['component'] ?? null)->toBe('leadhub::Segments/Index');
    expect($response->getContent())->not->toContain('leadhub::segments.');
});

it('renders the segment create builder', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.segments.create'));

    $response->assertStatus(200);
    $payload = json_decode($response->getContent(), true);
    expect($payload['component'] ?? null)->toBe('leadhub::Segments/Edit');
});

it('stores a segment from the builder', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->post(cp_route('leadhub.segments.store'), [
            'name' => 'Engaged',
            'rules' => ['match' => 'any', 'conditions' => [['type' => 'tag', 'operator' => 'has', 'value' => 'vip']]],
        ]);

    expect($response->getStatusCode())->toBeIn([200, 302, 303]);
    expect(app(SegmentRepository::class)->findByHandle('engaged'))->not->toBeNull();
});

it('renders the segment edit builder for an existing segment', function (): void {
    $segment = app(SegmentRepository::class)->create([
        'name' => 'Editable',
        'rules' => ['match' => 'all', 'conditions' => []],
    ]);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.segments.edit', $segment->uuid));

    $response->assertStatus(200);
    $payload = json_decode($response->getContent(), true);
    expect($payload['component'] ?? null)->toBe('leadhub::Segments/Edit');
});

it('returns a live member-count preview', function (): void {
    app(ContactRepository::class)->create([
        'email' => 'prev@example.com', 'email_normalized' => 'prev@example.com', 'status' => 'qualified',
    ]);

    // A GET: the preview reads, it writes nothing. See routes/cp.php.
    $response = $this->get(cp_route('leadhub.segments.preview').'?'.http_build_query([
        'rules' => ['match' => 'all', 'conditions' => [['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified']]],
    ]));

    $response->assertStatus(200);
    expect($response->json('count'))->toBe(1);
});

it('refuses the preview without the manage-segments permission', function (): void {
    $regular = User::make()->email('seg-preview-nobody@example.com');
    $regular->save();

    $this->actingAs($regular)
        ->get(cp_route('leadhub.segments.preview'))
        ->assertStatus(403);
});

it('blocks users without view-segments permission', function (): void {
    $regular = User::make()->email('seg-regular@example.com');
    $regular->save();

    $response = $this->actingAs($regular)
        ->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.segments.index'));

    expect($response->getStatusCode())->toBeIn([302, 401, 403]);
});
