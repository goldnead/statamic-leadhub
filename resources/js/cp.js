/**
 * LeadHub — Statamic 6 Control Panel entry point.
 *
 * Each page registered here corresponds to an `Inertia::render('leadhub::...')`
 * call on the PHP side. The string identifier MUST match exactly.
 */

import Dashboard from './pages/Dashboard.vue';
import ContactsIndex from './pages/Contacts/Index.vue';
import ContactsCreate from './pages/Contacts/Create.vue';
import ContactsShow from './pages/Contacts/Show.vue';
import CompaniesIndex from './pages/Companies/Index.vue';
import CompaniesShow from './pages/Companies/Show.vue';
import CompaniesCreate from './pages/Companies/Create.vue';
import CompaniesEdit from './pages/Companies/Edit.vue';
import TasksIndex from './pages/Tasks/Index.vue';
import TasksCreate from './pages/Tasks/Create.vue';
import TasksEdit from './pages/Tasks/Edit.vue';
import PipelinesBoard from './pages/Pipelines/Board.vue';
import PipelinesManage from './pages/Pipelines/Manage.vue';
import OpportunityCreate from './pages/Pipelines/OpportunityCreate.vue';
import OpportunityEdit from './pages/Pipelines/OpportunityEdit.vue';
import OpportunityShow from './pages/Pipelines/OpportunityShow.vue';
import FollowupsIndex from './pages/Followups/Index.vue';
import FormsIndex from './pages/Forms/Index.vue';
import FormsEdit from './pages/Forms/Edit.vue';
import TagsIndex from './pages/Tags/Index.vue';
import SegmentsIndex from './pages/Segments/Index.vue';
import SegmentsEdit from './pages/Segments/Edit.vue';
import ScoringIndex from './pages/Scoring/Index.vue';
import Settings from './pages/Settings.vue';
import SyncLog from './pages/SyncLog.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('leadhub::Dashboard', Dashboard);
    Statamic.$inertia.register('leadhub::Contacts/Index', ContactsIndex);
    Statamic.$inertia.register('leadhub::Contacts/Create', ContactsCreate);
    Statamic.$inertia.register('leadhub::Contacts/Show', ContactsShow);
    Statamic.$inertia.register('leadhub::Companies/Index', CompaniesIndex);
    Statamic.$inertia.register('leadhub::Companies/Show', CompaniesShow);
    Statamic.$inertia.register('leadhub::Companies/Create', CompaniesCreate);
    Statamic.$inertia.register('leadhub::Companies/Edit', CompaniesEdit);
    Statamic.$inertia.register('leadhub::Tasks/Index', TasksIndex);
    Statamic.$inertia.register('leadhub::Tasks/Create', TasksCreate);
    Statamic.$inertia.register('leadhub::Tasks/Edit', TasksEdit);
    Statamic.$inertia.register('leadhub::Pipelines/Board', PipelinesBoard);
    Statamic.$inertia.register('leadhub::Pipelines/Manage', PipelinesManage);
    Statamic.$inertia.register('leadhub::Pipelines/OpportunityCreate', OpportunityCreate);
    Statamic.$inertia.register('leadhub::Pipelines/OpportunityEdit', OpportunityEdit);
    Statamic.$inertia.register('leadhub::Pipelines/OpportunityShow', OpportunityShow);
    Statamic.$inertia.register('leadhub::Followups/Index', FollowupsIndex);
    Statamic.$inertia.register('leadhub::Forms/Index', FormsIndex);
    Statamic.$inertia.register('leadhub::Forms/Edit', FormsEdit);
    Statamic.$inertia.register('leadhub::Tags/Index', TagsIndex);
    Statamic.$inertia.register('leadhub::Segments/Index', SegmentsIndex);
    Statamic.$inertia.register('leadhub::Segments/Edit', SegmentsEdit);
    Statamic.$inertia.register('leadhub::Scoring/Index', ScoringIndex);
    Statamic.$inertia.register('leadhub::Settings', Settings);
    Statamic.$inertia.register('leadhub::SyncLog', SyncLog);
});
