/**
 * LeadHub — Statamic 6 Control Panel entry point.
 *
 * Each page registered here corresponds to an `Inertia::render('leadhub::...')`
 * call on the PHP side. The string identifier MUST match exactly.
 */

import Dashboard from './pages/Dashboard.vue';
import ContactsIndex from './pages/Contacts/Index.vue';
import ContactsShow from './pages/Contacts/Show.vue';
import CompaniesIndex from './pages/Companies/Index.vue';
import CompaniesShow from './pages/Companies/Show.vue';
import TasksIndex from './pages/Tasks/Index.vue';
import PipelinesBoard from './pages/Pipelines/Board.vue';
import FollowupsIndex from './pages/Followups/Index.vue';
import FormsIndex from './pages/Forms/Index.vue';
import FormsEdit from './pages/Forms/Edit.vue';
import TagsIndex from './pages/Tags/Index.vue';
import Settings from './pages/Settings.vue';
import SyncLog from './pages/SyncLog.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('leadhub::Dashboard', Dashboard);
    Statamic.$inertia.register('leadhub::Contacts/Index', ContactsIndex);
    Statamic.$inertia.register('leadhub::Contacts/Show', ContactsShow);
    Statamic.$inertia.register('leadhub::Companies/Index', CompaniesIndex);
    Statamic.$inertia.register('leadhub::Companies/Show', CompaniesShow);
    Statamic.$inertia.register('leadhub::Tasks/Index', TasksIndex);
    Statamic.$inertia.register('leadhub::Pipelines/Board', PipelinesBoard);
    Statamic.$inertia.register('leadhub::Followups/Index', FollowupsIndex);
    Statamic.$inertia.register('leadhub::Forms/Index', FormsIndex);
    Statamic.$inertia.register('leadhub::Forms/Edit', FormsEdit);
    Statamic.$inertia.register('leadhub::Tags/Index', TagsIndex);
    Statamic.$inertia.register('leadhub::Settings', Settings);
    Statamic.$inertia.register('leadhub::SyncLog', SyncLog);
});
