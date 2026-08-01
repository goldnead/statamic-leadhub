<?php

use Goldnead\EmailTemplates\Facades\EmailTemplates as EtFacade;
use Goldnead\Leadhub\Facades\LeadHub;

// LeadHub no longer ships its own email-template subsystem. Email templates are
// owned by the standalone goldnead/statamic-email-templates addon (the shared
// `et_templates` collection). LeadHub::resolveEmailTemplate() is now a thin
// seam that delegates to that addon's public facade. These tests pin that
// delegation using a stand-in facade (see tests/Fixtures/EmailTemplatesAddonStub.php),
// since the real addon is an optional, non-composer dependency.

require_once __DIR__.'/../Fixtures/EmailTemplatesAddonStub.php';

afterEach(function () {
    EtFacade::$handler = null;
});

/** A tiny resolved-template double: whatever the addon returns, we call toArray(). */
function fakeResolvedTemplate(array $data): object
{
    return new class($data)
    {
        public function __construct(private array $data) {}

        public function toArray(): array
        {
            return $this->data;
        }
    };
}

it('resolves an email template through the et_templates addon facade', function () {
    EtFacade::$handler = fn (string $slug) => fakeResolvedTemplate([
        'slug' => $slug,
        'title' => 'Welcome',
        'subject' => 'Hi',
        'body' => 'VIA ET ADDON',
        'source' => 'entry',
    ]);

    $result = LeadHub::resolveEmailTemplate('welcome');

    expect($result)->toBeArray()
        ->and($result['slug'])->toBe('welcome')
        ->and($result['body'])->toBe('VIA ET ADDON')
        ->and($result['source'])->toBe('entry');
});

it('passes the slug and fallback through to the addon resolver', function () {
    $captured = [];

    EtFacade::$handler = function (string $slug, ?callable $fallback) use (&$captured) {
        $captured = ['slug' => $slug, 'fallback' => $fallback];

        return null;
    };

    $fallback = fn () => ['title' => 'File', 'body' => 'FILE BODY'];

    LeadHub::resolveEmailTemplate('reminder', $fallback);

    expect($captured['slug'])->toBe('reminder')
        ->and($captured['fallback'])->toBe($fallback);
});

it('returns null when the addon resolves no template', function () {
    EtFacade::$handler = fn () => null;

    expect(LeadHub::resolveEmailTemplate('nope'))->toBeNull();
});
