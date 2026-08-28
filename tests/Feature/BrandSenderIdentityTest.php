<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Sending\SenderIdentity;
use Goldnead\Leadhub\Contracts\BrandAddressed;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\FollowupRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Notifications\FollowupDigestNotification;
use Goldnead\Leadhub\Notifications\LeadAssignedNotification;
use Goldnead\Leadhub\Notifications\NewLeadNotification;
use Goldnead\Leadhub\Sending\BrandMailer;
use Goldnead\Leadhub\Services\LeadHubNotifier;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * LeadHub's staff mail leaves as the brand the contact belongs to.
 *
 * Before this, `Notification::route('mail', …)->notify()` used the process-wide
 * default mailer and the process-wide From for every brand. On a host where
 * each brand's sending domain is verified in its own relay account that pairs
 * one brand's transport with another brand's address — the relay refuses it or
 * rewrites it, and an internal alert nobody sees is an alert that does not
 * exist.
 *
 * The load-bearing assertions are the two ends: a brand that declared a mail
 * identity gets it on the message, and an installation that declared none sends
 * exactly as it did before.
 */
beforeEach(function (): void {
    config()->set('leadhub.notifications.enabled', true);
    config()->set('mail.mailers.brand_relay', ['transport' => 'array']);
});

function brandWithMail(string $handle, array $mail): Brand
{
    return Brand::create([
        'handle' => $handle,
        'name' => ucfirst($handle),
        'settings' => ['mail' => $mail],
    ]);
}

function contactIn(?Brand $brand, array $attributes = []): Contact
{
    $make = fn () => app(ContactRepository::class)->create(array_merge([
        'email' => 'lead@example.com',
        'email_normalized' => 'lead@example.com',
        'first_name' => 'Lead',
        'status' => 'new',
    ], $attributes));

    return $brand
        ? BrandContext::runFor($brand, $make)
        : $make();
}

/**
 * Fire the new-lead alert the way a request does: inside the brand it is about.
 *
 * The tests that use this assert what the sender-identity layer makes of a
 * brand's `settings.mail`. They do not assert *where* the brand id came from,
 * and only one of the two storage drivers can answer that from the contact:
 * `HasBrand` stamps `brand_id` in an Eloquent `creating` hook, while the
 * flat-file driver keeps brand membership in the directory path and writes no
 * brand key into the YAML at all — a decision `Repositories\FlatFile\BrandSegments`
 * argues at length. So `LeadHubNotifier::brandOf()` is null on flat, and a test
 * that relied on it silently covered one driver out of two.
 *
 * Inside the brand context both drivers reach the same identity: `brandOf()`
 * may return null, and `BrandSenderIdentity::resolve(null)` then falls back to
 * `BrandContext::current()`. That is also the shape production has — a form
 * submission is handled inside the brand whose domain it arrived on.
 *
 * The contact-row path keeps its own test below, which is the one that is
 * eloquent-only.
 */
function notifyNewLeadAs(Brand $brand, Contact $contact): bool
{
    return BrandContext::runFor($brand, fn () => app(LeadHubNotifier::class)->newLead($contact));
}

it('puts the brand from-address and mailer on a new-lead alert', function (): void {
    Notification::fake();
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    $brand = brandWithMail('chorgesucht', [
        'from_address' => 'noreply@chorgesucht.de',
        'from_name' => 'chorgesucht.de',
        'mailer' => 'brand_relay',
    ]);

    notifyNewLeadAs($brand, contactIn($brand));

    Notification::assertSentOnDemand(NewLeadNotification::class, function ($notification, $channels, $notifiable) {
        $message = $notification->toMail($notifiable);

        expect($message->from)->toBe(['noreply@chorgesucht.de', 'chorgesucht.de'])
            ->and($message->mailer)->toBe('brand_relay');

        return true;
    });
});

it('carries the brand of the contact, not the brand in context', function (): void {
    // The contact row knows; the context may be a queued listener or a console
    // import with no brand resolved at all. Getting this wrong sends brand A's
    // lead alert through brand B's relay account.
    Notification::fake();
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    $a = brandWithMail('brand-a', ['from_address' => 'a@example.com', 'mailer' => 'brand_relay']);
    brandWithMail('brand-b', ['from_address' => 'b@example.com', 'mailer' => 'brand_relay']);

    $contact = contactIn($a);
    app('brand-context')->forget();

    app(LeadHubNotifier::class)->newLead($contact);

    Notification::assertSentOnDemand(NewLeadNotification::class, function ($notification, $channels, $notifiable) {
        expect($notification->toMail($notifiable)->from[0])->toBe('a@example.com');

        return true;
    });
})->skip(
    fn () => config('leadhub.storage.driver') === 'flat',
    'Eloquent-only: on the flat driver a contact carries no brand_id to read back, '
    .'because brand membership lives in the directory path (see BrandSegments).',
);

