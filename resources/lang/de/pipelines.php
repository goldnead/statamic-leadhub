<?php

return [
    'title' => 'Pipelines',
    'singular' => 'Pipeline',
    'moved' => 'Opportunity verschoben.',
    'already_in_stage' => 'Der Deal steht bereits in dieser Phase.',
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

    // Die Detailseite einer Opportunity (v2.4.0). Vorher gab es keinen Ort, an
    // dem ein einzelner Deal stand: jeder Link im CP führte aufs Board.
    'back_to_board' => 'Zum Board',
    'last_activity' => 'Letzte Aktivität',
    'closed_at' => 'Abgeschlossen',
    'won_at' => 'Gewonnen',
    'lost_at' => 'Verloren',
    'current_stage' => 'Aktuelle Stufe',
    'change_stage' => 'Stufe wechseln',
    'change_stage_note' => 'Notiz',
    'change_stage_hint' => 'Die Notiz ist das Einzige, was später erklärt, warum der Deal weitergegangen ist. Sie steht im Verlauf und sonst nirgends.',
    'change_stage_submit' => 'Wechseln',

    // Der Verlauf, gelesen aus leadhub_stage_transitions.
    'history' => 'Verlauf',
    'history_created' => 'Angelegt',
    'history_hint' => 'Die Dauer ist die Zeit bis zum nächsten Wechsel; beim obersten Eintrag läuft sie bis jetzt.',
    'history_hint_closed' => 'Die Dauer ist die Zeit bis zum nächsten Wechsel. Dieser Deal ist abgeschlossen, die letzte endet also dort.',
    'stage_removed' => 'Gelöschte Stufe',
    'actor_system' => 'System',
    'actor_unknown' => 'Unbekannt',
    'duration_running' => 'läuft',
    'duration_under_minute' => 'unter einer Minute',
    'duration_minutes' => ':count Min.',
    'duration_hours' => ':count Std.',
    'duration_days' => ':count Tage',

    'validation' => [
        'contact_not_found' => 'Diesen Kontakt gibt es nicht.',
        'company_not_found' => 'Diese Firma gibt es nicht.',
        'pipeline_not_found' => 'Diese Pipeline gibt es nicht.',
        'stage_not_in_pipeline' => 'Diese Stufe gehört nicht zur gewählten Pipeline.',
    ],
];
