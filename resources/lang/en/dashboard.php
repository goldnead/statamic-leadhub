<?php

return [
    'title' => 'LeadHub Dashboard',
    'subtitle' => 'Turn website form submissions into contacts and follow-ups.',
    'kpi' => [
        'new_leads_week' => 'New leads (7 days)',
        'qualified' => 'Qualified leads',
        'won' => 'Won leads',
        'due_followups' => 'Due follow-ups',
    ],
    'sections' => [
        'latest_leads' => 'Latest leads',
        'latest_activity' => 'Latest activity',
        'followups_today' => 'Follow-ups due today',
        'followups_overdue' => 'Overdue follow-ups',
        'leads_by_status' => 'Leads by status',
    ],
    'empty' => [
        'title' => 'No leads yet',
        'description' => 'Connect your first Statamic form to start turning submissions into contacts.',
        'cta' => 'Configure forms',
    ],
];
