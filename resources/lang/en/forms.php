<?php

return [
    'index_title' => 'Form Mappings',
    'index_description' => 'Configure which Statamic forms create LeadHub contacts and how their fields map.',
    'enabled' => 'LeadHub enabled',
    'disabled' => 'LeadHub disabled',
    'configure' => 'Configure',
    'last_processed' => 'Last processed',
    'never_processed' => 'Never',
    'mapping' => [
        'enable' => 'Enable LeadHub for this form',
        'email_field' => 'Email field',
        'first_name_field' => 'First name field',
        'last_name_field' => 'Last name field',
        'full_name_field' => 'Full name field',
        'phone_field' => 'Phone field',
        'company_field' => 'Company field',
        'message_field' => 'Message field',
        'consent_field' => 'Consent field',
        'tags_field' => 'Tags field',
        'default_status' => 'Default status',
        'default_source' => 'Default source',
        'default_tags' => 'Default tags',
        'attach_full_submission' => 'Attach full submission to timeline',
        'save' => 'Save mapping',
    ],
    'errors' => [
        'email_required_when_enabled' => 'You must select an email field when LeadHub is enabled for this form.',
    ],
    'flashes' => [
        'saved' => 'Form mapping saved.',
    ],
];
