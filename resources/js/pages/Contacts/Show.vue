<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Text, Field, Select, Textarea,
    DatePicker, Checkbox, ConfirmationModal, Modal, Dropdown, DropdownMenu, DropdownItem,
} from '@statamic/cms/ui';
import CompanyPicker from '../../support/CompanyPicker.vue';
import { toDateTimeString } from '../../support/datetime.js';
import { money } from '../../support/money.js';

const props = defineProps([
    'contact',          // { id, display_name, email, phone, company, status, status_label,
                        //   tags, consent, source_form, created_at, last_activity_at,
                        //   archived_at, update_url, archive_url, restore_url, delete_url,
                        //   note_url, followup_url, redirect_url }
    'events',           // { data: [{ id, type, summary, payload, actor_label, created_at }],
                        //   meta: { current_page, last_page, total } }
    'activeFollowup',   // { id, due_at, due_at_iso, note, is_overdue,
                        //   complete_url, delete_url } | null
    'statuses',         // { key: label }
    'allTags',          // [{ id, name, slug }]
    'assignableUsers',  // [{ value, label }]
    'canArchive',
    'canDelete',
    'crmFeatures',      // { companies, tasks, pipelines } — feature flags
    'linkedCompanies',  // [{ id, name, domain, industry, relationship_label, is_primary, url }]
    'tasks',            // [{ id, title, status, priority, due_at, is_overdue, is_completed, ... }]
    'opportunities',    // [{ id, title, status, outcome, value_estimate, stage_name, ... }]
    'crmCreateUrls',    // { task, opportunity, company } — null hides the button
    // Linking an existing company: { url, search_url, options } or null when
    // the reader may not manage companies.
    'companyLink',
    // What sibling addons know about this person, each as a panel they filled
    // in themselves: [{ key, heading, description, empty, rows: [{ label, url,
    // meta, badge }], action }]. Rendered generically on purpose — see
    // Support\ContactPanels for why this is data and not a component name.
    'contactPanels',
    // The merged timeline: LeadHub's own events plus what payments,
    // entitlements, booking and consent know about this person, newest first.
    // [{ id, source, kind, at, at_human, summary, url, badge, amount, detail,
    //    actor, payload }]. `events` above is the raw LeadHub feed and is no
    // longer rendered here.
    'timeline',
    'timelineTotal',    // how many entries existed before the server cap
    'timelineSources',  // [{ key, label, available }] — which readers took part
    'stats',            // { first_contact_human, last_contact_human, purchase_count,
                        //   lifetime_value: [{ currency, cent, formatted }], active_access }
    // The "grant access" action, or null when entitlements is not installed
    // or the user may not: { url, products: [{ value, label }] }
    'accessGrant',
]);

const noteBody = ref('');

// ── Timeline ────────────────────────────────────────────────────────────────

const PAGE = 25;
const visibleCount = ref(PAGE);
const visibleTimeline = computed(() => (props.timeline || []).slice(0, visibleCount.value));
const hiddenTimeline = computed(() => Math.max((props.timeline || []).length - visibleCount.value, 0));
const activeSources = computed(() => (props.timelineSources || []).filter((s) => s.available && !s.failed));
const failedSources = computed(() => (props.timelineSources || []).filter((s) => s.failed));

const sourceLabel = (key) => (props.timelineSources || []).find((s) => s.key === key)?.label ?? key;

/** Entry kind → Badge colour for the source chip, so a glance tells the feeds apart. */
const sourceColor = (source) => ({
    leadhub: 'default',
    payments: 'green',
    entitlements: 'blue',
    booking: 'purple',
    consent: 'amber',
}[source] ?? 'default');

// ── Grant access ────────────────────────────────────────────────────────────

const showGrant = ref(false);
const grantProduct = ref('');
const grantNote = ref('');
const grantErrors = ref({});
const granting = ref(false);

function openGrant() {
    grantErrors.value = {};
    grantProduct.value = props.accessGrant?.products?.[0]?.value ?? '';
    grantNote.value = '';
    showGrant.value = true;
}