it('sends nothing for a brand that declares mail settings but no from-address', function (): void {
    // The half-pair: one brand's transport behind the host-wide address.
    // Refusing is the smaller failure, and it is the visible one.
    Notification::fake();
    Log::spy();
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    $brand = brandWithMail('halfpair', ['mailer' => 'brand_relay']);

    expect(notifyNewLeadAs($brand, contactIn($brand)))->toBeFalse();

    Notification::assertNothingSent();
    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('sends nothing for a brand naming a mailer config/mail.php does not define', function (): void {
    Notification::fake();
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    $brand = brandWithMail('typo', [
        'from_address' => 'x@example.com',
        'mailer' => 'scaleway_typo',
    ]);

    expect(notifyNewLeadAs($brand, contactIn($brand)))->toBeFalse();

    Notification::assertNothingSent();
});

it('leaves an installation without brand mail settings exactly as it was', function (): void {
    // The line that keeps this addon installable outside the host it was
    // written for. No from, no mailer on the message: `config('mail.from')` and
    // `config('mail.default')` stay in charge, as before this layer existed.
    Notification::fake();
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    app(LeadHubNotifier::class)->newLead(contactIn(null));

    Notification::assertSentOnDemand(NewLeadNotification::class, function ($notification, $channels, $notifiable) {
        $message = $notification->toMail($notifiable);

        expect($message->from)->toBe([])
            ->and($message->mailer)->toBeNull();

        return true;
    });
});

it('writes the brand locale onto the notification', function (): void {
    Notification::fake();
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    $brand = brandWithMail('deutsch', [
        'from_address' => 'noreply@example.de',
        'locale' => 'de',
    ]);

    notifyNewLeadAs($brand, contactIn($brand));

    Notification::assertSentOnDemand(
        NewLeadNotification::class,
        fn ($notification) => $notification->locale === 'de',
    );
});

it('stamps the assignment alert and the follow-up digest as well', function (): void {
    // All three notification classes, not just the one that happened to be
    // wired first. A class that forgets to stamp its message sends under the
    // host identity and nothing turns red.
    foreach ([NewLeadNotification::class, LeadAssignedNotification::class, FollowupDigestNotification::class] as $class) {
        expect(is_subclass_of($class, BrandAddressed::class))->toBeTrue();
    }

    $contact = contactIn(null);
    $identity = SenderIdentity::of('brand_relay', 'noreply@example.com', 'Example');

    $messages = [
        (new NewLeadNotification($contact))->sendAs($identity)->toMail(new stdClass),
        (new LeadAssignedNotification($contact))->sendAs($identity)->toMail(new stdClass),
        (new FollowupDigestNotification([], 0, 0))->sendAs($identity)->toMail(new stdClass),
    ];

    foreach ($messages as $message) {
        expect($message)->toBeInstanceOf(MailMessage::class)
            ->and($message->from)->toBe(['noreply@example.com', 'Example'])
            ->and($message->mailer)->toBe('brand_relay');
    }
});

it('refuses to dispatch a notification that cannot carry an identity', function (): void {
    $stranger = new class extends LaravelNotification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }
    };

    app(BrandMailer::class)->notify(null, ['staff@example.com'], $stranger);
})->throws(LogicException::class, 'must implement');

it('does not claim to have sent digests for a brand that cannot send', function (): void {
    // The command prints how many digests went out. Before the guard it printed
    // the number of recipients it had assembled, which for an unconfigured
    // brand was a count of refusals reported as deliveries.
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    config()->set('leadhub.notifications.digest.enabled', true);
    config()->set('leadhub.notifications.digest.fallback_recipients', ['staff@example.com']);

    $brand = brandWithMail('halfpair-digest', ['mailer' => 'brand_relay']);

    $contact = contactIn($brand, ['email' => 'due@example.com', 'email_normalized' => 'due@example.com']);

    BrandContext::runFor($brand, function () use ($contact) {
        app(FollowupRepository::class)
            ->create($contact, now()->subDay(), 'overdue');
    });

    $this->artisan('leadhub:followups:digest', ['--brand' => 'halfpair-digest'])
        ->expectsOutputToContain('no follow-up digest was sent')
        ->doesntExpectOutputToContain('Sent 1 follow-up digest')
        ->assertSuccessful();
});

it('puts the brand address on the header of a message that really left', function (): void {
    // Everything above asserts against the MailMessage object under
    // Notification::fake(). That proves this package's half. This one runs the
    // real channel over the array transport and reads the header off the
    // Symfony message, so a break in the wiring between `MailMessage::from()`
    // and what Laravel actually sends would be caught here.
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    config()->set('mail.default', 'array');
    config()->set('leadhub.notifications.recipients', ['sales@example.com']);

    $brand = brandWithMail('echt', [
        'from_address' => 'noreply@chorgesucht.de',
        'from_name' => 'chorgesucht.de',
        'mailer' => 'brand_relay',
    ]);

    $transport = Mail::mailer('brand_relay')->getSymfonyTransport();
    $transport->flush();

    expect(notifyNewLeadAs($brand, contactIn($brand)))->toBeTrue();

    $sent = $transport->messages();

    expect($sent)->toHaveCount(1);

    $from = $sent[0]->getOriginalMessage()->getFrom();

    expect($from[0]->getAddress())->toBe('noreply@chorgesucht.de')
        ->and($from[0]->getName())->toBe('chorgesucht.de');
});

it('reports a notification that cannot carry an identity at error level, through the notifier', function (): void {
    // The path production actually takes. `LeadHubNotifier::send()` catches
    // Throwable so a dead SMTP host cannot roll back a form submission — and
    // that catch used to turn this permanent, silent outage into the same
    // Log::warning as a transient one. It is fail-safe either way; the point is
    // that somebody finds out.
    Log::spy();

    $stranger = new class extends LaravelNotification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }
    };

    $notifier = app(LeadHubNotifier::class);

    $send = (new ReflectionClass($notifier))->getMethod('send');
    $send->setAccessible(true);

    expect($send->invoke($notifier, ['staff@example.com'], $stranger, null))->toBeFalse();

    Log::shouldHaveReceived('error')->atLeast()->once();
    Log::shouldNotHaveReceived('warning');
});
