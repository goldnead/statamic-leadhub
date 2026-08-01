<?php

namespace Goldnead\Leadhub\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FollowupDigestNotification extends Notification
{
    /**
     * @param  array<int, array{name: string, due_at: string, overdue: bool, url: string, note: ?string}>  $items
     */
    public function __construct(public array $items, public int $overdueCount, public int $todayCount) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('leadhub::notifications.digest.subject', [
                'overdue' => $this->overdueCount,
                'today' => $this->todayCount,
            ]))
            ->greeting(__('leadhub::notifications.digest.greeting'))
            ->line(__('leadhub::notifications.digest.line', [
                'overdue' => $this->overdueCount,
                'today' => $this->todayCount,
            ]));

        foreach ($this->items as $item) {
            $prefix = $item['overdue']
                ? __('leadhub::notifications.digest.overdue_prefix')
                : __('leadhub::notifications.digest.today_prefix');
            $line = trim($prefix.' '.$item['name'].' — '.$item['due_at']);
            if (! empty($item['note'])) {
                $line .= ' · '.$item['note'];
            }
            $message->line($line);
        }

        return $message->action(
            __('leadhub::notifications.digest.action'),
            cp_route('leadhub.followups.index'),
        );
    }
}
