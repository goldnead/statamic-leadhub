<?php

/**
 * Manual "create contact" flow (feature: manual_contacts).
 *
 * Covers the create page render, the store endpoint (persist + redirect),
 * validation (needs at least an email or a phone), and the feature-flag gate.
 */

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('creator@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);
});

function createInertiaComponent($response): ?string
{
    if (! $response->headers->get('X-Inertia')) {
        return null;
    }

    return json_decode($response->getContent(), true)['component'] ?? null;
}

it('renders the create contact page', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.contacts.create'));

    $response->assertStatus(200);
    expect(createInertiaComponent($response))->toBe('leadhub::Contacts/Create');
});

it('creates a contact from valid input and redirects to its detail page', function (): void {
    $response = $this->post(cp_route('leadhub.contacts.store'), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'status' => 'new',
    ]);

    $contact = app(ContactRepository::class)->findByEmailNormalized(
        \Goldnead\Leadhub\Support\EmailNormalizer::normalize('jane@example.com')
    );

    expect($contact)->not->toBeNull()
        ->and($contact->first_name)->toBe('Jane')
        ->and($contact->email)->toBe('jane@example.com');

    $response->assertRedirect(cp_route('leadhub.contacts.show', $contact->uuid));
});

it('accepts a phone-only contact (no email)', function (): void {
    $this->post(cp_route('leadhub.contacts.store'), [
        'first_name' => 'Phone',
        'phone' => '+49 151 12345678',
    ])->assertRedirect();

    expect(app(ContactRepository::class)->paginate([], 25, 1)->total())->toBe(1);
});

it('rejects a contact with neither email nor phone', function (): void {
    $this->from(cp_route('leadhub.contacts.create'))
        ->post(cp_route('leadhub.contacts.store'), [
            'first_name' => 'Ghost',
        ])
        ->assertSessionHasErrors('email');

    expect(app(ContactRepository::class)->paginate([], 25, 1)->total())->toBe(0);
});

it('returns 404 for create + store when the manual_contacts feature is off', function (): void {
    config()->set('leadhub.features.manual_contacts', false);

    $this->get(cp_route('leadhub.contacts.create'))->assertStatus(404);
    $this->post(cp_route('leadhub.contacts.store'), [
        'email' => 'blocked@example.com',
    ])->assertStatus(404);
});
