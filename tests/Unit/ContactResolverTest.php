<?php

use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubContactUpdated;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentContactRepository;
use Goldnead\Leadhub\Services\ContactResolver;
use Goldnead\Leadhub\Support\ContactDto;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->resolver = new ContactResolver(new EloquentContactRepository);
});

it('creates a new contact when no email match exists', function (): void {
    Event::fake([LeadHubContactCreated::class, LeadHubContactUpdated::class]);

    $dto = new ContactDto(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
        defaultStatus: 'new',
    );

    [$contact, $wasCreated] = $this->resolver->resolveOrCreate($dto);

    expect($wasCreated)->toBeTrue();
    expect($contact)->toBeInstanceOf(Contact::class);
    expect($contact->email)->toBe('jane@example.com');
    expect($contact->email_normalized)->toBe('jane@example.com');
    expect($contact->status)->toBe('new');

    Event::assertDispatched(LeadHubContactCreated::class);
});

it('finds an existing contact by normalized email', function (): void {
    Contact::factory()->create([
        'email' => 'jane@example.com',
        'email_normalized' => 'jane@example.com',
        'first_name' => 'Jane',
    ]);

    $dto = new ContactDto(
        email: '  Jane@EXAMPLE.com  ',
        firstName: 'Jane',
    );

    [$contact, $wasCreated] = $this->resolver->resolveOrCreate($dto);

    expect($wasCreated)->toBeFalse();
    expect(Contact::count())->toBe(1);
    expect($contact->email_normalized)->toBe('jane@example.com');
});

it('does not overwrite existing non-empty fields by default', function (): void {
    $contact = Contact::factory()->create([
        'email_normalized' => 'jane@example.com',
        'first_name' => 'Jane',
        'last_name' => null,            // empty — should be filled by the DTO
        'company' => 'Acme GmbH',
    ]);

    $dto = new ContactDto(
        email: 'jane@example.com',
        firstName: 'Janet',         // would overwrite 'Jane' but should not
        lastName: 'Doe',            // empty before — should fill
        company: 'NewCorp',         // would overwrite 'Acme' but should not
    );

    $this->resolver->resolveOrCreate($dto);

    $fresh = $contact->fresh();
    expect($fresh->first_name)->toBe('Jane');
    expect($fresh->company)->toBe('Acme GmbH');
    expect($fresh->last_name)->toBe('Doe');
});

it('updates last_activity_at on every resolve', function (): void {
    $contact = Contact::factory()->create([
        'email_normalized' => 'jane@example.com',
        'last_activity_at' => now()->subDays(10),
    ]);

    $dto = new ContactDto(email: 'jane@example.com');

    $this->resolver->resolveOrCreate($dto);

    expect($contact->fresh()->last_activity_at->isToday())->toBeTrue();
});

it('overrides existing fields when config flag is true', function (): void {
    config(['leadhub.overwrite_existing_fields_from_submissions' => true]);

    $contact = Contact::factory()->create([
        'email_normalized' => 'jane@example.com',
        'first_name' => 'Jane',
    ]);

    $dto = new ContactDto(
        email: 'jane@example.com',
        firstName: 'Janet',
    );

    $this->resolver->resolveOrCreate($dto);

    expect($contact->fresh()->first_name)->toBe('Janet');
});

it('dispatches LeadHubContactUpdated when fields actually change', function (): void {
    Event::fake([LeadHubContactCreated::class, LeadHubContactUpdated::class]);

    Contact::factory()->create([
        'email_normalized' => 'jane@example.com',
        'first_name' => 'Jane',
        'last_name' => null,
    ]);

    $dto = new ContactDto(
        email: 'jane@example.com',
        firstName: 'Jane',
        lastName: 'Doe',
    );

    $this->resolver->resolveOrCreate($dto);

    Event::assertDispatched(LeadHubContactUpdated::class);
});

it('throws when DTO has no email', function (): void {
    $dto = new ContactDto(email: null);

    expect(fn () => $this->resolver->resolveOrCreate($dto))
        ->toThrow(InvalidArgumentException::class);
});
