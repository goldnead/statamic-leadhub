<?php

/*
 * Die bearbeitbare Einstellungsseite.
 *
 * Schlüsselgleich mit resources/lang/en/settings.php; TranslationParityTest
 * prüft das in beide Richtungen. Die Feldschlüssel sind der Config-Pfad mit
 * ersetzten Punkten (`exports.queue_threshold` → `exports_queue_threshold`),
 * weil ein Punkt im Sprachschlüssel für den Übersetzer ein Pfadtrenner ist.
 */

return [

    // Das Seitenbeiwerk, das hier stand — title, intro, save, saving, saved,
    // unavailable, default_placeholder und die nur lesbare Statusliste —, ist
    // mit dem Bildschirm entfallen, zu dem es gehörte. Das Formular ist der
    // gemeinsame in goldnead/statamic-brand-context und bringt sein eigenes
    // Beiwerk mit; die Status-Handles stehen jetzt auf dem LeadHub-Dashboard
    // neben den Zahlen.
    'publish_command' => 'php artisan vendor:publish --tag=leadhub-config',

    'groups' => [
        'behavior' => [
            'title' => 'Verhalten',
            'description' => 'Was passiert, wenn eine Formular-Submission eintrifft. Gilt für Submissions nach der Änderung; bereits angelegte Kontakte behalten ihre Felder.',
        ],
        'redaction' => [
            'title' => 'Payload-Redaktion',
            'description' => 'Feldnamen, die hierauf passen, werden ersetzt, bevor die Submission-Payload in die Timeline geschrieben wird. Verglichen wird als Teilzeichenkette ohne Rücksicht auf Groß- und Kleinschreibung, ein Name greift also in jeder Verschachtelungstiefe. Eine leere Liste bedeutet: Payloads werden genau so gespeichert, wie sie ankommen.',
        ],
        'features' => [
            'title' => 'Feature-Flags',
            'description' => 'Welche Teile des Addons aktiv sind. Ein abgeschaltetes Modul verliert seine Control-Panel-Ansichten, seinen Navigationseintrag und seine Laufzeit-Verdrahtung; die bereits erzeugten Daten bleiben liegen.',
        ],
        'exports' => [
            'title' => 'Exporte',
            'description' => 'Wohin CSV-Exporte geschrieben werden und ab wann sie nicht mehr im Browser laufen, sondern über die Queue.',
        ],
        'scoring' => [
            'title' => 'Lead-Scoring',
            'description' => 'Die Rückfallwerte für eine Marke, die noch keine eigenen Zeilen in der Scoring-Tabelle hat. Die Regeln je Ereignis werden unter LeadHub → Scoring gepflegt, pro Marke, und haben Vorrang.',
        ],
        'click_tracking' => [
            'title' => 'Klick-Tracking in E-Mails',
            'description' => 'Wie getrackte Klicks gezählt werden. Wirkt nur bei eingeschaltetem Klick-Tracking, und gewertet wird ein Klick ausschließlich bei Kontakten mit Marketing-Einwilligung.',
        ],
        'notifications' => [
            'title' => 'Benachrichtigungen',
            'description' => 'Welche internen E-Mails rausgehen. Zusätzlich hängen alle am Benachrichtigungs-Schalter der Umgebung, der auf der LeadHub-Übersicht steht.',
        ],
    ],

    'fields' => [

        'default_status' => [
            'label' => 'Standard-Lead-Status',
            'description' => 'Der Status, mit dem ein neu erfasster Kontakt startet. Die Auswahl ist die Statusliste aus config/leadhub.php; die Handles stehen auf der LeadHub-Übersicht.',
        ],
        'overwrite_existing_fields_from_submissions' => [
            'label' => 'Bestehende Felder aus Submissions überschreiben',
            'description' => 'Wenn eine neue Submission zu einem bereits vorhandenen Kontakt eintrifft: Felder überschreiben, die schon einen Wert tragen. Aus schützt von Hand gepflegte Felder.',
        ],
        'store_full_submission_payload' => [
            'label' => 'Vollständige Submission-Payload speichern',
            'description' => 'Die ganze Payload (nach der Redaktion) an das Timeline-Ereignis hängen. Aus speichert nur die zugeordneten Felder.',
        ],
        'timeline_payload_redaction' => [
            'label' => 'Redigierte Feldnamen',
            'description' => 'Ein Name pro Zeile.',
        ],

        'features_manual_contacts' => [
            'label' => 'Manuelle Kontakte',
            'description' => 'Einen Kontakt von Hand anlegen, statt auf eine Formular-Submission zu warten.',
        ],
        'features_csv_export' => [
            'label' => 'CSV-Export',
            'description' => 'Der Export-Knopf in der Kontaktliste. Aus schließt den Weg, über den Kontaktdaten die Installation verlassen.',
        ],
        'features_attribution' => [
            'label' => 'Attribution erfassen',
            'description' => 'UTM-Parameter, Referrer und Landingpage aus einer Submission auf den Kontakt übernehmen. Nur beim ersten Kontakt, später nie überschrieben.',
        ],
        'features_ingestion' => [
            'label' => 'Ingestion-API',
            'description' => 'Der programmatische Einstieg, über den Schwester-Addons und die Host-Anwendung Kontakte an LeadHub übergeben.',
        ],
        'features_merge' => [
            'label' => 'Kontakte zusammenführen',
            'description' => 'Zwei Kontakte zu einem verschmelzen. Nicht umkehrbar: der unterlegene Datensatz geht im verbleibenden auf.',
        ],
        'features_companies' => [
            'label' => 'Firmen',
            'description' => 'Die Firmendatensätze, denen Kontakte zugeordnet werden, und die Firmen-Ansicht.',
        ],
        'features_tasks' => [
            'label' => 'Aufgaben',
            'description' => 'Aufgabenliste, Zuweisung und der Digest offener Aufgaben.',
        ],
        'features_pipelines' => [
            'label' => 'Pipelines',
            'description' => 'Opportunities, Stufen und das Deal-Board.',
        ],
        'features_scoring' => [
            'label' => 'Lead-Scoring',
            'description' => 'Engagement-Punkte je Kontakt und die Regeltabelle unter LeadHub → Scoring. Braucht den Datenbank-Treiber.',
        ],
        'features_webhooks' => [
            'label' => 'Webhooks',
            'description' => 'LeadHubs eigene ausgehende Webhook-Haken.',
        ],
        'features_crm_destinations' => [
            'label' => 'CRM-Ziele',
            'description' => 'Kontakte in externe Systeme schieben. Die Ziele selbst und ihre Zugangsdaten bleiben in config/leadhub.php; hier wird nur entschieden, ob die Syncs laufen.',
        ],
        'features_webhook_manager' => [
            'label' => 'Webhook-Manager-Brücke',
            'description' => 'LeadHubs Lebenszyklus-Ereignisse in goldnead/statamic-webhook-manager einhängen. Ohne installiertes Addon wirkungslos.',
        ],
        'features_click_tracking' => [
            'label' => 'Klick-Tracking in E-Mails',
            'description' => 'Links in Marketing-Mails auf eine signierte Weiterleitung umschreiben, die den Klick festhält. Einwilligung zuerst: gewertet wird ein Klick nur bei Kontakten mit Marketing-Einwilligung. Deutsche Installationen müssen diese Einwilligung vor dem Einschalten einholen.',
        ],

        'exports_queue_threshold' => [
            'label' => 'Queue-Schwelle',
            'description' => 'Ab dieser Anzahl Kontakte läuft ein Export als Queue-Job statt synchron. Eine Schwelle, die kein Worker bedient, bedeutet: große Exporte werden eingereiht und nie fertig.',
        ],
        'exports_disk' => [
            'label' => 'Disk',
            'description' => 'Der Filesystem-Disk, auf den Exportdateien geschrieben werden. Zur Auswahl stehen die konfigurierten Disks der Anwendung.',
        ],
        'exports_directory' => [
            'label' => 'Verzeichnis',
            'description' => 'Der Pfad auf diesem Disk. Bereits geschriebene Dateien bleiben, wo sie sind.',
        ],

        'scoring_default' => [
            'label' => 'Standard-Punktwert',
            'description' => 'Punkte für eine Aktivität, die keine Regel aufführt. Negative Werte sind erlaubt, damit nicht aufgeführte Aktivität Punkte kosten statt einbringen kann.',
        ],
        'scoring_timeline' => [
            'label' => 'Punkteänderungen in der Timeline festhalten',
            'description' => 'Bei jeder echten Punkteänderung einen Timeline-Eintrag schreiben. Aus bedeutet: der Kontakt hat einen Punktestand ohne Verlauf. Das Ereignis zur Punkteänderung wird so oder so ausgelöst.',
        ],

        'click_tracking_dedupe_window' => [
            'label' => 'Dedupe-Fenster (Minuten)',
            'description' => 'Mehrfache Klicks desselben Kontakts auf denselben Link innerhalb dieses Fensters werden einmal festgehalten und einmal gewertet. Null wertet jeden Klick.',
        ],
        'click_tracking_ignored_query_parameters' => [
            'label' => 'Ignorierte Query-Parameter',
            'description' => 'Einer pro Zeile. Parameter, die Versanddienste beim Weiterleiten anhängen, von der Signaturprüfung ausgenommen, damit der Klick weiterhin gewertet wird. Hier gehören ausschließlich bedeutungslose Parameter hinein: das Weiterleitungsziel steht selbst in der Query, und die reservierten Parameter werden auch dann abgelehnt, wenn sie hier stehen.',
        ],

        'notifications_new_lead' => [
            'label' => 'Neuer Lead',
            'description' => 'Die hinterlegten Empfänger benachrichtigen, wenn ein neuer Lead ohne Zuständigkeit eintrifft.',
        ],
        'notifications_on_assignment' => [
            'label' => 'Lead-Zuweisung',
            'description' => 'Den Zuständigen benachrichtigen, wenn ihm ein Lead zugewiesen wird.',
        ],
        'notifications_on_task_assignment' => [
            'label' => 'Aufgaben-Zuweisung',
            'description' => 'Den neuen Bearbeiter benachrichtigen, wenn ihm eine Aufgabe übergeben wird. Läuft über goldnead/statamic-notifications und ist ohne dieses Addon wirkungslos. Wer sich selbst eine Aufgabe zuweist, wird nie benachrichtigt.',
        ],
        'notifications_digest_enabled' => [
            'label' => 'Täglicher Digest',
            'description' => 'Die tägliche E-Mail je Zuständigem mit den fälligen und überfälligen Follow-ups. Wird vom Scheduler verschickt, geht also nur dort raus, wo Laravels Scheduler läuft.',
        ],
    ],

    'environment' => [
        'heading' => 'Gehört der Umgebung',
        'description' => 'Diese Werte kommen aus Umgebungsvariablen. Sie werden angezeigt, damit man sie prüfen kann, und nicht angeboten, damit niemand sie halb ändert: ein hier gespeicherter Wert würde vom nächsten Deploy überholt. Der Storage-Treiber ist mehr als das. Er entscheidet, wo Kontakte, Ereignisse und Notizen liegen, und ein Wechsel muss sie zuerst umziehen: php artisan leadhub:storage:migrate.',
        'storage_driver' => 'Storage-Treiber',
        'flat_path' => 'Pfad der Flat-Dateien',
        'notifications' => 'Benachrichtigungen',
        'notify_recipients' => 'Empfänger für neue Leads',
        'digest_time' => 'Digest-Uhrzeit',
        'digest_recipients' => 'Digest-Ersatzempfänger',
        'on' => 'An',
        'off' => 'Aus',
        'none' => 'Keine',
    ],
];
