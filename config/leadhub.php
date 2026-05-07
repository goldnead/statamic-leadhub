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
        'attribution' => false,
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

];
