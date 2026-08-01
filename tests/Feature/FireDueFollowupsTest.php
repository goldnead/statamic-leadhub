<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Events\LeadHubFollowupDue;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Services\FollowupService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Eloquent-targeted.');
    }
});

it('fires a follow-up-due event for overdue and due-today follow-ups', function () {
    Event::fake([LeadHubFollowupDue::class]);

    $contacts = app(ContactRepository::class);
    $followups = app(FollowupService::class);

    $a = LeadHub::create(['email' => 'overdue@example.com']);
    $b = LeadHub::create(['email' => 'today@example.com']);
    $c = LeadHub::create(['email' => 'future@example.com']);

    $followups->set($contacts->find($a['id']), now()->subDays(3));
    $followups->set($contacts->find($b['id']), now());
    $followups->set($contacts->find($c['id']), now()->addDays(5));

    $this->artisan('leadhub:followups:due')->assertSuccessful();

    // Overdue + due-today fire; the future one does not.
    Event::assertDispatchedTimes(LeadHubFollowupDue::class, 2);
});