function grantAccess() {
    if (! props.accessGrant?.url || ! grantProduct.value || granting.value) return;
    granting.value = true;
    router.post(props.accessGrant.url, { product: grantProduct.value, note: grantNote.value || null }, {
        preserveScroll: true,
        onSuccess: () => { showGrant.value = false; grantErrors.value = {}; },
        onError: (errors) => { grantErrors.value = errors || {}; },
        onFinish: () => { granting.value = false; },
    });
}
const followupDueAt = ref(null);
const followupNote = ref('');
const followupErrors = ref({});
const tagIds = ref(props.contact.tags.map(t => String(t.id)));
const status = ref(props.contact.status);
const assignedTo = ref(props.contact.assigned_to || '');
const showDeleteConfirm = ref(false);

const ownerOptions = computed(() => [
    { value: '', label: __('Unassigned') },
    ...(props.assignableUsers || []),
]);

function changeStatus() {
    router.patch(props.contact.update_url, { status: status.value }, { preserveScroll: true });
}

function changeOwner() {
    router.patch(props.contact.update_url, { assigned_to: assignedTo.value || null }, { preserveScroll: true });
}

function saveTags() {
    router.patch(props.contact.update_url, { tag_ids: tagIds.value }, { preserveScroll: true });
}

function addNote() {
    if (! noteBody.value.trim()) return;
    router.post(props.contact.note_url, { body: noteBody.value }, {
        preserveScroll: true,
        onSuccess: () => { noteBody.value = ''; },
    });
}

// The DatePicker's v-model is a DateValue OBJECT. Posting it raw is what made
// this form fail with a 422 the page never showed. Normalize first, and surface
// whatever the server still rejects.
const followupDueAtValue = computed(() => toDateTimeString(followupDueAt.value));

function setFollowup() {
    const dueAt = followupDueAtValue.value;

    if (! dueAt) {
        followupErrors.value = { due_at: __('Pick a date for the follow-up.') };
        return;
    }

    followupErrors.value = {};

    router.post(props.contact.followup_url, {
        due_at: dueAt,
        note: followupNote.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { followupDueAt.value = null; followupNote.value = ''; followupErrors.value = {}; },
        onError: (errors) => { followupErrors.value = errors || {}; },
    });
}

function completeFollowup() {
    if (! props.activeFollowup) return;
    router.post(props.activeFollowup.complete_url, {}, { preserveScroll: true });
}

function removeFollowup() {
    if (! props.activeFollowup) return;
    router.delete(props.activeFollowup.delete_url, { preserveScroll: true });
}

function archive() {
    router.post(props.contact.archive_url, {}, { preserveScroll: true });
}

function restore() {
    router.post(props.contact.restore_url, {}, { preserveScroll: true });
}

function destroy() {
    router.delete(props.contact.delete_url);
}

function statusOptions() {
    return Object.entries(props.statuses).map(([value, label]) => ({ value, label }));
}

function tagOptions() {
    return props.allTags.map(t => ({ value: String(t.id), label: t.name }));
}

function completeTask(task) {
    router.post(task.complete_url, {}, { preserveScroll: true });
}

// ── Panels contributed by sibling addons ────────────────────────────────────

/** The option picked in each panel's select-shaped action, keyed by panel. */
const panelChoice = ref({});

function runPanelAction(panel) {
    const chosen = panelChoice.value[panel.key];
    const option = (panel.action?.select?.options || []).find((o) => String(o.value) === String(chosen));
    if (! option?.url) return;

    router.post(option.url, option.payload || {}, {
        preserveScroll: true,
        onSuccess: () => { panelChoice.value = { ...panelChoice.value, [panel.key]: null }; },
    });
}

// ── Linked companies ────────────────────────────────────────────────────────

const companyToLink = ref('');
const linkingCompany = ref(false);

function linkCompany() {
    if (! props.companyLink?.url || ! companyToLink.value || linkingCompany.value) return;
    linkingCompany.value = true;
    router.post(props.companyLink.url, { company_id: Number(companyToLink.value) }, {
        preserveScroll: true,
        onSuccess: () => { companyToLink.value = ''; },
        onFinish: () => { linkingCompany.value = false; },
    });
}

