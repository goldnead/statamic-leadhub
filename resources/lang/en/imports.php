<?php

return [
    'pasted_name' => 'Pasted from a spreadsheet',
    'timeline_summary' => 'Imported from :file',
    'custom_field' => 'Custom field: :field',
    'disclaimer' => [
        'duplicates' => 'Duplicates are merged: an address that already exists is updated, never created twice, and a field that already has a value is not overwritten.',
        'no_mail' => 'Importing sends no confirmation email and no notification, because the people on your list are not asked again.',
        'permission' => 'Make sure everyone on the list actually signed up and gave you permission to email them.',
    ],
    'targets' => [
        'email' => 'Email',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'full_name' => 'Full name',
        'phone' => 'Phone',
        'company' => 'Company',
        'postal_code' => 'Postal code',
        'country' => 'Country (2-letter code)',
        'status' => 'Status',
        'consent' => 'Marketing consent',
        'tags' => 'Tags (separated by , ; or |)',
        'custom_new' => 'Create as a new custom field',
    ],
    'status' => [
        'staged' => 'Waiting for the mapping',
        'queued' => 'Queued',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],
    'reasons' => [
        'missing_email' => 'No email address',
        'invalid_email' => 'Not a valid email address',
        'invalid_country' => 'Country is not a 2-letter code',
        'invalid_postal_code' => 'Postal code is longer than 16 characters',
        'unknown_status' => 'Unknown status',
        'opted_out' => 'Address opted out, left untouched',
        'exception' => 'Could not be saved',
    ],
    'errors' => [
        'bad_target' => 'A column is mapped to a field that does not exist.',
        'duplicate_target' => 'Each contact field can be filled from one column only.',
        'email_required' => 'Map one column to Email.',
        'empty_file' => 'The file has no header row.',
    ],
    'report' => [
        'row' => 'Row',
        'type' => 'Result',
        'reason' => 'Reason',
        'types' => [
            'skipped' => 'Skipped',
            'error' => 'Error',
        ],
    ],
    'flashes' => [
        'started' => 'Import queued. Contacts appear as the queue works through the file.',
    ],
];
