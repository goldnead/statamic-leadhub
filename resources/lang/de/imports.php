<?php

return [
    'pasted_name' => 'Aus einer Tabelle eingefügt',
    'timeline_summary' => 'Importiert aus :file',
    'custom_field' => 'Eigenes Feld: :field',
    'disclaimer' => [
        'duplicates' => 'Dubletten werden zusammengeführt: Eine Adresse, die es schon gibt, wird aktualisiert und nie doppelt angelegt. Ein Feld, das schon einen Wert hat, wird nicht überschrieben.',
        'no_mail' => 'Der Import verschickt keine Bestätigungsmail und keine Benachrichtigung, weil die Leute auf deiner Liste nicht noch einmal gefragt werden.',
        'sync' => 'Importierte Kontakte zählen in deinen Segmenten und werden, wenn du ein CRM angebunden hast, wie jeder Kontakt dorthin übertragen. Ausgehende Webhooks werden nicht für jeden importierten Kontakt ausgelöst.',
        'permission' => 'Stelle sicher, dass sich alle auf der Liste wirklich eingetragen und dir erlaubt haben, sie per E-Mail anzuschreiben.',
    ],
    'targets' => [
        'email' => 'E-Mail',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'full_name' => 'Vollständiger Name',
        'phone' => 'Telefon',
        'company' => 'Firma',
        'postal_code' => 'Postleitzahl',
        'country' => 'Land (2-Buchstaben-Code)',
        'status' => 'Status',
        'consent' => 'Marketing-Einwilligung',
        'tags' => 'Tags (getrennt mit , ; oder |)',
        'custom_new' => 'Als neues eigenes Feld anlegen',
    ],
    'status' => [
        'staged' => 'Wartet auf die Zuordnung',
        'queued' => 'In der Warteschlange',
        'running' => 'Läuft',
        'completed' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
    ],
    'reasons' => [
        'missing_email' => 'Keine E-Mail-Adresse',
        'invalid_email' => 'Keine gültige E-Mail-Adresse',
        'invalid_country' => 'Land ist kein 2-Buchstaben-Code',
        'invalid_postal_code' => 'Postleitzahl ist länger als 16 Zeichen',
        'unknown_status' => 'Unbekannter Status',
        'opted_out' => 'Adresse hat sich abgemeldet, unverändert gelassen',
        'exception' => 'Konnte nicht gespeichert werden',
    ],
    'errors' => [
        'bad_target' => 'Eine Spalte ist einem Feld zugeordnet, das es nicht gibt.',
        'duplicate_target' => 'Jedes Kontaktfeld kann nur aus einer Spalte kommen.',
        'email_required' => 'Ordne eine Spalte der E-Mail zu.',
        'empty_file' => 'Die Datei hat keine Kopfzeile.',
    ],
    'report' => [
        'row' => 'Zeile',
        'type' => 'Ergebnis',
        'reason' => 'Grund',
        'types' => [
            'skipped' => 'Übersprungen',
            'error' => 'Fehler',
        ],
    ],
    'flashes' => [
        'started' => 'Import eingereiht. Die Kontakte erscheinen, sobald die Warteschlange die Datei abarbeitet.',
    ],
];
