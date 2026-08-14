<?php

return [
    'title' => 'Pipelines',
    'singular' => 'Pipeline',
    'moved' => 'Opportunity moved.',
    'already_in_stage' => 'The deal is already in that stage.',
    'created' => 'Pipeline created.',
    'empty' => 'No open opportunities in this stage.',

    // Closed opportunities on the board
    'closed_filter' => 'Closed deals',
    'closed_none' => 'Open only',
    'closed_days' => 'Closed in the last :days days',
    'closed_all' => 'All closed deals',

    // Stage management
    'stage_created' => 'Stage added.',
    'stage_updated' => 'Stage updated.',
    'stage_deleted' => 'Stage deleted.',
    'stages_reordered' => 'Stage order saved.',
    'stage_not_empty' => 'This stage still holds opportunities. Move them to another stage first.',
    'stage_last' => 'A pipeline needs at least one stage.',

    // Opportunity create / edit / delete
    'opportunity' => 'Opportunity',
    'opportunity_new' => 'New opportunity',
    'opportunity_edit' => 'Edit opportunity',
    'opportunity_created' => 'Opportunity created.',
    'opportunity_updated' => 'Opportunity updated.',
    'opportunity_deleted' => 'Opportunity deleted.',
    'opportunity_has_tasks' => 'This opportunity still has :count task(s). Complete or delete them first.',

    // The task panel on the opportunity form (v1.10.0). The refusal above used
    // to name a count nothing on screen let you check.
    'opportunity_tasks_empty' => 'No tasks on this opportunity yet.',
    'opportunity_tasks_hint' => 'Every task counts, completed ones included — deleting the opportunity is refused while any of them is still attached.',

    // The opportunity detail screen (v2.4.0). Before it there was no place a
    // single deal stood: every link in the CP went to the board.
    'back_to_board' => 'Back to the board',
    'last_activity' => 'Last activity',
    'closed_at' => 'Closed',
    'won_at' => 'Won',
    'lost_at' => 'Lost',
    'current_stage' => 'Current stage',
    'change_stage' => 'Change stage',
    'change_stage_note' => 'Note',
    'change_stage_hint' => 'The note is the only thing that later explains why the deal moved on. It shows up in the history, and nowhere else.',
    'change_stage_submit' => 'Move',

    // The history, read out of leadhub_stage_transitions.
    'history' => 'History',
    'history_created' => 'Created',
    'history_hint' => 'The duration is the time until the next change; on the top entry it runs until now.',
    'history_hint_closed' => 'The duration is the time until the next change. This deal is closed, so the last one ends there.',
    'stage_removed' => 'Deleted stage',
    'actor_system' => 'System',
    'actor_unknown' => 'Unknown',
    'duration_running' => 'ongoing',
    'duration_under_minute' => 'under a minute',
    'duration_minutes' => ':count min',
    'duration_hours' => ':count h',
    'duration_days' => ':count days',

    'validation' => [
        'contact_not_found' => 'That contact does not exist.',
        'company_not_found' => 'That company does not exist.',
        'pipeline_not_found' => 'That pipeline does not exist.',
        'stage_not_in_pipeline' => 'That stage does not belong to the selected pipeline.',
    ],
];
