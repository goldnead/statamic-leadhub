<?php

return [
    'title' => 'Tasks',
    'singular' => 'Task',
    'completed' => 'Task completed.',
    'filters' => [
        'open' => 'Open',
        'today' => 'Due today',
        'overdue' => 'Overdue',
        'done' => 'Done',
    ],

    // Create / edit / delete
    'new' => 'New task',
    'edit' => 'Edit task',
    'created' => 'Task created.',
    'updated' => 'Task updated.',
    'deleted' => 'Task deleted.',

    // Assignment
    'assignee' => 'Assignee',
    'unassigned' => 'Unassigned',
    'any_assignee' => 'Anyone',
    'mine' => 'My tasks',

    'priorities' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
    ],

    'validation' => [
        'contact_not_found' => 'That contact does not exist.',
        'assignee_not_assignable' => 'That user cannot be assigned LeadHub tasks.',
        'opportunity_not_found' => 'That opportunity does not exist.',
        'opportunity_contact_mismatch' => 'That opportunity belongs to a different contact. Pick the contact first.',
    ],
];
