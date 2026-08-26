<?php

return [
    'title' => 'Custom fields',
    'nav' => 'Custom fields',
    'description' => 'Values you keep about your own contacts — voice part, choir size, region. A tag can only say yes or no; a field holds a value a segment can compare against.',
    'label' => 'Label',
    'handle' => 'Handle',
    'handle_hint' => 'The value is stored and referenced in segments under this handle. It cannot be changed later — every value already written hangs on it.',
    'type' => 'Type',
    'in_use' => 'Contacts with a value',
    'instructions' => 'Help text',
    'options' => 'Options',
    'add' => 'Add field',
    'types' => [
        'text' => 'Text',
        'number' => 'Number',
        'select' => 'Select',
        'date' => 'Date',
        'boolean' => 'Yes/No',
    ],
    'delete_confirm' => 'The definition is deleted; values already recorded stay. They become unreadable until somebody defines the same handle again. Deleted values could not be recovered at all — which is why they are left alone.',
    'empty' => 'No custom field defined yet.',
    'flashes' => [
        'created' => 'Field created.',
        'updated' => 'Field saved.',
        'deleted' => 'Field deleted. The recorded values are still there.',
    ],
];
