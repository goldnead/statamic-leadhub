<?php

namespace Goldnead\Leadhub\Integrations\Notifications;

use Goldnead\Notifications\NotificationManager;

/**
 * Optional wiring into `goldnead/statamic-notifications`. A no-op when that
 * addon is not installed, exactly like the webhook-manager bridge.
 *
 * ## Why this is a provider concern and not a controller concern
 *
 * The type registry lives **per process**. A type registered where the
 * notification is produced — in a controller, in a console one-off — is unknown
 * to the scheduled digest process. There it falls back to the `in_app` default
 * and the item is silently skipped, so the notification exists, is never
 * summarised, and nothing logs a word about it. That skipping is deliberate on
 * the notifications side (it is what stops an immediate mail being repeated
 * days later), which is precisely why the registration has to be global.
 * Registering from `ServiceProvider::boot()` is the only place that holds for
 * the web process, the queue worker and the scheduler alike.
 *
 * ## The direction of the dependency
 *
 * notifications already knows about LeadHub: it bundles a follow-up digest
 * source and registers `crm.lead_assigned` / `crm.followup_due` when this addon
 * is present. Tasks are not in that bundle and should not be — the data,
 * the wording and the CP route belong here. So this half is registered from
 * LeadHub, under handles that do not collide with the bundled ones.
 */
class NotificationsBridge
{
    /** The type handle for "a task was assigned to you". */
    public const TASK_ASSIGNED = 'crm.task_assigned';

    /** The digest source handle. `leadhub` is taken by the bundled follow-up source. */
    public const DIGEST_SOURCE = 'leadhub-tasks';

    /**
     * Whether the notifications addon is installed AND booted far enough to
     * accept a registration. Both halves matter: the class can exist while the
     * container binding does not yet, and calling the facade then would fail
     * during boot rather than degrade to nothing.
     */
    public static function available(): bool
    {
        return class_exists(NotificationManager::class)
            && app()->bound('notifications');
    }

    public function boot(): void
    {
        if (! static::available()) {
            return;
        }

        $notifications = app('notifications');

        // No renderer on purpose. NotificationType::render() falls back to the
        // item's own `message` and `link`, and those are written at notify time
        // by the code that knows which task it was — a renderer here would have
        // to re-read the task to say the same sentence, and would say nothing
        // at all once the task is deleted.
        $notifications->registerType(self::TASK_ASSIGNED, function ($type): void {
            $type->label(__('leadhub::tasks.notifications.assigned_label'))
                ->defaultChannels(['in_app', 'mail']);
        });

        // Open tasks in the digest. The bundled `leadhub` source contributes
        // open follow-ups only, which is the whole of what the digest covered
        // through v1.9.0: somebody carrying ten open tasks and no follow-up got
        // a mail that said nothing about their work.
        $notifications->registerSource(self::DIGEST_SOURCE, TaskDigestSource::class);
    }
}
