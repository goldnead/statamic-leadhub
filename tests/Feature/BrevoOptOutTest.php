<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Services\CrmSyncService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Eloquent-targeted.');
    }
    config()->set('leadhub.features.crm_destinations', true);
    config()->set('leadhub.crm.destinations', [
        'brevo' => [
            'driver' => 'brevo',
            'enabled' => true,
            'api_key' => 'test-key',
            'list_id' => 7,
            'triggers' => ['created'],
        ],
    ]);
});

it('removes an opted-out contact from the Brevo list', function () {
    Http::fake(['api.brevo.com/*' => Http::response([], 201)]);

    $contact = LeadHub::create(['email' => 'optout@example.com', 'first_name' => 'Opt']);

    $result = LeadHub::optOut($contact['id']);

    expect($result['id'])->toBe($contact['id']);

    $model = app(ContactRepository::class)->find($contact['id']);
    expect((bool) $model->do_not_contact)->toBeTrue();

    Http::assertSent(fn ($req) => str_contains($req->url(), '/contacts/lists/7/contacts/remove')
        && in_array('optout@example.com', $req['emails'] ?? []));
});

it('does not push an opted-out contact even on a sync event', function () {
    Http::fake(['api.brevo.com/*' => Http::response([], 201)]);

    $contact = LeadHub::create(['email' => 'guard@example.com']);
    LeadHub::optOut($contact['id']);
    Http::fake(); // reset recorded requests
    Http::fake(['api.brevo.com/*' => Http::response([], 201)]);

    $model = app(ContactRepository::class)->find($contact['id']);
    $results = app(CrmSyncService::class)->syncContact($model, 'created');

    expect($results)->toBe([]);
});
