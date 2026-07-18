<?php

return [
    'collection_title' => 'Email Templates',
    'blueprint_title' => 'Email Template',
    'tab_content' => 'Content',
    'field_title' => 'Title',
    'field_subject' => 'Subject',
    'field_subject_instructions' => 'The email subject line. Supports merge variables such as {{ contact.first_name }}.',
    'field_body' => 'Body (HTML)',
    'field_body_instructions' => 'Raw HTML for the email body. Kept as plain HTML (not rich text) for reliable rendering in email clients.',
    'field_plain_text' => 'Plain text',
    'field_plain_text_instructions' => 'Optional plain-text alternative for clients that do not render HTML.',
    'field_description' => 'Description',
    'field_description_instructions' => 'Internal note. Not sent to recipients.',
];
