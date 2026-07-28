<?php

namespace Goldnead\Leadhub\Integrations\WebhookManager;

use Goldnead\Leadhub\Events\LeadHubContactArchived;
use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubContactDeleted;
use Goldnead\Leadhub\Events\LeadHubContactEnteredSegment;
use Goldnead\Leadhub\Events\LeadHubContactLeftSegment;
use Goldnead\Leadhub\Events\LeadHubContactUpdated;
use Goldnead\Leadhub\Events\LeadHubFollowupCompleted;
use Goldnead\Leadhub\Events\LeadHubFollowupSet;
use Goldnead\Leadhub\Events\LeadHubNoteAdded;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Events\LeadHubSubmissionAttached;
use Goldnead\Leadhub\Events\LeadHubTagAdded;
use Goldnead\Leadhub\Events\LeadHubTagRemoved;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Optional integration with goldnead/statamic-webhook-manager.
 *
 * When that addon is installed, this bridge registers every LeadHub lifecycle
 * event as a webhook-manager trigger and re-emits it as the addon's internal
 * {@see \Goldnead\WebhookManager\Events\TriggerDetected} event, so the events
 * become selectable triggers in the Webhook Manager CP and can fire outbound
 * webhooks — without LeadHub ever depending on the addon.
 *
 * If the webhook manager is not installed (or the feature is disabled), boot()
 * is a no-op. Nothing here references the addon's classes until after the
 * class_exists() guard passes, so the bridge is safe to wire unconditionally.
 */
class WebhookManagerBridge
{
    /**
     * LeadHub event class => [trigger handle, CP label].
     *
     * @var array<class-string, array{0: string, 1: string}>
     */
    public const TRIGGERS = [
        LeadHubContactCreated::class => ['leadhub.contact.created', 'LeadHub — contact created'],
        LeadHubContactUpdated::class => ['leadhub.contact.updated', 'LeadHub — contact updated'],
        LeadHubStatusChanged::class => ['leadhub.status.changed', 'LeadHub — status changed'],
        LeadHubSubmissionAttached::class => ['leadhub.submission.attached', 'LeadHub — submission attached'],
        LeadHubFollowupSet::class => ['leadhub.followup.set', 'LeadHub — follow-up set'],
        LeadHubFollowupCompleted::class => ['leadhub.followup.completed', 'LeadHub — follow-up completed'],
        LeadHubTagAdded::class => ['leadhub.tag.added', 'LeadHub — tag added'],
        LeadHubTagRemoved::class => ['leadhub.tag.removed', 'LeadHub — tag removed'],
        LeadHubNoteAdded::class => ['leadhub.note.added', 'LeadHub — note added'],
        LeadHubContactArchived::class => ['leadhub.contact.archived', 'LeadHub — contact archived'],
        LeadHubContactDeleted::class => ['leadhub.contact.deleted', 'LeadHub — contact deleted'],
        \Goldnead\Leadhub\Events\LeadHubSourceIngested::class => ['leadhub.source.ingested', 'LeadHub — source ingested'],
        \Goldnead\Leadhub\Events\LeadHubContactsMerged::class => ['leadhub.contacts.merged', 'LeadHub — contacts merged'],
        \Goldnead\Leadhub\Events\LeadHubFollowupDue::class => ['leadhub.followup.due', 'LeadHub — follow-up due'],
        // A new event type is a public surface. Registering it here is what
        // makes "score crossed a threshold" usable from outside the addon
        // instead of a line in a timeline nobody polls.
        \Goldnead\Leadhub\Events\LeadHubContactScoreChanged::class => ['leadhub.score.changed', 'LeadHub — engagement score changed'],
        // Same reasoning for reassignment: "this task is now yours" is the
        // moment an outside system (a chat notification, a rota, a dashboard)
        // wants to hear about, and a timeline entry alone cannot tell it.
        \Goldnead\Leadhub\Events\LeadHubTaskAssigned::class => ['leadhub.task.assigned', 'LeadHub — task assigned'],
        LeadHubContactEnteredSegment::class => ['leadhub.segment.entered', 'LeadHub — entered segment'],
        LeadHubContactLeftSegment::class => ['leadhub.segment.left', 'LeadHub — left segment'],
    ];

    /**
     * Whether boot() has already run its registrations. Guards against
     * double-registering triggers/listeners when the bridge is booted more
     * than once (the ServiceProvider binds the bridge as a singleton so this
     * guard holds across container resolutions).
     */
    protected bool $booted = false;

    /**
     * Whether the webhook-manager addon is installed and the integration is on.
     */
    public static function available(): bool
    {
        return (bool) config('leadhub.features.webhook_manager', true)
            && class_exists(\Goldnead\WebhookManager\Facades\WebhookManager::class);
    }

    /**
     * Register LeadHub triggers and wire each LeadHub event to the webhook
     * manager. No-op when the addon is absent or the feature is disabled.
     */
    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // The 'webhook-manager' container binding only appears once that
        // addon's own provider has booted (sibling boot order is not
        // guaranteed). Bail WITHOUT marking booted so a later boot attempt
        // can still succeed instead of silently registering nothing.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        foreach (static::TRIGGERS as $eventClass => [$handle, $label]) {
            // Fail-safe: resolving the webhook-manager facade touches a binding
            // that the addon sets up in its own boot. Addon boot order between
            // two sibling addons is not guaranteed, so registration must never
            // be allowed to break LeadHub's own boot.
            try {
                \Goldnead\WebhookManager\Facades\WebhookManager::registerTrigger(
                    new LeadhubTrigger($handle, $label)
                );
            } catch (\Throwable $e) {
                Log::warning('LeadHub → Webhook Manager bridge could not register trigger ['.$handle.']: '.$e->getMessage());

                continue;
            }

            $events->listen($eventClass, function ($event) use ($handle): void {
                $this->dispatch($handle, $event);
            });
        }
    }

    /**
     * Normalize a LeadHub event into a TriggerEvent and hand it to the webhook
     * manager. Fail-safe: a webhook-manager error must never break the LeadHub
     * pipeline (mirrors the addon's fail-safe submission listener).
     */
    protected function dispatch(string $handle, object $event): void
    {
        try {
            $trigger = \Goldnead\WebhookManager\Facades\WebhookManager::triggers()->get($handle);

            if (! $trigger) {
                return;
            }

            event(new \Goldnead\WebhookManager\Events\TriggerDetected(
                $trigger->build($event)
            ));
        } catch (\Throwable $e) {
            Log::warning('LeadHub → Webhook Manager bridge failed for ['.$handle.']: '.$e->getMessage());
        }
    }
}
