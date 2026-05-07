<?php

return [
    'index_title' => 'Formular-Zuordnungen',
    'index_description' => 'Lege fest, welche Statamic-Formulare LeadHub-Kontakte erzeugen und wie ihre Felder zugeordnet werden.',
    'enabled' => 'LeadHub aktiviert',
    'disabled' => 'LeadHub deaktiviert',
    'configure' => 'Konfigurieren',
    'last_processed' => 'Zuletzt verarbeitet',
    'never_processed' => 'Nie',
    'mapping' => [
        'enable' => 'LeadHub für dieses Formular aktivieren',
        'email_field' => 'E-Mail-Feld',
        'first_name_field' => 'Vorname-Feld',
        'last_name_field' => 'Nachname-Feld',
        'full_name_field' => 'Vollständiger-Name-Feld',
        'phone_field' => 'Telefon-Feld',
        'company_field' => 'Firma-Feld',
        'message_field' => 'Nachricht-Feld',
        'consent_field' => 'Einwilligung-Feld',
        'tags_field' => 'Tags-Feld',
        'default_status' => 'Standard-Status',
        'default_source' => 'Standard-Quelle',
        'default_tags' => 'Standard-Tags',
        'attach_full_submission' => 'Volle Submission an Timeline anhängen',
        'save' => 'Zuordnung speichern',
    ],
    'errors' => [
        'email_required_when_enabled' => 'Du musst ein E-Mail-Feld auswählen, wenn LeadHub für dieses Formular aktiv ist.',
    ],
    'flashes' => [
        'saved' => 'Formular-Zuordnung gespeichert.',
    ],
];
