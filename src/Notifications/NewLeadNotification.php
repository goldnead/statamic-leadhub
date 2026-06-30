<?php

namespace Goldnead\Leadhub\Notifications;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewLeadNotification extends Notification
{
    public function __construct(public Contact $contact)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->contact->displayName();

        $message = (new MailMessage)
            ->subject(__('leadhub::notifications.new_lead.subject', ['name' => $name]))
            ->greeting(__('leadhub::notifications.new_lead.greeting'))
            ->line(__('leadhub::notifications.new_lead.line', [
                'name' => $name,
                'email' => (string) $this->contact->email,
            ]));

        if ($this->contact->company) {
            $message->line(__('leadhub::notifications.company', ['company' => $this->contact->company]));
        }
        if ($this->contact->source_form) {
            $message->line(__('leadhub::notifications.source', ['source' => $this->contact->source_form]));
        }

        return $message->action(
            __('leadhub::notifications.view_contact'),
            cp_route('leadhub.contacts.show', $this->contact->uuid),
        );
    }
}
