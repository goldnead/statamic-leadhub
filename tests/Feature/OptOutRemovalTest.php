<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Exceptions\CrmRemovalFailedException;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Jobs\RemoveContactFromCrmJob;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\CrmSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * An opt-out has to reach the lists outside LeadHub, and when it does not, it
 * has to say so. Before, the result was thrown away, a failed removal was
 * never retried, and a destination that cannot remove anyone was skipped
 * without a word.
 */
beforeEach(function (): void {
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

function optOutEvents(int $contactId, string $type)
{
    return Event::query()->where('contact_id', $contactId)->where('type', $type)->get();
}

it('reports which destinations removed the contact and when the opt-out happened', function (): void {
    Http::fake(['api.brevo.com/*' => Http::response([], 201)]);
    $contact = LeadHub::create(['email' => 'bericht@example.com']);

    $result = LeadHub::optOut($contact['id']);

    expect($result['opt_out']['removed'])->toBe(['brevo'])
        ->and($result['opt_out']['failed'])->toBe([])
        ->and($result['opt_out']['unsupported'])->toBe([])
        ->and($result['opt_out']['opted_out_at'])->not->toBeNull();
});

it('writes the opt-out with its timestamp into the timeline', function (): void {
    Http::fake(['api.brevo.com/*' => Http::response([], 201)]);
    $contact = LeadHub::create(['email' => 'zeit@example.com']);

    LeadHub::optOut($contact['id']);

    $events = optOutEvents($contact['id'], Event::TYPE_CONTACT_OPTED_OUT);
    expect($events)->toHaveCount(1)
        ->and($events->first()->payload['opted_out_at'] ?? null)->not->toBeNull();
});

it('names the destinations that cannot remove a contact instead of skipping them', function (): void {
    Http::fake();
    config()->set('leadhub.crm.destinations', [
        'hub' => ['driver' => 'hubspot', 'enabled' => true, 'token' => 't', 'triggers' => ['created']],
        'zap' => ['driver' => 'webhook', 'enabled' => true, 'url' => 'https://hooks.test/x', 'triggers' => ['created']],
    ]);
    $contact = LeadHub::create(['email' => 'manuell@example.com']);

    $result = LeadHub::optOut($contact['id']);

    expect($result['opt_out']['unsupported'])->toBe(['hub', 'zap']);

    $failed = optOutEvents($contact['id'], Event::TYPE_CRM_SYNC_FAILED);
    expect($failed->pluck('payload.destination')->sort()->values()->all())->toBe(['hub', 'zap'])
        ->and($failed->first()->payload['reason'] ?? null)->toBe('removal_unsupported');
});

it('reports a failed removal and queues a retry for exactly that destination', function (): void {
    Queue::fake();
    Http::fake(['api.brevo.com/*' => Http::response(['message' => 'down'], 503)]);
    $contact = LeadHub::create(['email' => 'nochmal@example.com']);

    $result = LeadHub::optOut($contact['id']);

    expect(array_keys($result['opt_out']['failed']))->toBe(['brevo'])
        ->and($result['opt_out']['removed'])->toBe([]);

    Queue::assertPushed(RemoveContactFromCrmJob::class, fn ($job) => $job->contactUuid === $contact['uuid']
        && $job->destinations === ['brevo']);
});

it('lets the retry job fail loudly so the queue tries again', function (): void {
    Http::fake(['api.brevo.com/*' => Http::response(['message' => 'down'], 503)]);
    $contact = LeadHub::create(['email' => 'laut@example.com']);
    $model = app(ContactRepository::class)->find($contact['id']);

    $job = new RemoveContactFromCrmJob((string) $model->uuid, ['brevo']);

    expect($job->tries)->toBeGreaterThan(1);
    expect(fn () => $job->handle(app(CrmSyncService::class), app(ContactRepository::class)))
        ->toThrow(CrmRemovalFailedException::class);
});

it('records the final failure on the contact when every attempt is used up', function (): void {
    $contact = LeadHub::create(['email' => 'aufgegeben@example.com']);
    $model = app(ContactRepository::class)->find($contact['id']);

    (new RemoveContactFromCrmJob((string) $model->uuid, ['brevo']))
        ->failed(new CrmRemovalFailedException('brevo (HTTP 503)'));

    $failed = optOutEvents($contact['id'], Event::TYPE_CRM_SYNC_FAILED)
        ->filter(fn ($e) => ($e->payload['reason'] ?? null) === 'removal_gave_up');
    expect($failed)->toHaveCount(1);
});

it('does not break the opt-out on the sync queue and records the last failure', function (): void {
    config()->set('queue.default', 'sync');
    Http::fake(['api.brevo.com/*' => Http::response(['message' => 'down'], 503)]);
    $contact = LeadHub::create(['email' => 'syncqueue@example.com']);

    $result = LeadHub::optOut($contact['id']);

    expect(array_keys($result['opt_out']['failed']))->toBe(['brevo']);
    $gaveUp = optOutEvents($contact['id'], Event::TYPE_CRM_SYNC_FAILED)
        ->filter(fn ($e) => ($e->payload['reason'] ?? null) === 'removal_gave_up');
    expect($gaveUp)->toHaveCount(1);
});

it('names an unsupported destination once, not on every repeated opt-out', function (): void {
    Http::fake();
    config()->set('leadhub.crm.destinations', [
        'hub' => ['driver' => 'hubspot', 'enabled' => true, 'token' => 't', 'triggers' => ['created']],
    ]);
    $contact = LeadHub::create(['email' => 'zweimal@example.com']);

    LeadHub::optOut($contact['id']);
    $second = LeadHub::optOut($contact['id']);

    expect($second['opt_out']['unsupported'])->toBe(['hub'])
        ->and($second['opt_out']['opted_out_at'])->not->toBeNull()
        ->and(optOutEvents($contact['id'], Event::TYPE_CRM_SYNC_FAILED))->toHaveCount(1);
});

it('does not remove twice from a destination that already took the opt-out', function (): void {
    config()->set('leadhub.crm.destinations.brevo2', [
        'driver' => 'brevo', 'enabled' => true, 'api_key' => 'k2', 'list_id' => 8, 'triggers' => ['created'],
    ]);
    Http::fakeSequence('api.brevo.com/v3/contacts/lists/8/*')->push(['message' => 'down'], 503)->push([], 201);
    Http::fake(['api.brevo.com/v3/contacts/lists/7/*' => Http::response([], 201)]);
    $contact = LeadHub::create(['email' => 'einmal@example.com']);
    $model = app(ContactRepository::class)->find($contact['id']);

    $job = new RemoveContactFromCrmJob((string) $model->uuid, ['brevo', 'brevo2']);
    expect(fn () => $job->handle(app(CrmSyncService::class), app(ContactRepository::class)))
        ->toThrow(CrmRemovalFailedException::class);

    unserialize(serialize($job))->handle(app(CrmSyncService::class), app(ContactRepository::class));

    $toList7 = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/lists/7/'))->count();
    expect($toList7)->toBe(1);
});
