<?php

/**
 * The sync log paginates instead of truncating.
 *
 * It used to be a hand-built <table> fed by a hardcoded `->limit(100)`: an
 * install syncing a few hundred contacts lost the rest of its log with no
 * pagination control and no hint that anything was missing. The screen now
 * renders <Listing> in server mode against a paginated data endpoint.
 */

use Goldnead\Leadhub\Models\SyncLog;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('sync-log@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.crm_destinations', true);
});

function makeSyncLogs(int $count): void
{
    foreach (range(1, $count) as $i) {
        SyncLog::create([
            'contact_uuid' => null,
            'contact_label' => "contact-{$i}@example.com",
            'destination' => 'zap',
            'driver' => 'webhook',
            'event' => 'contact.synced',
            'status' => $i % 10 === 0 ? SyncLog::STATUS_FAILED : SyncLog::STATUS_SUCCESS,
            'response_code' => 200,
            'message' => "row {$i}",
        ]);
    }
}

it('reaches every row beyond the old 100-row ceiling', function (): void {
    makeSyncLogs(120);

    $first = $this->getJson(cp_route('leadhub.sync-log.data').'?perPage=25');
    $first->assertStatus(200);

    expect($first->json('meta.total'))->toBe(120)
        ->and($first->json('meta.last_page'))->toBe(5)
        ->and($first->json('data'))->toHaveCount(25);

    $last = $this->getJson(cp_route('leadhub.sync-log.data').'?perPage=25&page=5');
    expect($last->json('data'))->toHaveCount(20);

    // Nothing in the union of the pages is missing, and nothing repeats.
    $ids = [];
    foreach (range(1, 5) as $page) {
        $response = $this->getJson(cp_route('leadhub.sync-log.data')."?perPage=25&page={$page}");
        $ids = array_merge($ids, array_column($response->json('data'), 'id'));
    }

    expect(array_unique($ids))->toHaveCount(120);
})->skip(fn () => config('leadhub.storage.driver') === 'flat', 'Sync logs are eloquent-only.');

it('returns meta.columns on every response, as Listing requires', function (): void {
    makeSyncLogs(3);

    $response = $this->getJson(cp_route('leadhub.sync-log.data'));

    $fields = array_column($response->json('meta.columns'), 'field');

    expect($fields)->toBe([
        'contact_label', 'destination', 'event', 'status', 'message', 'created_at',
    ]);
})->skip(fn () => config('leadhub.storage.driver') === 'flat', 'Sync logs are eloquent-only.');

it('translates the status instead of shipping the raw enum', function (): void {
    makeSyncLogs(10);

    $rows = collect($this->getJson(cp_route('leadhub.sync-log.data'))->json('data'));

    expect($rows->pluck('status_label')->unique()->sort()->values()->all())
        ->toBe(['Failed', 'Success']);
})->skip(fn () => config('leadhub.storage.driver') === 'flat', 'Sync logs are eloquent-only.');

it('searches the log server-side', function (): void {
    makeSyncLogs(30);

    $response = $this->getJson(cp_route('leadhub.sync-log.data').'?search=contact-17@example.com');

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.contact_label'))->toBe('contact-17@example.com');
})->skip(fn () => config('leadhub.storage.driver') === 'flat', 'Sync logs are eloquent-only.');

it('refuses the data endpoint without the view permission', function (): void {
    $plain = User::make()->email('sync-log-nobody@example.com');
    $plain->save();

    $this->actingAs($plain)
        ->getJson(cp_route('leadhub.sync-log.data'))
        ->assertStatus(403);
});
