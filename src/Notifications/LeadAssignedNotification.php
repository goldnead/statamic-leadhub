<?php

namespace Goldnead\Leadhub\Notifications;

use Goldnead\Leadhub\Contracts\BrandAddressed;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Notifications\Concerns\SendsAsBrand;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadAssignedNotification extends Notification implements BrandAddressed
{
    use SendsAsBrand;

    public function __construct(public Contact $contact) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->contact->displayName();

        $message = (new MailMessage)
            ->subject(__('leadhub::notifications.assigned.subject', ['name' => $name]))
            ->greeting(__('leadhub::notifications.assigned.greeting'))
            ->line(__('leadhub::notifications.assigned.line', ['name' => $name]))
            ->action(
                __('leadhub::notifications.view_contact'),
                cp_route('leadhub.contacts.show', $this->contact->uuid),
            );

        return $this->asBrand($message);
    }
}
