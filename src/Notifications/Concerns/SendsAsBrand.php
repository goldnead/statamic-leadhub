<?php

namespace Goldnead\Leadhub\Notifications\Concerns;

use Goldnead\BrandContext\Sending\SenderIdentity;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Puts a brand's sender identity on the `MailMessage` a notification builds.
 *
 * Values on the message, never state in the config — the reason is spelled out
 * in {@see SenderIdentity}: Laravel burns `mail.from` into the cached mailer
 * instance the first time a mailer name is resolved, so a config override
 * escapes its own `finally` and leaves the first brand's address standing for
 * the rest of the process.
 *
 * `MailMessage::mailer()` and `MailMessage::from()` are what Laravel's own
 * `MailChannel` reads (`$this->mailer->mailer($message->mailer ?? null)` and
 * `buildMessage()`), so nothing here reimplements the channel.
 */
trait SendsAsBrand
{
    /**
     * The identity handed over by the one door, or null on a path that has none.
     */
    public ?SenderIdentity $brandIdentity = null;

    public function sendAs(SenderIdentity $identity): static
    {
        $this->brandIdentity = $identity;

        return $this;
    }

    /**
     * Stamp the message, if there is an identity to stamp it with.
     *
     * Both nulls mean "exactly as before this class existed": a null mailer is
     * what `$message->mailer ?? null` already resolved to, and a null address
     * leaves `config('mail.from')` in charge. A single-brand install therefore
     * sends byte-identically to what it sent before.
     */
    protected function asBrand(MailMessage $message): MailMessage
    {
        $identity = $this->brandIdentity;

        if (! $identity) {
            return $message;
        }

        if ($identity->mailer !== null) {
            $message->mailer($identity->mailer);
        }

        if ($identity->fromAddress !== null) {
            $message->from($identity->fromAddress, $identity->fromName);
        }

        return $message;
    }
}
