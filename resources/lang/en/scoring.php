<?php

return [
    'title' => 'Scoring',
    'intro' => 'Every scored activity adds points to a contact\'s engagement score. Rules are stored per brand — what you change here applies to this brand only.',

    'event_type' => 'Activity type',
    'label' => 'Description',
    'points' => 'Points',
    'enabled' => 'Active',

    'catch_all' => 'Everything else',
    'catch_all_label' => 'Every activity without a rule of its own',
    'catch_all_hint' => 'The rule for activity types that have no rule of their own. This is what `scoring.default` used to be.',

    'new_rule' => 'New rule',
    'create' => 'Add rule',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'edit' => 'Edit',
    'delete' => 'Delete',

    'event_type_placeholder' => 'e.g. purchase.completed',
    'label_placeholder' => 'What this activity is',
    'known_types' => 'Known activity types',

    'fallback_notice_title' => 'This brand has no rules yet',
    'fallback_notice_body' => 'Until the first rule exists, scoring keeps using the point table from config/leadhub.php exactly as before. Nothing has changed for this brand. Run :command to copy those values in, or add rules by hand below.',
    'config_table' => 'Currently in config/leadhub.php',
    'config_default' => 'Everything else: :points',

    'delete_title' => 'Delete rule',
    'delete_body' => 'Delete this rule? Nothing references it, so nothing breaks — but this activity type will score the catch-all rule from now on, and already awarded points stay where they are.',
    'delete_body_last' => 'This is the last rule for this brand. Deleting it hands scoring back to the point table in config/leadhub.php.',

    'disabled_hint' => 'Inactive rules are ignored entirely — the activity falls through to the catch-all rule, as if the rule were not there.',
    'no_recompute' => 'Changing a rule affects future activity only. Scores already awarded are not recalculated.',

    'errors' => [
        'duplicate' => 'A rule for this activity type already exists in this brand.',
        'event_type_format' => 'Use the activity type handle, e.g. purchase.completed. Letters, digits, dot, colon, underscore and hyphen only.',
    ],

    'flashes' => [
        'created' => 'Rule created.',
        'updated' => 'Rule updated.',
        'deleted' => 'Rule deleted.',
    ],
];
