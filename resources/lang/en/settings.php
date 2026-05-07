<?php

return [
    'title' => 'Settings',
    'description' => 'Configuration values are read from config/leadhub.php. Publish the config to override them.',
    'publish_command' => 'php artisan vendor:publish --tag=leadhub-config',
    'sections' => [
        'statuses' => 'Lead statuses',
        'behavior' => 'Behavior',
        'redaction' => 'Payload redaction',
        'features' => 'Feature flags',
    ],
    'overwrite_label' => 'Overwrite existing fields from new submissions',
    'store_payload_label' => 'Store full submission payload',
    'queue_threshold_label' => 'Export queue threshold',
];
