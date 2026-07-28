<?php

return [
    'title' => 'Aufgaben',
    'singular' => 'Aufgabe',
    'completed' => 'Aufgabe erledigt.',
    'filters' => [
        'open' => 'Offen',
        'today' => 'Heute fällig',
        'overdue' => 'Überfällig',
        'done' => 'Erledigt',
    ],

    // Anlegen / Bearbeiten / Löschen
    'new' => 'Neue Aufgabe',
    'edit' => 'Aufgabe bearbeiten',
    'created' => 'Aufgabe angelegt.',
    'updated' => 'Aufgabe aktualisiert.',
    'deleted' => 'Aufgabe gelöscht.',

    // Zuweisung
    'assignee' => 'Bearbeiter',
    'unassigned' => 'Nicht zugewiesen',
    'any_assignee' => 'Alle',
    'mine' => 'Meine Aufgaben',

    'priorities' => [
        'low' => 'Niedrig',
        'normal' => 'Normal',
        'high' => 'Hoch',
    ],

    'validation' => [
        'contact_not_found' => 'Diesen Kontakt gibt es nicht.',
        'assignee_not_assignable' => 'Diesem Nutzer lassen sich keine LeadHub-Aufgaben zuweisen.',
    ],
];
