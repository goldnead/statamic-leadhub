/**
 * LeadHub — Statamic 6 Control Panel entry point.
 *
 * Each page registered here corresponds to an `Inertia::render('leadhub::...')`
 * call on the PHP side. The string identifier MUST match exactly.
 */

import Dashboard from './pages/Dashboard.vue';
import ContactsIndex from './pages/Contacts/Index.vue';
import ContactsShow from './pages/Contacts/Show.vue';
import FollowupsIndex from './pages/Followups/Index.vue';
import FormsIndex from './pages/Forms/Index.vue';
import FormsEdit from './pages/Forms/Edit.vue';
import TagsIndex from './pages/Tags/Index.vue';
import Settings from './pages/Settings.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('leadhub::Dashboard', Dashboard);
    Statamic.$inertia.register('leadhub::Contacts/Index', ContactsIndex);
    Statamic.$inertia.register('leadhub::Contacts/Show', ContactsShow);
    Statamic.$inertia.register('leadhub::Followups/Index', FollowupsIndex);
    Statamic.$inertia.register('leadhub::Forms/Index', FormsIndex);
    Statamic.$inertia.register('leadhub::Forms/Edit', FormsEdit);
    Statamic.$inertia.register('leadhub::Tags/Index', TagsIndex);
    Statamic.$inertia.register('leadhub::Settings', Settings);
});
