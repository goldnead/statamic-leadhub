<?php

return [
    'title' => 'Pipelines',
    'singular' => 'Pipeline',
    'moved' => 'Opportunity verschoben.',
    'created' => 'Pipeline erstellt.',
    'empty' => 'Keine offenen Opportunities in dieser Stufe.',

    // Abgeschlossene Opportunities auf dem Board
    'closed_filter' => 'Abgeschlossene Deals',
    'closed_none' => 'Nur offene',
    'closed_days' => 'Abgeschlossen in den letzten :days Tagen',
    'closed_all' => 'Alle abgeschlossenen Deals',

    // Stufenverwaltung
    'stage_created' => 'Stufe hinzugefügt.',
    'stage_updated' => 'Stufe aktualisiert.',
    'stage_deleted' => 'Stufe gelöscht.',
    'stages_reordered' => 'Reihenfolge der Stufen gespeichert.',
    'stage_not_empty' => 'In dieser Stufe liegen noch Opportunities. Verschiebe sie zuerst in eine andere Stufe.',
    'stage_last' => 'Eine Pipeline braucht mindestens eine Stufe.',

    // Opportunity anlegen / bearbeiten / löschen
    'opportunity' => 'Opportunity',
    'opportunity_new' => 'Neue Opportunity',
    'opportunity_edit' => 'Opportunity bearbeiten',
    'opportunity_created' => 'Opportunity angelegt.',
    'opportunity_updated' => 'Opportunity aktualisiert.',
    'opportunity_deleted' => 'Opportunity gelöscht.',
    'opportunity_has_tasks' => 'An dieser Opportunity hängen noch :count Aufgabe(n). Erledige oder lösche sie zuerst.',

    // Aufgabenliste auf der Opportunity-Maske (v1.10.0). Die Absage oben nannte
    // bisher eine Zahl, die sich auf keinem Bildschirm nachprüfen ließ.
    'opportunity_tasks_empty' => 'An dieser Opportunity hängt noch keine Aufgabe.',
    'opportunity_tasks_hint' => 'Gezählt wird jede Aufgabe, erledigte eingeschlossen — solange eine daran hängt, wird das Löschen der Opportunity verweigert.',

    'validation' => [
        'contact_not_found' => 'Diesen Kontakt gibt es nicht.',
        'company_not_found' => 'Diese Firma gibt es nicht.',
        'pipeline_not_found' => 'Diese Pipeline gibt es nicht.',
        'stage_not_in_pipeline' => 'Diese Stufe gehört nicht zur gewählten Pipeline.',
    ],
];
