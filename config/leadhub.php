<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lead Statuses
    |--------------------------------------------------------------------------
    |
    | The list of available lead statuses. The keys are stored in the database;
    | the values are the human-readable labels shown in the Control Panel.
    | You may add/remove statuses, but be aware that removing a status
    | does not migrate existing contacts off it.
    |
    */

    'statuses' => [
        'new' => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'won' => 'Won',
        'lost' => 'Lost',
        'archived' => 'Archived',
    ],

    'default_status' => 'new',

    /*
    |--------------------------------------------------------------------------
    | Field Overwrite Behavior
    |--------------------------------------------------------------------------
    |
    | When a new submission arrives for an existing contact, should non-empty
    | fields on the contact be overwritten with new values from the form?
    | Default: false — manually maintained fields are protected.
    |
    */

    'overwrite_existing_fields_from_submissions' => false,

    /*
    |--------------------------------------------------------------------------
    | Submission Payload Storage
    |--------------------------------------------------------------------------
    |
    | When true, the full submission payload (after redaction) is attached
    | to the timeline event. When false, only the mapped fields are stored.
    |
    */

    'store_full_submission_payload' => true,

    /*
    |--------------------------------------------------------------------------
    | Sensitive Payload Redaction
    |--------------------------------------------------------------------------
    |
    | Field names matching these patterns will be redacted before storing
    | in the timeline payload. Comparison is case-insensitive substring match.
    |
    */

    'timeline_payload_redaction' => [
        'password',
        'passwort',
        'token',
        'secret',
        'api_key',
        'credit_card',
        'card_number',
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Settings
    |--------------------------------------------------------------------------
    |
    | When the number of contacts being exported exceeds queue_threshold,
    | the export will be processed as a queued job instead of synchronously.
    |
    */

    'exports' => [
        'queue_threshold' => 1000,
        'disk' => 'local',
        'directory' => 'leadhub/exports',
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Toggle MVP features on/off. Pro features default to false.
    |
    */

    'features' => [
        'manual_contacts' => true,
        'csv_export' => true,
        'webhooks' => false,
        'crm_destinations' => false,
        'attribution' => true,

        // Auto-wire LeadHub's lifecycle events into the optional
        // goldnead/statamic-webhook-manager addon when it is installed. Has no
        // effect unless that addon is present; set to false to opt out.
        'webhook_manager' => true,

        // CRM-core modules (eloquent driver only). All default off so existing
        // installs keep the lightweight lead-capture behaviour until opted in.
        'ingestion' => true,
        'scoring' => false,
        'merge' => true,
        'companies' => false,
        'tasks' => false,
        'pipelines' => false,

        // Email link-click tracking for lead scoring. Opt-in (default off) and
        // consent-first: even when enabled, a click is only ever scored when the
        // contact has given marketing consent (see the `click_tracking` block
        // below). Transactional-only recipients are never tracked. GDPR: German
        // installs must obtain marketing consent before enabling this.
        'click_tracking' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead Scoring
    |--------------------------------------------------------------------------
    |
    | When features.scoring is enabled, each scored activity adds points to a
    | contact's engagement_score. Map an activity/event type to a point value;
    | anything not listed uses the `default`.
    |
    */

    'scoring' => [
        'default' => 1,
        'events' => [
            'submission_received' => 2,
            'LeadHubSubmissionAttached' => 2,
            'purchase.completed' => 10,
            'booking.confirmed' => 5,
            // A tracked email link click. Only awarded when features.scoring is
            // on AND the contact has marketing consent (see click_tracking).
            'email_link_clicked' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Link-Click Tracking
    |--------------------------------------------------------------------------
    |
    | Rewrites <a href> links in marketing emails to a signed redirect that
    | records an `email_link_clicked` timeline event and awards engagement
    | points before forwarding the recipient to the target URL.
    |
    | CONSENT / GDPR: enabling features.click_tracking is not enough — a click is
    | only ever scored when the resolved contact has given marketing consent
    | (contact.consent === true and do_not_contact === false). Transactional-only
    | contacts are never tracked. The redirect itself always succeeds, even when
    | scoring is skipped, so recipients always reach their link.
    |
    */

    'click_tracking' => [
        // Repeated clicks of the same link by the same contact within this many
        // minutes are recorded once and scored once (dedupe window).
        'dedupe_window' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attribution (UTM) Capture
    |--------------------------------------------------------------------------
    |
    | When features.attribution is enabled, these submission field handles are
    | captured onto the contact (first-touch — never overwritten by later
    | submissions). Point your public form's hidden fields at the same handles
    | (populated from the URL query string and document.referrer).
    |
    | Map: contact column => submission field handle.
    |
    */

    'attribution' => [
        'fields' => [
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
            'utm_term' => 'utm_term',
            'utm_content' => 'utm_content',
            'referrer' => 'referrer',
            'landing_page' => 'landing_page',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | E-mail alerts for new leads, assignment, and a daily follow-up digest.
    |
    | - recipients: who gets the "new lead" alert for UNassigned leads
    |   (assigned leads always notify their owner). Comma-separated emails.
    | - digest: a daily e-mail per owner listing their due + overdue follow-ups.
    |   Schedule it by running Laravel's scheduler (php artisan schedule:run).
    |
    */

    'notifications' => [
        'enabled' => env('LEADHUB_NOTIFICATIONS', true),

        // Notify on a brand-new lead.
        'new_lead' => true,
        // Notify the owner when a lead is assigned to them.
        'on_assignment' => true,

        // Default recipients (emails) for new, unassigned leads.
        'recipients' => array_filter(array_map(
            'trim',
            explode(',', (string) env('LEADHUB_NOTIFY_EMAILS', ''))
        )),

        'digest' => [
            'enabled' => true,
            'time' => env('LEADHUB_DIGEST_TIME', '08:00'),
            // Who receives follow-ups for UNassigned contacts in the digest.
            'fallback_recipients' => array_filter(array_map(
                'trim',
                explode(',', (string) env('LEADHUB_DIGEST_EMAILS', ''))
            )),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM Destinations
    |--------------------------------------------------------------------------
    |
    | Push contacts to external systems when they are created / updated / their
    | status changes (enable features.crm_destinations first). Each destination
    | has a driver (hubspot, brevo, webhook — or your own via
    | DestinationManager::extend()), credentials, and the triggers it listens
    | for. Syncs run on the queue and are logged under LeadHub → Sync log.
    |
    | The `webhook` driver POSTs the contact as JSON to any URL (Zapier, Make,
    | n8n, or a webhook addon), optionally HMAC-signed.
    |
    */

    'crm' => [
        'destinations' => [
            // 'hubspot' => [
            //     'driver' => 'hubspot',
            //     'enabled' => true,
            //     'token' => env('LEADHUB_HUBSPOT_TOKEN'),
            //     'triggers' => ['created', 'status_changed'],
            // ],
            // 'brevo' => [
            //     'driver' => 'brevo',
            //     'enabled' => true,
            //     'api_key' => env('LEADHUB_BREVO_KEY'),
            //     'list_id' => env('LEADHUB_BREVO_LIST'),
            // ],
            // 'zapier' => [
            //     'driver' => 'webhook',
            //     'enabled' => true,
            //     'url' => env('LEADHUB_WEBHOOK_URL'),
            //     'secret' => env('LEADHUB_WEBHOOK_SECRET'),
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Normalization
    |--------------------------------------------------------------------------
    |
    | How should email addresses be normalized for deduplication?
    | - trim: remove whitespace
    | - lowercase: convert to lowercase
    | The original email is always preserved in the `email` column.
    |
    */

    'email_normalization' => [
        'trim' => true,
        'lowercase' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | LeadHub supports two storage drivers:
    |
    | - "eloquent": uses dedicated database tables (default).
    |   Recommended for any project with >500 contacts or >5k timeline events.
    |   Requires running `php artisan migrate`.
    |
    | - "flat": stores leads as YAML files under content/leadhub/, with a
    |   Stache-style JSON index for fast lookups. True to Statamic's
    |   flat-file philosophy. Best for small lead volumes (≤500 contacts,
    |   ≤10k timeline events). Migrations are NOT required.
    |
    | Switch drivers by changing this value (or LEADHUB_DRIVER env var) and
    | running `php artisan leadhub:storage:migrate --from=eloquent --to=flat`
    | or vice versa to move existing data.
    |
    */

    'storage' => [
        'driver' => env('LEADHUB_DRIVER', 'eloquent'),

        'flat' => [
            // Where lead YAML files live (typically inside the site's content dir).
            'path' => env('LEADHUB_FLAT_PATH', base_path('content/leadhub')),

            // Disk + directory for the JSON indexes.
            'index_disk' => env('LEADHUB_INDEX_DISK', 'local'),
            'index_path' => env('LEADHUB_INDEX_PATH', 'leadhub/index'),
        ],
    ],

];
