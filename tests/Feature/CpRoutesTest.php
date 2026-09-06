<?php

/**
 * End-to-end smoke test for the v0.3 Inertia CP layer.
 *
 * For each LeadHub page, hits the route as an authenticated super user and
 * asserts:
 *   - The response is HTTP 200 (not 404, not 500).
 *   - It is an Inertia response (X-Inertia: true header).
 *   - The Inertia component identifier matches the expected Vue page.
 *   - The response body does not leak raw `leadhub::` translation keys.
 *
 * This is the test class that would have caught the user-reported
 * "control panel doesn't work" regressions in v0.2.x.
 */

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\TagRepository;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()
        ->email('test@example.com')
        ->makeSuper();
    $this->user->save();

    $this->actingAs($this->user);
});

function inertiaComponent($response): ?string
{
    $header = $response->headers->get('X-Inertia');
    if (! $header) {
        return null;
    }
    $payload = json_decode($response->getContent(), true);

    return $payload['component'] ?? null;
}

it('renders the dashboard', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.dashboard'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Dashboard');
    expect($response->getContent())->not->toContain('leadhub::nav.');
});

it('renders the contacts index', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.contacts.index'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Contacts/Index');
});

it('renders the contact detail page', function (): void {
    $repo = app(ContactRepository::class);
    $contact = $repo->create([
        'email' => 'detail@example.com',
        'email_normalized' => 'detail@example.com',
        'first_name' => 'Detail',
        'status' => 'new',
    ]);

    // Sanity: the same repo can find the contact we just created.
    expect($repo->find($contact->uuid))
        ->not->toBeNull("Repo can't find contact uuid={$contact->uuid} immediately after create");

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.contacts.show', $contact->uuid));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Contacts/Show');
});

it('updates a contact status and syncs tags without error', function (): void {
    $repo = app(ContactRepository::class);
    $tags = app(TagRepository::class);

    $contact = $repo->create([
        'email' => 'update@example.com',
        'email_normalized' => 'update@example.com',
        'first_name' => 'Update',
        'status' => 'new',
    ]);
    $tag = $tags->create(['name' => 'Hot']);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->patch(cp_route('leadhub.contacts.update', $contact->uuid), [
            'status' => 'qualified',
            'tag_ids' => [$tag->id],
        ]);

    // A successful update redirects back — it must not 500 (tag_ids is not a
    // column on the contact; it is synced to the tag relation).
    expect($response->getStatusCode())->toBeIn([200, 302, 303]);

    $fresh = $repo->find($contact->uuid);
    expect($fresh->status)->toBe('qualified');
    expect($tags->forContact($fresh)->pluck('name')->all())->toContain('Hot');
});

it('renders the followups index', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.followups.index'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Followups/Index');
});

it('renders the form mappings index', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.forms.index'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Forms/Index');
});

it('renders the tags index', function (): void {
    // Same driver-aware creation as in the contact detail test.
    $tags = app(TagRepository::class);
    $tags->create(['name' => 'Lead']);
    $tags->create(['name' => 'VIP']);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.tags.index'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Tags/Index');
});

it('sends the old settings URL to the suite settings screen', function (): void {
    // There is no `leadhub::Settings` component any more — the editable settings
    // are the shared screen in brand-context. The URL stays as a redirect because
    // it is in bookmarks and in this addon's documentation.
    $this->get(cp_route('leadhub.settings'))
        ->assertRedirect(cp_route('brand-context.settings.index'));
});

it('redirects unauthenticated users away from the dashboard', function (): void {
    auth()->logout();

    $response = $this->get(cp_route('leadhub.dashboard'));

    expect($response->getStatusCode())->toBeIn([302, 401, 403]);
});

it('blocks users without view-leadhub permission', function (): void {
    $regular = User::make()
        ->email('regular@example.com');
    $regular->save();

    $response = $this->actingAs($regular)
        ->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.dashboard'));

    expect($response->getStatusCode())->toBeIn([302, 401, 403]);
});

it('assigns an owner to a contact via the update route', function (): void {
    $repo = app(ContactRepository::class);
    $owner = User::make()->email('rep2@example.com')->makeSuper();
    $owner->save();

    $contact = $repo->create([
        'email' => 'assign@example.com',
        'email_normalized' => 'assign@example.com',
        'first_name' => 'Assign',
        'status' => 'new',
    ]);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->patch(cp_route('leadhub.contacts.update', $contact->uuid), [
            'assigned_to' => (string) $owner->id(),
        ]);

    expect($response->getStatusCode())->toBeIn([200, 302, 303]);
    expect($repo->find($contact->uuid)->assigned_to)->toBe((string) $owner->id());
});

it('renders the sync log page', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.sync-log'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::SyncLog');
});
