<?php

return [
    'title' => 'Scoring',
    'intro' => 'Jede bewertete Aktivität erhöht den Engagement-Score eines Kontakts. Regeln werden pro Brand gespeichert — Änderungen hier gelten nur für diese Brand.',

    'event_type' => 'Aktivitätstyp',
    'label' => 'Beschreibung',
    'points' => 'Punkte',
    'enabled' => 'Aktiv',

    'catch_all' => 'Alles Übrige',
    'catch_all_label' => 'Jede Aktivität ohne eigene Regel',
    'catch_all_hint' => 'Die Regel für Aktivitätstypen ohne eigene Regel. Das war bisher `scoring.default`.',

    'new_rule' => 'Neue Regel',
    'create' => 'Regel anlegen',
    'save' => 'Speichern',
    'cancel' => 'Abbrechen',
    'edit' => 'Bearbeiten',
    'delete' => 'Löschen',

    'event_type_placeholder' => 'z. B. purchase.completed',
    'label_placeholder' => 'Worum es bei dieser Aktivität geht',
    'known_types' => 'Bekannte Aktivitätstypen',

    'fallback_notice_title' => 'Diese Brand hat noch keine Regeln',
    'fallback_notice_body' => 'Solange keine Regel existiert, rechnet das Scoring unverändert mit der Punktetabelle aus config/leadhub.php. Für diese Brand hat sich nichts geändert. :command übernimmt diese Werte, oder du legst die Regeln unten von Hand an.',
    'config_table' => 'Aktuell in config/leadhub.php',
    'config_default' => 'Alles Übrige: :points',

    'delete_title' => 'Regel löschen',
    'delete_body' => 'Diese Regel löschen? Nichts verweist darauf, es geht also nichts kaputt — aber dieser Aktivitätstyp wird ab jetzt über die Auffangregel bewertet, und bereits vergebene Punkte bleiben, wo sie sind.',
    'delete_body_last' => 'Das ist die letzte Regel dieser Brand. Nach dem Löschen rechnet das Scoring wieder mit der Punktetabelle aus config/leadhub.php.',

    'disabled_hint' => 'Inaktive Regeln werden vollständig ignoriert — die Aktivität fällt auf die Auffangregel zurück, als gäbe es die Regel nicht.',
    'no_recompute' => 'Eine geänderte Regel wirkt nur auf künftige Aktivität. Bereits vergebene Punkte werden nicht neu berechnet.',

    'errors' => [
        'duplicate' => 'Für diesen Aktivitätstyp existiert in dieser Brand bereits eine Regel.',
        'event_type_format' => 'Bitte das Handle des Aktivitätstyps angeben, z. B. purchase.completed. Erlaubt sind Buchstaben, Ziffern, Punkt, Doppelpunkt, Unterstrich und Bindestrich.',
    ],

    'flashes' => [
        'created' => 'Regel angelegt.',
        'updated' => 'Regel aktualisiert.',
        'deleted' => 'Regel gelöscht.',
    ],
];
