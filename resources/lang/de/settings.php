<?php

return [
    'title' => 'Einstellungen',
    'description' => 'Konfigurationswerte stammen aus config/leadhub.php. Veröffentliche die Config, um sie anzupassen.',
    'publish_command' => 'php artisan vendor:publish --tag=leadhub-config',
    'sections' => [
        'statuses' => 'Lead-Status',
        'behavior' => 'Verhalten',
        'redaction' => 'Payload-Redaktion',
        'features' => 'Feature-Flags',
    ],
    'overwrite_label' => 'Bestehende Felder aus neuen Submissions überschreiben',
    'store_payload_label' => 'Volle Submission-Payload speichern',
    'queue_threshold_label' => 'Export-Queue-Schwelle',
];