function detachCompany(company) {
    if (! company.detach_url) return;
    router.delete(company.detach_url, { preserveScroll: true });
}

const features = computed(() => props.crmFeatures || {});
const companies = computed(() => props.linkedCompanies || []);
const contactTasks = computed(() => props.tasks || []);
const contactOpportunities = computed(() => props.opportunities || []);

// Any CRM module switched on gets its panel — including when it is empty, so
// "nothing linked yet" is distinguishable from "the panel does not exist".
const showCrm = computed(() =>
    !!(features.value.companies || features.value.tasks || features.value.pipelines)
);
</script>

<template>
    <Head :title="[contact.display_name, __('Contacts'), __('LeadHub')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <!--
            Header actions follow the CP's own shape: one primary button, the
            rest behind the "…" dropdown. The status lives in the sidebar with
            the other fields — it is a value on the record, not an action.
        -->
        <Header :title="contact.display_name" icon="users">
            <!--
                Core's order: the "…" menu first, the primary action last
                (pages/user-groups/Show.vue). Dropdown already renders the
                dots trigger itself — passing one is duplicating core.
            -->
            <Dropdown v-if="canArchive || canDelete">
                <DropdownMenu>
                    <DropdownItem
                        v-if="canArchive && !contact.archived_at"
                        :text="__('Archive contact')"
                        icon="package-box-crate"
                        @click="archive"
                    />
                    <DropdownItem
                        v-if="canArchive && contact.archived_at"
                        :text="__('Restore')"
                        icon="history"
                        @click="restore"
                    />
                    <DropdownItem
                        v-if="canDelete"
                        :text="__('Delete')"
                        icon="trash"
                        variant="destructive"
                        @click="showDeleteConfirm = true"
                    />
                </DropdownMenu>
            </Dropdown>
            <Button
                v-if="accessGrant"
                :text="__('Grant access')"
                variant="primary"
                data-leadhub-grant-access
                @click="openGrant"
            />
        </Header>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Main column -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Headline numbers: what a glance should answer before the
                     detail does. Read from the merged timeline, so "purchases"
                     is payments' count when payments is installed and
                     LeadHub's own ledger otherwise. -->
                <!-- auto-fit rather than a breakpoint variant: every addon's
                     stylesheet lands in the same layer, and a sibling's
                     `sm:grid-cols-2` loaded later wins over an `md:` variant
                     from this one. An arbitrary track list is emitted by
                     nobody else. -->
                <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] *:min-w-0" data-leadhub-contact-stats>
                    <Card>
                        <Text size="xs" variant="subtle" as="div">{{ __('First contact') }}</Text>
                        <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ stats?.first_contact_human || '—' }}</div>
                    </Card>
                    <Card>
                        <Text size="xs" variant="subtle" as="div">{{ __('Last contact') }}</Text>
                        <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ stats?.last_contact_human || '—' }}</div>
                    </Card>
                    <Card>
                        <Text size="xs" variant="subtle" as="div">{{ __('Purchases') }}</Text>
                        <div class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ stats?.purchase_count ?? 0 }}</div>
                    </Card>
                    <Card>
                        <Text size="xs" variant="subtle" as="div">{{ __('Lifetime value') }}</Text>
                        <div v-if="stats?.lifetime_value?.length" class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                            <div v-for="value in stats.lifetime_value" :key="value.currency">{{ value.formatted }}</div>
                        </div>
                        <div v-else class="mt-1 text-lg font-semibold text-gray-500 dark:text-gray-400">—</div>
                    </Card>
                    <Card>
                        <Text size="xs" variant="subtle" as="div">{{ __('Active access') }}</Text>
                        <div class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ stats?.active_access ?? '—' }}</div>
                    </Card>
                </div>

                <!-- Contact summary -->
                <Panel :heading="__('Contact')">
                    <Card>
                        <dl class="text-sm space-y-1.5">
                            <div v-if="contact.email" class="flex gap-2">
                                <dt class="text-gray-500 w-20 shrink-0">{{ __('Email') }}</dt>
                                <dd>{{ contact.email }}</dd>
                            </div>
                            <div v-if="contact.phone" class="flex gap-2">
                                <dt class="text-gray-500 w-20 shrink-0">{{ __('Phone') }}</dt>
                                <dd>{{ contact.phone }}</dd>
                            </div>
                            <!--
                                Free-text company. This is whatever the form
                                submitted, not a linked Company record — the
                                two look alike and have nothing to do with each
                                other, so the label says which one this is.
                            -->
                            <div v-if="contact.company" class="flex gap-2" data-leadhub-company-text>
                                <dt class="text-gray-500 w-20 shrink-0">{{ __('Company') }}</dt>
                                <dd class="min-w-0">
                                    {{ contact.company }}
                                    <Text size="xs" variant="subtle" class="block">
                                        {{ __('Text from the form. Not a linked company record.') }}
                                    </Text>
                                </dd>
                            </div>
                            <div v-if="contact.source_form" class="flex gap-2">
                                <dt class="text-gray-500 w-20 shrink-0">{{ __('Source') }}</dt>
                                <dd>{{ contact.source_form }}</dd>
                            </div>
                        </dl>
                    </Card>
                </Panel>

                <!-- Linked CRM records -->
                <template v-if="showCrm">
                    <Panel v-if="features.companies" :heading="__('Linked companies')" data-leadhub-linked-companies>
                        <Card>
                            <div v-if="companies.length === 0" class="py-4 text-center text-sm text-gray-500">
                                {{ __('No company linked to this contact.') }}
                            </div>
                            <ul v-else class="-my-3 divide-y divide-content-border">
                                <li v-for="company in companies" :key="company.id" class="py-3 flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <Link :href="company.url" class="text-sm font-medium hover:underline">{{ company.name }}</Link>
                                        <div class="text-xs text-gray-500 truncate">
                                            <span v-if="company.domain">{{ company.domain }}</span>
                                            <span v-if="company.domain && company.industry"> · </span>
                                            <span v-if="company.industry">{{ company.industry }}</span>
                                            <span v-if="company.relationship_label"> · {{ company.relationship_label }}</span>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <Badge v-if="company.is_primary" color="blue" pill :text="__('Primary')" />
                                        <Button
                                            v-if="companyLink"
                                            icon="x"
                                            size="xs"
                                            variant="ghost"
                                            :aria-label="__('leadhub::companies.unlink')"
                                            @click="detachCompany(company)"
                                        />
                                    </div>
                                </li>
                            </ul>

                            <!--
                                Link + create sit BELOW the list, the way the
                                relationship fieldtype does it
                                (components/inputs/relationship/RelationshipInput.vue):
                                `size="sm"`, no variant, `icon="link"`.
                            -->
                            <div v-if="companyLink" class="pt-3 mt-3 border-t border-content-border space-y-2" data-leadhub-company-link>
                                <CompanyPicker
                                    v-model="companyToLink"
                                    :options="companyLink.options"
                                    :search-url="companyLink.search_url"
                                />
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        :text="__('leadhub::companies.link')"
                                        icon="link"
                                        size="sm"
                                        :disabled="!companyToLink || linkingCompany"
                                        @click="linkCompany"
                                    />
                                    <Button
                                        v-if="crmCreateUrls && crmCreateUrls.company"
                                        :text="__('leadhub::companies.new')"
                                        icon="plus"
                                        size="sm"
                                        @click="router.visit(crmCreateUrls.company)"
                                    />
                                </div>
                            </div>
                        </Card>
                    </Panel>

                    <Panel v-if="features.tasks" :heading="__('Tasks')" data-leadhub-linked-tasks>
                        <Card>
                            <div v-if="contactTasks.length === 0" class="py-4 text-center text-sm text-gray-500">
                                {{ __('No tasks for this contact.') }}
                            </div>
                            <ul v-else class="-my-3 divide-y divide-content-border">
                                <li v-for="task in contactTasks" :key="task.id" class="py-3 flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium" :class="task.is_completed ? 'line-through text-gray-500' : ''">
                                            {{ task.title }}
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <span v-if="task.due_at">{{ task.due_at }}</span>
                                            <span v-if="task.due_at && task.assignee_name"> · </span>
                                            <span v-if="task.assignee_name">{{ task.assignee_name }}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <Badge v-if="task.is_overdue" color="red" pill :text="__('Overdue')" />
                                        <Badge v-else-if="task.is_completed" color="green" pill :text="__('Completed')" />
                                        <Badge v-else color="default" pill :text="task.priority_label || task.priority" />
                                        <Button
                                            v-if="!task.is_completed"
                                            :text="__('Mark as done')"
                                            size="xs"
                                            variant="ghost"
                                            @click="completeTask(task)"
                                        />
                                    </div>
                                </li>
                            </ul>
                            <div v-if="crmCreateUrls && crmCreateUrls.task" class="pt-3 mt-3 border-t border-content-border">
                                <Button
                                    :text="__('New task')"
                                    icon="plus"
                                    size="sm"
                                    data-leadhub-contact-new-task
                                    @click="router.visit(crmCreateUrls.task)"
                                />
                            </div>
                        </Card>
                    </Panel>

                    <Panel v-if="features.pipelines" :heading="__('Opportunities')" data-leadhub-linked-opportunities>
                        <Card>
                            <div v-if="contactOpportunities.length === 0" class="py-4 text-center text-sm text-gray-500">
                                {{ __('No opportunities for this contact.') }}
                            </div>
                            <ul v-else class="-my-3 divide-y divide-content-border">
                                <li v-for="opp in contactOpportunities" :key="opp.id" class="py-3 flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <component
                                            :is="opp.show_url ? Link : 'span'"
                                            :href="opp.show_url"
                                            class="text-sm font-medium"
                                            :class="opp.show_url ? 'hover:underline' : ''"
                                        >{{ opp.title }}</component>
                                        <div class="text-xs text-gray-500">
                                            <span v-if="opp.pipeline_name">{{ opp.pipeline_name }}</span>
                                            <span v-if="opp.pipeline_name && opp.stage_name"> · </span>
                                            <span v-if="opp.stage_name">{{ opp.stage_name }}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <Text v-if="opp.value_estimate" size="sm" class="font-medium">{{ money(opp.value_estimate) }}</Text>
                                        <Badge
                                            v-if="opp.outcome"
                                            :color="opp.outcome === 'won' ? 'green' : 'red'"
                                            pill
                                            :text="opp.outcome_label || opp.outcome"
                                        />
                                        <Badge v-else color="blue" pill :text="opp.status_label || opp.status" />
                                    </div>
                                </li>
                            </ul>
                            <div v-if="crmCreateUrls && crmCreateUrls.opportunity" class="pt-3 mt-3 border-t border-content-border">
                                <Button
                                    :text="__('New opportunity')"
                                    icon="plus"
                                    size="sm"
                                    data-leadhub-contact-new-opportunity
                                    @click="router.visit(crmCreateUrls.opportunity)"
                                />
                            </div>
                        </Card>
                    </Panel>
                </template>

                <!-- Panels contributed by sibling addons: mailing lists today,
                     whatever registers tomorrow. Above the note box because
                     they answer "what is going on with this person", which is
                     what somebody reads before they write anything down. -->
                <Panel
                    v-for="panel in (contactPanels || [])"
                    :key="panel.key"
                    :heading="panel.heading"
                    :data-leadhub-contact-panel="panel.key"
                >
                    <Card>
                        <p v-if="panel.description" class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                            {{ panel.description }}
                        </p>

                        <div
                            v-if="panel.rows.length === 0"
                            class="py-4 text-center text-sm text-gray-500"
                        >{{ panel.empty }}</div>

                        <ul v-else class="-my-3 divide-y divide-content-border">
                            <li
                                v-for="(row, i) in panel.rows"
                                :key="i"
                                class="py-3 flex items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <component
                                        :is="row.url ? Link : 'span'"
                                        :href="row.url"
                                        class="text-sm font-medium"
                                        :class="row.url ? 'hover:underline' : ''"
                                    >{{ row.label }}</component>
                                    <div v-if="row.meta" class="text-xs text-gray-500">{{ row.meta }}</div>
                                </div>
                                <Badge
                                    v-if="row.badge"
                                    :color="row.badge.color"
                                    pill
                                    :text="row.badge.text"
                                    class="shrink-0"
                                />
                            </li>
                        </ul>

                        <!--
                            Two shapes of action, both data. A plain one is a
                            link. One carrying `select` renders a picker plus a
                            button, and each option says where it posts and
                            what it sends — so a contributor can offer "put
                            this person on a list" without LeadHub knowing what
                            a list is.
                        -->
                        <div v-if="panel.action" class="pt-3 mt-3 border-t border-content-border space-y-2">
                            <template v-if="panel.action.select">
                                <Select
                                    v-model="panelChoice[panel.key]"
                                    :options="panel.action.select.options"
                                    :placeholder="panel.action.select.placeholder"
                                    class="w-full"
                                    adaptive-width
                                />
                                <Button
                                    :text="panel.action.text"
                                    :icon="panel.action.icon || 'plus'"
                                    size="sm"
                                    :disabled="!panelChoice[panel.key]"
                                    @click="runPanelAction(panel)"
                                />
                            </template>
                            <Button
                                v-else
                                :text="panel.action.text"
                                :icon="panel.action.icon || 'plus'"
                                size="sm"
                                @click="router.visit(panel.action.url)"
                            />
                        </div>
                    </Card>
                </Panel>

                <!-- Add note -->
                <Panel :heading="__('Add a note')">
                    <Card>
                        <div class="space-y-3">
                            <Textarea
                                v-model="noteBody"
                                :placeholder="__('Write your note...')"
                                rows="3"
                            />
                            <div class="text-right">
                                <Button :text="__('Add note')" variant="primary" :disabled="!noteBody.trim()" @click="addNote" />
                            </div>
                        </div>
                    </Card>
                </Panel>

                <!-- Timeline: one order for everything about this person.
                     Entries from the sibling addons carry a source chip and a
                     state badge the source itself chose; LeadHub's own events
                     keep the actor line and the folded payload they had. -->
                <Panel :heading="__('Timeline')" data-leadhub-timeline>
                    <Card>
                        <div
                            v-if="activeSources.length"
                            class="mb-3 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"
                            data-leadhub-timeline-sources
                        >
                            <span>{{ __('Timeline sources') }}:</span>
                            <Badge
                                v-for="source in activeSources"
                                :key="source.key"
                                size="sm"
                                :color="sourceColor(source.key)"
                                :text="source.label"
                            />
                        </div>

                        <!-- A reader that threw: its entries are not in the
                             list below, and a quiet line says so instead of a
                             green chip over a hole. -->
                        <div v-if="failedSources.length" class="mb-3 space-y-0.5" data-leadhub-timeline-failed>
                            <Text
                                v-for="source in failedSources"
                                :key="source.key"
                                as="div"
                                size="xs"
                                variant="warning"
                            >{{ __('Could not read source :source', { source: source.label }) }}</Text>
                        </div>

                        <div v-if="(timeline || []).length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('No timeline events yet.') }}
                        </div>
                        <ul v-else class="-my-3 divide-y divide-content-border">
                            <li v-for="entry in visibleTimeline" :key="entry.id" class="py-3" :data-leadhub-timeline-entry="entry.kind">
                                <div class="flex items-start justify-between gap-3 text-sm">
                                    <div class="min-w-0">
                                        <component
                                            :is="entry.url ? 'a' : 'span'"
                                            :href="entry.url"
                                            class="font-medium"
                                            :class="entry.url ? 'hover:underline' : ''"
                                        >{{ entry.summary }}</component>
                                        <span v-if="entry.amount" class="ms-2 tabular-nums text-gray-900 dark:text-gray-100">{{ entry.amount.formatted }}</span>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <Badge
                                            v-if="entry.badge"
                                            pill
                                            :color="entry.badge.color"
                                            :text="entry.badge.text"
                                        />
                                        <Text size="xs" variant="subtle" :title="entry.at">{{ entry.at_human }}</Text>
                                    </div>
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <!--
                                        The raw event key used to sit here as
                                        `<code>leadhub.score_changed</code>`,
                                        next to the word "System". Together
                                        they made a person's history read like
                                        an application log. The key is still
                                        reachable, one fold down, for whoever
                                        actually needs it.
                                    -->
                                    <Badge v-if="entry.source !== 'leadhub'" size="sm" :color="sourceColor(entry.source)" :text="sourceLabel(entry.source)" />
                                    <span v-if="entry.actor">{{ entry.actor }}</span>
                                </div>

                                <!-- Readable lines when the source supplied them.
                                     A contributor that knows what its own event
                                     means puts label/value pairs in
                                     `payload.detail`; everything else still has
                                     the raw payload below. The convention is
                                     LeadHub's and mentions no sibling addon —
                                     see Support\ContactPanels for the same idea
                                     applied to whole panels. -->
                                <dl
                                    v-if="entry.detail && entry.detail.length"
                                    class="mt-2 grid gap-x-3 gap-y-0.5 text-xs sm:grid-cols-[auto_1fr]"
                                    :data-leadhub-event-detail="entry.id"
                                >
                                    <template v-for="(line, i) in entry.detail" :key="i">
                                        <dt><Text size="xs" variant="subtle">{{ line.label }}</Text></dt>
                                        <dd><Text size="xs">{{ line.value }}</Text></dd>
                                    </template>
                                </dl>

                                <details v-if="entry.payload && Object.keys(entry.payload).length > 0" class="mt-2">
                                    <summary class="text-xs text-gray-500 cursor-pointer">{{ __('leadhub::timeline.technical_details') }}</summary>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <code>{{ entry.kind }}</code>
                                    </div>
                                    <pre class="text-xs mt-1 p-2 rounded bg-gray-50 dark:bg-gray-800 overflow-x-auto">{{ JSON.stringify(entry.payload, null, 2) }}</pre>
                                </details>
                            </li>
                        </ul>
                        <div v-if="hiddenTimeline > 0" class="mt-4 pt-4 border-t border-content-border text-center">
                            <Button
                                :text="__('Show more entries') + ' (' + hiddenTimeline + ')'"
                                size="sm"
                                variant="ghost"
                                @click="visibleCount += PAGE"
                            />
                        </div>
                    </Card>
                </Panel>
            </div>

            <!-- Sidebar -->
            <aside class="space-y-4">
                <!-- Status and owner: the two fields somebody changes most
                     often, so they sit at the top of the sidebar the way the
                     publish panel does on an entry. -->
                <Panel :heading="__('Status')">
                    <Card>
                        <!--
                            `adaptive-width`: without it the popover is only
                            `min-w-[--reka-combobox-trigger-width]` wide and the
                            option labels get truncated to "Qualif…" inside a
                            narrow sidebar.
                        -->
                        <Select
                            v-model="status"
                            :options="statusOptions()"
                            class="w-full"
                            adaptive-width
                            @update:model-value="changeStatus"
                        />
                    </Card>
                </Panel>

                <!-- Owner -->
                <Panel :heading="__('Owner')">
                    <Card>
                        <Select
                            v-model="assignedTo"
                            :options="ownerOptions"
                            :placeholder="__('Unassigned')"
                            class="w-full"
                            adaptive-width
                            @update:model-value="changeOwner"
                        />
                    </Card>
                </Panel>

                <!-- Active follow-up -->
                <Panel :heading="__('Active follow-up')">
                    <Card>
                        <div v-if="activeFollowup" class="text-sm space-y-3">
                            <div class="flex items-center gap-2">
                                <Badge :color="activeFollowup.is_overdue ? 'red' : 'default'" :text="activeFollowup.due_at" />
                                <Text v-if="activeFollowup.is_overdue" size="xs" variant="danger">{{ __('Overdue') }}</Text>
                            </div>
                            <Text v-if="activeFollowup.note">{{ activeFollowup.note }}</Text>
                            <div class="flex gap-2">
                                <Button :text="__('Mark as done')" variant="default" size="sm" @click="completeFollowup" />
                                <Button :text="__('Remove')" variant="ghost" size="sm" @click="removeFollowup" />
                            </div>
                        </div>
                        <Text v-else variant="subtle">{{ __('No active follow-up.') }}</Text>

                        <div class="mt-4 pt-4 border-t border-content-border space-y-2">
                            <Field :label="__('Set follow-up')" :error="followupErrors.due_at">
                                <DatePicker v-model="followupDueAt" granularity="minute" clearable />
                            </Field>
                            <Textarea v-model="followupNote" :placeholder="__('Optional note')" rows="2" />
                            <Text v-if="followupErrors.note" size="xs" variant="danger" data-leadhub-followup-error>
                                {{ followupErrors.note }}
                            </Text>
                            <Button :text="__('Schedule follow-up')" variant="primary" @click="setFollowup" />
                        </div>
                    </Card>
                </Panel>

                <!-- Tags -->
                <Panel :heading="__('Tags')">
                    <Card>
                        <div class="space-y-2.5 text-sm">
                            <Checkbox
                                v-for="tag in allTags"
                                :key="tag.id"
                                :model-value="tagIds.includes(String(tag.id))"
                                @update:model-value="(checked) => {
                                    if (checked) tagIds.push(String(tag.id));
                                    else tagIds = tagIds.filter(id => id !== String(tag.id));
                                }"
                                :label="tag.name"
                            />
                            <Button class="mt-1" :text="__('Save tags')" variant="primary" size="sm" @click="saveTags" />
                        </div>
                    </Card>
                </Panel>

                <!-- Contact metadata -->
                <Panel :heading="__('Details')">
                    <Card>
                        <dl class="text-sm space-y-1.5">
                            <!--
                                Engagement score. `!== null` and not a falsy
                                check: 0 is the score every contact starts at
                                and is exactly the value somebody looks here to
                                confirm. The controller sends null when the
                                scoring feature is off, which is the only case
                                that hides the row.
                            -->
                            <div v-if="contact.engagement_score !== null && contact.engagement_score !== undefined" class="flex justify-between items-center">
                                <dt class="text-gray-500">{{ __('leadhub::contacts.detail.engagement_score') }}</dt>
                                <dd><Badge color="blue" :text="String(contact.engagement_score)" /></dd>
                            </div>
                            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Consent') }}</dt><dd>{{ contact.consent ? '✓' : '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Created') }}</dt><dd>{{ contact.created_at }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Last activity') }}</dt><dd>{{ contact.last_activity_at }}</dd></div>
                            <div v-if="contact.archived_at" class="flex justify-between"><dt class="text-gray-500">{{ __('Archived') }}</dt><dd>{{ contact.archived_at }}</dd></div>
                        </dl>
                    </Card>
                </Panel>

                <!-- Attribution -->
                <Panel v-if="contact.attribution && contact.attribution.length" :heading="__('Attribution')">
                    <Card>
                        <dl class="text-sm space-y-1.5">
                            <div v-for="row in contact.attribution" :key="row.label" class="flex gap-2">
                                <dt class="text-gray-500 w-24 shrink-0">{{ row.label }}</dt>
                                <dd class="min-w-0 truncate" :title="row.value">{{ row.value }}</dd>
                            </div>
                        </dl>
                    </Card>
                </Panel>

            </aside>
        </div>

        <!-- Grant access: a product from the catalogue, an optional note, one
             write through the entitlements facade. -->
        <Modal v-if="accessGrant" v-model:open="showGrant" :title="__('Grant access')" icon="key">
            <div class="space-y-4">
                <Field :label="__('Product')" :error="grantErrors.product" required>
                    <Select
                        v-model="grantProduct"
                        :options="accessGrant.products"
                        :placeholder="__('Choose a product')"
                    />
                </Field>
                <Field :label="__('Note (optional)')" :error="grantErrors.note">
                    <Textarea v-model="grantNote" rows="3" />
                </Field>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button :text="__('Cancel')" variant="ghost" @click="showGrant = false" />
                    <Button
                        :text="__('Grant access')"
                        variant="primary"
                        :disabled="!grantProduct"
                        :loading="granting"
                        @click="grantAccess"
                    />
                </div>
            </template>
        </Modal>

        <ConfirmationModal
            :open="showDeleteConfirm"
            :title="__('Delete contact')"
            :body-text="__('This will permanently delete the contact and its entire timeline. This cannot be undone.')"
            danger
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
