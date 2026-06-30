<?php

return [
    'view_contact' => 'View contact',
    'company' => 'Company: :company',
    'source' => 'Source: :source',

    'new_lead' => [
        'subject' => 'New lead: :name',
        'greeting' => 'New lead in LeadHub',
        'line' => ':name (:email) just came in.',
    ],

    'assigned' => [
        'subject' => 'Lead assigned to you: :name',
        'greeting' => 'A lead was assigned to you',
        'line' => 'You are now the owner of :name.',
    ],

    'digest' => [
        'subject' => 'Follow-ups: :overdue overdue, :today due today',
        'greeting' => 'Your LeadHub follow-ups',
        'line' => 'You have :overdue overdue and :today due today.',
        'overdue_prefix' => 'Overdue:',
        'today_prefix' => 'Today:',
        'action' => 'Open follow-ups',
    ],
];
