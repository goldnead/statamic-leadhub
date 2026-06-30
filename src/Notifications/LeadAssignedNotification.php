<?php

namespace Goldnead\Leadhub\Notifications;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadAssignedNotification extends Notification
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

        return (new MailMessage)
            ->subject(__('leadhub::notifications.assigned.subject', ['name' => $name]))
            ->greeting(__('leadhub::notifications.assigned.greeting'))
            ->line(__('leadhub::notifications.assigned.line', ['name' => $name]))
            ->action(
                __('leadhub::notifications.view_contact'),
                cp_route('leadhub.contacts.show', $this->contact->uuid),
            );
    }
}
