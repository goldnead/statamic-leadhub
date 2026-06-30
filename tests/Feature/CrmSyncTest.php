<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Jobs\SyncContactToCrmJob;
use Goldnead\Leadhub\Listeners\DispatchCrmSync;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\SyncLog;
use Goldnead\Leadhub\Services\CrmSyncService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('leadhub.features.crm_destinations', true);
    config()->set('leadhub.crm.destinations', [
        'zap' => [
            'driver' => 'webhook',
            'enabled' => true,
            'url' => 'https://hooks.test/abc',
            'secret' => 's3cret',
            'triggers' => ['created', 'status_changed'],
        ],
    ]);
});

function aContact(string $email = 'crm@example.com')
{
    return app(ContactRepository::class)->create([
        'email' => $email,
        'email_normalized' => $email,
        'first_name' => 'Crm',
        'status' => 'new',
    ]);
}

it('pushes a contact to a webhook destination and logs success', function (): void {
    Http::fake(['hooks.test/*' => Http::response(['ok' => true], 200)]);

    $results = app(CrmSyncService::class)->syncContact(aContact(), 'created');

    expect($results['zap']->success)->toBeTrue();
    Http::assertSent(fn ($req) => $req->url() === 'https://hooks.test/abc'
        && $req->hasHeader('X-LeadHub-Signature'));
    expect(SyncLog::where('destination', 'zap')->where('status', 'success')->exists())->toBeTrue();
    expect(Event::where('type', Event::TYPE_CRM_SYNC_SUCCEEDED)->exists())->toBeTrue();
});

it('logs a failure when the remote returns an error', function (): void {
    Http::fake(['hooks.test/*' => Http::response('boom', 500)]);

    $results = app(CrmSyncService::class)->syncContact(aContact('fail@example.com'), 'created');

    expect($results['zap']->success)->toBeFalse();
    expect(SyncLog::where('status', 'failed')->exists())->toBeTrue();
    expect(Event::where('type', Event::TYPE_CRM_SYNC_FAILED)->exists())->toBeTrue();
});

it('does not sync for events the destination ignores', function (): void {
    Http::fake();

    $results = app(CrmSyncService::class)->syncContact(aContact('skip@example.com'), 'updated');

    expect($results)->toBe([]);
    Http::assertNothingSent();
});

it('does nothing when the feature flag is off', function (): void {
    config()->set('leadhub.features.crm_destinations', false);
    Http::fake();

    $results = app(CrmSyncService::class)->syncContact(aContact('off@example.com'), 'created');

    expect($results)->toBe([]);
    Http::assertNothingSent();
});

it('queues a sync job when a contact is created', function (): void {
    Bus::fake();
    $contact = aContact('job@example.com');

    (new DispatchCrmSync())->handle(new LeadHubContactCreated($contact));

    Bus::assertDispatched(
        SyncContactToCrmJob::class,
        fn ($job) => $job->contactUuid === (string) $contact->uuid && $job->event === 'created',
    );
});
