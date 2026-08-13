<?php

namespace Goldnead\Leadhub\Sending;

use Goldnead\BrandContext\Sending\BrandMailer as BrandContextMailer;
use Goldnead\Leadhub\Contracts\BrandAddressed;
use Goldnead\Leadhub\Contracts\SenderIdentityResolver;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Notification;
use LogicException;

/**
 * The one door every mail in this package leaves through.
 *
 * The mechanism is {@see BrandContextMailer}: values on the message, never
 * state in the config, a refusal as a return value rather than an exception.
 * This subclass narrows which resolver gets injected, so that a host rebinding
 * statamic-leadhub's {@see SenderIdentityResolver} is answered here and nowhere
 * else — and it adds the one way in which LeadHub's mail differs from the other
 * addons' mail.
 *
 * **LeadHub sends notifications, not mailables.** New lead, lead assigned and
 * the follow-up digest are `Illuminate\Notifications\Notification`s dispatched
 * at an on-demand mail route. `BrandMailer::send()` takes a `Mailable` and puts
 * the identity on it; a notification has no such surface until its own
 * `toMail()` has run. So {@see self::notify()} hands the identity to the
 * notification first and lets the notification stamp its own `MailMessage` —
 * see {@see BrandAddressed}.
 *
 * **These are internal mails to staff, not post to customers**, and they go out
 * under the brand the contact belongs to. Decided by Adrian on 13.08.2026: "new
 * lead at brand X" is news about that brand, and on a host where each brand's
 * domain is verified in its own relay account the host identity is not merely
 * the wrong name — it is an address the brand's transport is not allowed to
 * carry, so the relay refuses it. A staff mail that silently never arrives is
 * worse than one that arrives with the wrong name in the From.
 */
class BrandMailer extends BrandContextMailer
{
    public function __construct(SenderIdentityResolver $identities)
    {
        parent::__construct($identities);
    }

    /**
     * Dispatch one notification to on-demand mail recipients, as the given brand.
     *
     * **The notification instance belongs to this send afterwards.** It is
     * mutated, not copied: the identity and the locale are written onto it,
     * because `toMail()` is the only place a `MailMessage` exists and only the
     * notification itself can reach it. Build a fresh instance per send — every
     * caller in this package does. Handing the same instance to two brands
     * would give the second one the first one's From, which is the failure this
     * class exists to prevent.
     *
     * All recipients of one call get one `toMail()`: `Notification::route()`
     * builds a single `AnonymousNotifiable` with an array route. That is why
     * the recipients must belong to one brand, which they do — they are one
     * contact's owner, or one brand's configured inbox.
     *
     * @param  int|null  $brandId  null = the brand in context, if any.
     * @param  list<string>  $recipients
     * @return bool whether it went out
     */
    public function notify(?int $brandId, array $recipients, LaravelNotification $notification): bool
    {
        $this->refuseUnaddressable($notification);

        if ($recipients === []) {
            return false;
        }

        $identity = $this->identities->resolve($brandId);

        if (! $identity->maySend()) {
            $this->sayRefused($brandId, $identity);

            return false;
        }

        // An explicit locale on the notification wins: the caller knew
        // something the brand row does not. Laravel's `NotificationSender`
        // reads `$notification->locale` and wraps the whole render in it.
        if ($identity->locale !== null && ! $notification->locale) {
            $notification->locale($identity->locale);
        }

        /** @var BrandAddressed&LaravelNotification $notification */
        $notification->sendAs($identity);

        Notification::route('mail', $recipients)->notify($notification);

        return true;
    }

    /**
     * A notification that cannot take an identity must not be dispatched here.
     *
     * It would go out under `config('mail.from')` and the default transport —
     * the failure this class exists to prevent — and it would do so silently,
     * because a mail that leaves is indistinguishable from a mail that leaves
     * correctly until somebody reads the headers. Louder than a docblock on
     * purpose: this is the kind of rule that is broken by the next class
     * somebody adds, not by the ones that are here now.
     */
    protected function refuseUnaddressable(LaravelNotification $notification): void
    {
        if ($notification instanceof BrandAddressed) {
            return;
        }

        throw new LogicException(
            'A notification sent through LeadHub\'s BrandMailer must implement '
            .BrandAddressed::class.' so the brand\'s from-address and mailer can be put on its '
            .'MailMessage. Without it the mail would leave under the host-wide From. Use the '
            .'SendsAsBrand trait and end toMail() with `return $this->asBrand($message);`. ('
            .$notification::class.')'
        );
    }
}
