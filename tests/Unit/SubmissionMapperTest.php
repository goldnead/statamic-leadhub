<?php

use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Services\SubmissionMapper;

beforeEach(function (): void {
    $this->mapper = new SubmissionMapper;
});

it('maps fields correctly using the configured field handles', function (): void {
    $mapping = FormMapping::factory()->create([
        'form_handle' => 'contact',
        'email_field' => 'email',
        'first_name_field' => 'first_name',
        'last_name_field' => 'last_name',
        'phone_field' => 'phone',
        'company_field' => 'company',
        'message_field' => 'message',
    ]);

    $data = [
        'email' => 'jane@example.com',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '+49 30 1234567',
        'company' => 'Acme GmbH',
        'message' => 'Hi there',
    ];

    $dto = $this->mapper->map($data, $mapping);

    expect($dto->email)->toBe('jane@example.com');
    expect($dto->firstName)->toBe('Jane');
    expect($dto->lastName)->toBe('Doe');
    expect($dto->fullName)->toBe('Jane Doe');
    expect($dto->phone)->toBe('+49 30 1234567');
    expect($dto->company)->toBe('Acme GmbH');
    expect($dto->message)->toBe('Hi there');
    expect($dto->sourceForm)->toBe('contact');
});

it('handles missing optional fields gracefully', function (): void {
    $mapping = FormMapping::factory()->create([
        'email_field' => 'email',
        'phone_field' => 'phone',
    ]);

    $dto = $this->mapper->map(['email' => 'foo@example.com'], $mapping);

    expect($dto->email)->toBe('foo@example.com');
    expect($dto->phone)->toBeNull();
    expect($dto->company)->toBeNull();
});

it('splits a full name when first and last fields are not mapped', function (): void {
    $mapping = FormMapping::factory()->create([
        'email_field' => 'email',
        'first_name_field' => null,
        'last_name_field' => null,
        'full_name_field' => 'name',
    ]);

    $dto = $this->mapper->map(['email' => 'foo@example.com', 'name' => 'Anna von Beispiel'], $mapping);

    expect($dto->firstName)->toBe('Anna');
    expect($dto->lastName)->toBe('von Beispiel');
    expect($dto->fullName)->toBe('Anna von Beispiel');
});

it('extracts tags from a comma-separated string', function (): void {
    $mapping = FormMapping::factory()->create([
        'email_field' => 'email',
        'tags_field' => 'topics',
    ]);

    $dto = $this->mapper->map(
        ['email' => 'foo@example.com', 'topics' => 'workshop, coaching, online'],
        $mapping
    );

    expect($dto->tags)->toEqualCanonicalizing(['workshop', 'coaching', 'online']);
});

it('extracts tags from an array field', function (): void {
    $mapping = FormMapping::factory()->create([
        'email_field' => 'email',
        'tags_field' => 'topics',
    ]);

    $dto = $this->mapper->map(
        ['email' => 'foo@example.com', 'topics' => ['workshop', 'coaching']],
        $mapping
    );

    expect($dto->tags)->toEqualCanonicalizing(['workshop', 'coaching']);
});

it('merges default tags with submission tags', function (): void {
    $mapping = FormMapping::factory()->create([
        'email_field' => 'email',
        'tags_field' => 'topics',
        'default_tags' => ['lead', 'website'],
    ]);

    $dto = $this->mapper->map(
        ['email' => 'foo@example.com', 'topics' => 'coaching'],
        $mapping
    );

    expect($dto->tags)->toEqualCanonicalizing(['lead', 'website', 'coaching']);
});

it('extracts consent as a boolean', function (): void {
    $mapping = FormMapping::factory()->create([
        'email_field' => 'email',
        'consent_field' => 'consent',
    ]);

    $dtoTrue = $this->mapper->map(['email' => 'foo@example.com', 'consent' => '1'], $mapping);
    $dtoFalse = $this->mapper->map(['email' => 'foo@example.com', 'consent' => null], $mapping);
    $dtoChecked = $this->mapper->map(['email' => 'foo@example.com', 'consent' => true], $mapping);

    expect($dtoTrue->consent)->toBeTrue();
    expect($dtoFalse->consent)->toBeFalse();
    expect($dtoChecked->consent)->toBeTrue();
});

it('returns email field as null when not set', function (): void {
    $mapping = FormMapping::factory()->create([
        'email_field' => 'email',
    ]);

    $dto = $this->mapper->map(['email' => ''], $mapping);

    expect($dto->hasEmail())->toBeFalse();
});
