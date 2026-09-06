<?php

/*
 * The editable settings screen.
 *
 * Every label and description the form shows comes from here, because the form
 * is generated from Support\Settings and that definition carries translation
 * keys rather than English strings. Field keys are the config path with the dots
 * flattened (`exports.queue_threshold` → `exports_queue_threshold`): a dot
 * inside a lang key is a path separator to the translator and would be looked up
 * as nested arrays that do not exist.
 */

return [

    // The page chrome that used to be here — title, intro, save, saving, saved,
    // unavailable, default_placeholder and the read-only statuses panel — is
    // gone with the screen it belonged to. The form is the suite's shared one in
    // goldnead/statamic-brand-context, which brings its own chrome; the status
    // handles moved onto the LeadHub dashboard next to the counts.
    'publish_command' => 'php artisan vendor:publish --tag=leadhub-config',

    'groups' => [
        'behavior' => [
            'title' => 'Behavior',
            'description' => 'What happens when a form submission arrives. These apply to submissions received after the change; contacts already created keep the fields they were given.',
        ],
        'redaction' => [
            'title' => 'Payload redaction',
            'description' => 'Field names matching these are replaced before the submission payload is written to the timeline. Matching is a case-insensitive substring match on the field name, so a name that appears at any depth of a payload is covered. Emptying the list means payloads are stored exactly as they arrive.',
        ],
        'features' => [
            'title' => 'Feature flags',
            'description' => 'Which parts of the addon are active. A disabled module loses its Control Panel screens, its navigation entry and its runtime wiring; the data it already created stays where it is.',
        ],
        'exports' => [
            'title' => 'Exports',
            'description' => 'Where CSV exports are written, and when they stop running in the browser and go onto the queue.',
        ],
        'scoring' => [
            'title' => 'Lead scoring',
            'description' => 'The fallback values for a brand that has no rows of its own in the scoring table. Rules per event are edited under LeadHub → Scoring, per brand, and take precedence over these.',
        ],
        'click_tracking' => [
            'title' => 'Email link-click tracking',
            'description' => 'How tracked clicks are counted. These only matter when the click tracking feature is on, and a click is scored only for a contact who has given marketing consent.',
        ],
        'notifications' => [
            'title' => 'Notifications',
            'description' => 'Which internal e-mails go out. All of them are additionally gated by the deployment-owned notifications switch, which is shown on the LeadHub dashboard.',
        ],
    ],

    'fields' => [

        'default_status' => [
            'label' => 'Default lead status',
            'description' => 'The status a newly captured contact starts on. The list is the one from config/leadhub.php; the handles are printed on the LeadHub dashboard.',
        ],
        'overwrite_existing_fields_from_submissions' => [
            'label' => 'Overwrite existing fields from submissions',
            'description' => 'When a new submission arrives for a contact that already exists, overwrite fields that already carry a value. Off protects fields maintained by hand.',
        ],
        'store_full_submission_payload' => [
            'label' => 'Store full submission payload',
            'description' => 'Attach the whole submission payload (after redaction) to the timeline event. Off stores only the mapped fields.',
        ],
        'timeline_payload_redaction' => [
            'label' => 'Redacted field names',
            'description' => 'One name per line.',
        ],

        'features_manual_contacts' => [
            'label' => 'Manual contacts',
            'description' => 'Creating a contact by hand instead of waiting for a form submission.',
        ],
        'features_csv_export' => [
            'label' => 'CSV export',
            'description' => 'The export button on the contact list. Off closes the route through which contact data leaves the install.',
        ],
        'features_attribution' => [
            'label' => 'Attribution capture',
            'description' => 'Capture the UTM parameters, referrer and landing page from a submission onto the contact. First touch only, never overwritten later.',
        ],
        'features_ingestion' => [
            'label' => 'Ingestion API',
            'description' => 'The programmatic entry point sibling addons and the host application use to hand contacts to LeadHub.',
        ],
        'features_merge' => [
            'label' => 'Contact merge',
            'description' => 'Merging two contacts into one. Merging is not reversible; the losing record is folded into the surviving one.',
        ],
        'features_companies' => [
            'label' => 'Companies',
            'description' => 'The company records contacts can belong to, and the Companies screen.',
        ],
        'features_tasks' => [
            'label' => 'Tasks',
            'description' => 'The task list, assignment and the open-task digest.',
        ],
        'features_pipelines' => [
            'label' => 'Pipelines',
            'description' => 'Opportunities, stages and the deal board.',
        ],
        'features_scoring' => [
            'label' => 'Lead scoring',
            'description' => 'Engagement scores per contact and the rule table under LeadHub → Scoring. Requires the database storage driver.',
        ],
        'features_webhooks' => [
            'label' => 'Webhooks',
            'description' => 'LeadHub\'s own outbound webhook hooks.',
        ],
        'features_crm_destinations' => [
            'label' => 'CRM destinations',
            'description' => 'Pushing contacts to external systems. The destinations themselves and their credentials stay in config/leadhub.php; this only decides whether the syncs run.',
        ],
        'features_webhook_manager' => [
            'label' => 'Webhook Manager bridge',
            'description' => 'Wire LeadHub\'s lifecycle events into goldnead/statamic-webhook-manager. Has no effect unless that addon is installed.',
        ],
        'features_click_tracking' => [
            'label' => 'Email link-click tracking',
            'description' => 'Rewrite links in marketing e-mails to a signed redirect that records the click. Consent first: a click is only ever scored for a contact who has given marketing consent. German installs must obtain that consent before switching this on.',
        ],

        'exports_queue_threshold' => [
            'label' => 'Queue threshold',
            'description' => 'Above this many contacts an export is processed as a queued job instead of synchronously. A value no worker can reach means large exports are enqueued and never finish.',
        ],
        'exports_disk' => [
            'label' => 'Disk',
            'description' => 'The filesystem disk export files are written to. The list is the application\'s configured disks.',
        ],
        'exports_directory' => [
            'label' => 'Directory',
            'description' => 'The path on that disk. Files already written stay where they are.',
        ],

        'scoring_default' => [
            'label' => 'Default points',
            'description' => 'Points awarded for an activity that no rule lists. Negative values are allowed, for making unlisted activity cost points rather than earn them.',
        ],
        'scoring_timeline' => [
            'label' => 'Record score changes on the timeline',
            'description' => 'Write a timeline entry on every real score change. Off leaves a contact with a score and no history; the score-changed event fires either way.',
        ],

        'click_tracking_dedupe_window' => [
            'label' => 'Dedupe window (minutes)',
            'description' => 'Repeated clicks of the same link by the same contact inside this window are recorded once and scored once. Zero scores every click.',
        ],
        'click_tracking_ignored_query_parameters' => [
            'label' => 'Ignored query parameters',
            'description' => 'One per line. Parameters sending services append when they forward a click, excluded from the signature check so the click still scores. Only meaningless parameters belong here: the redirect target rides in the query, and the reserved parameters are refused even when listed.',
        ],

        'notifications_new_lead' => [
            'label' => 'New lead',
            'description' => 'Alert the configured recipients when a brand-new lead arrives unassigned.',
        ],
        'notifications_on_assignment' => [
            'label' => 'Lead assignment',
            'description' => 'Tell the owner when a lead is assigned to them.',
        ],
        'notifications_on_task_assignment' => [
            'label' => 'Task assignment',
            'description' => 'Tell the new assignee when a task is handed to them. Runs through goldnead/statamic-notifications and is inert when that addon is absent. Assigning a task to yourself never notifies.',
        ],
        'notifications_digest_enabled' => [
            'label' => 'Daily digest',
            'description' => 'The daily e-mail per owner listing their due and overdue follow-ups. Sent by the scheduler, so it only goes out where Laravel\'s scheduler runs.',
        ],
    ],

    'environment' => [
        'heading' => 'Owned by the deployment',
        'description' => 'These come from environment variables and are shown so they can be checked, not offered so they can be half-changed: a value stored here would be overtaken by the next deploy. The storage driver is more than that. It decides where contacts, events and notes live, and switching it has to move them first: php artisan leadhub:storage:migrate.',
        'storage_driver' => 'Storage driver',
        'flat_path' => 'Flat-file path',
        'notifications' => 'Notifications',
        'notify_recipients' => 'New-lead recipients',
        'digest_time' => 'Digest time',
        'digest_recipients' => 'Digest fallback recipients',
        'on' => 'On',
        'off' => 'Off',
        'none' => 'None',
    ],
];
