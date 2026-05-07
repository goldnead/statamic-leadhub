<?php

use Goldnead\Leadhub\Support\EmailNormalizer;

it('trims and lowercases an email', function (): void {
    expect(EmailNormalizer::normalize('  Foo@EXAMPLE.com  '))
        ->toBe('foo@example.com');
});

it('returns null for null input', function (): void {
    expect(EmailNormalizer::normalize(null))->toBeNull();
});

it('returns null for an empty string after trimming', function (): void {
    expect(EmailNormalizer::normalize('   '))->toBeNull();
});

it('preserves the local-part dots in business addresses', function (): void {
    expect(EmailNormalizer::normalize('First.Last@company.com'))
        ->toBe('first.last@company.com');
});

it('validates emails with filter_var', function (): void {
    expect(EmailNormalizer::isValid('foo@example.com'))->toBeTrue();
    expect(EmailNormalizer::isValid('not-an-email'))->toBeFalse();
    expect(EmailNormalizer::isValid(null))->toBeFalse();
});
