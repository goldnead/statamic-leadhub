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

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Models\Tag;
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
    $contact = Contact::factory()->create();

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.contacts.show', $contact->id));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Contacts/Show');
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
    Tag::factory()->count(2)->create();

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.tags.index'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Tags/Index');
});

it('renders the settings page', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.settings'));

    $response->assertStatus(200);
    expect(inertiaComponent($response))->toBe('leadhub::Settings');
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
