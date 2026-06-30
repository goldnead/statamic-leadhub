<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Notifications\FollowupDigestNotification;
use Goldnead\Leadhub\Notifications\LeadAssignedNotification;
use Goldnead\Leadhub\Notifications\NewLeadNotification;
use Goldnead\Leadhub\Services\FollowupService;
use Goldnead\Leadhub\Services\LeadHubNotifier;
use Illuminate\Support\Facades\Notification;
use Statamic\Facades\User;

beforeEach(function (): void {
    config()->set('leadhub.notifications.enabled', true);
});

function makeOwner(string $email): string
{
    $user = User::make()->email($email)->makeSuper();
    $user->save();

    return (string) $user->id();
}

it('emails the configured recipients for a new unassigned lead', function (): void {
    Notification::fake();
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    $contact = app(ContactRepository::class)->create([
        'email' => 'lead@example.com',
        'email_normalized' => 'lead@example.com',
        'first_name' => 'Lead',
        'status' => 'new',
    ]);

    app(LeadHubNotifier::class)->newLead($contact);

    Notification::assertSentOnDemand(NewLeadNotification::class, fn ($n, $ch, $notifiable) => in_array('sales@example.com', (array) ($notifiable->routes['mail'] ?? []), true));
});

it('emails the owner when a lead is assigned', function (): void {
    Notification::fake();
    $ownerId = makeOwner('owner@example.com');

    $contact = app(ContactRepository::class)->create([
        'email' => 'assigned@example.com',
        'email_normalized' => 'assigned@example.com',
        'status' => 'new',
        'assigned_to' => $ownerId,
    ]);

    app(LeadHubNotifier::class)->assigned($contact);

    Notification::assertSentOnDemand(LeadAssignedNotification::class, fn ($n, $ch, $notifiable) => in_array('owner@example.com', (array) ($notifiable->routes['mail'] ?? []), true));
});

it('sends nothing when notifications are disabled', function (): void {
    Notification::fake();
    config()->set('leadhub.notifications.enabled', false);
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    $contact = app(ContactRepository::class)->create([
        'email' => 'x@example.com', 'email_normalized' => 'x@example.com', 'status' => 'new',
    ]);
    app(LeadHubNotifier::class)->newLead($contact);

    Notification::assertNothingSent();
});

it('sends a follow-up digest to each owner', function (): void {
    Notification::fake();
    $ownerId = makeOwner('rep@example.com');

    $contact = app(ContactRepository::class)->create([
        'email' => 'due@example.com', 'email_normalized' => 'due@example.com',
        'status' => 'new', 'assigned_to' => $ownerId,
    ]);
    app(FollowupService::class)->set($contact, now()->startOfDay()->addHours(9), 'Call back');

    $this->artisan('leadhub:followups:digest')->assertSuccessful();

    Notification::assertSentOnDemand(FollowupDigestNotification::class, fn ($n, $ch, $notifiable) => in_array('rep@example.com', (array) ($notifiable->routes['mail'] ?? []), true));
});
