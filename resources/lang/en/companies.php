<?php

return [
    'unnamed' => 'Unnamed company',
    'title' => 'Companies',
    'singular' => 'Company',

    // Create / edit / delete
    'new' => 'New company',
    'edit' => 'Edit company',
    'created' => 'Company created.',
    'updated' => 'Company updated.',
    'deleted' => 'Company deleted.',

    // Deletion is refused while records still hang on the company — the same
    // rule the pipeline stages follow. The message has to say what is in the
    // way, otherwise a refused delete reads as a broken button.
    'delete_has_contacts' => 'This company still has :count linked contact(s). Unlink them first.',
    'delete_has_opportunities' => 'This company still has :count opportunity(s). Reassign or delete them first.',

    'validation' => [
        'duplicate_name' => 'A company with this name already exists.',
        'duplicate_domain' => 'A company with this website already exists (:domain).',
    ],
];
