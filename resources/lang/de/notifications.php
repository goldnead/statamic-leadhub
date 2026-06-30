<?php

return [
    'view_contact' => 'Kontakt ansehen',
    'company' => 'Firma: :company',
    'source' => 'Quelle: :source',

    'new_lead' => [
        'subject' => 'Neuer Lead: :name',
        'greeting' => 'Neuer Lead in LeadHub',
        'line' => ':name (:email) ist gerade eingegangen.',
    ],

    'assigned' => [
        'subject' => 'Lead dir zugewiesen: :name',
        'greeting' => 'Dir wurde ein Lead zugewiesen',
        'line' => 'Du bist jetzt verantwortlich für :name.',
    ],

    'digest' => [
        'subject' => 'Follow-ups: :overdue überfällig, :today heute fällig',
        'greeting' => 'Deine LeadHub-Follow-ups',
        'line' => 'Du hast :overdue überfällige und :today heute fällige.',
        'overdue_prefix' => 'Überfällig:',
        'today_prefix' => 'Heute:',
        'action' => 'Follow-ups öffnen',
    ],
];
