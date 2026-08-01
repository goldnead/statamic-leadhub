<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Text, Field, Label, Select, Textarea, Input,
    DatePicker, Checkbox, ConfirmationModal, Pagination,
} from '@statamic/cms/ui';
import { toDateTimeString } from '../../support/datetime.js';

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
]);

const noteBody = ref('');
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

function money(value) {
    if (value === null || value === undefined) return null;
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(value);
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
        <Header :title="contact.display_name" icon="user">
            <Select v-model="status" :options="statusOptions()" @update:model-value="changeStatus" />
        </Header>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Main column -->
            <div class="lg:col-span-2 space-y-4">
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
                                    <Badge v-if="company.is_primary" color="blue" size="sm" :text="__('Primary')" />
                                </li>
                            </ul>
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
                                        <Badge v-if="task.is_overdue" color="red" size="sm" :text="__('Overdue')" />
                                        <Badge v-else-if="task.is_completed" color="green" size="sm" :text="__('Completed')" />
                                        <Badge v-else color="default" size="sm" :text="task.priority_label || task.priority" />
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
                                    icon="add"
                                    size="xs"
                                    variant="ghost"
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
                                            :is="opp.board_url ? Link : 'span'"
                                            :href="opp.board_url"
                                            class="text-sm font-medium"
                                            :class="opp.board_url ? 'hover:underline' : ''"
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
                                            size="sm"
                                            :text="opp.outcome"
                                        />
                                        <Badge v-else color="default" size="sm" :text="opp.status" />
                                    </div>
                                </li>
                            </ul>
                            <div v-if="crmCreateUrls && crmCreateUrls.opportunity" class="pt-3 mt-3 border-t border-content-border">
                                <Button
                                    :text="__('New opportunity')"
                                    icon="add"
                                    size="xs"
                                    variant="ghost"
                                    data-leadhub-contact-new-opportunity
                                    @click="router.visit(crmCreateUrls.opportunity)"
                                />
                            </div>
                        </Card>
                    </Panel>
                </template>

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

                <!-- Timeline -->
                <Panel :heading="__('Timeline')">
                    <Card>
                        <div v-if="events.data.length === 0" class="py-6 text-center text-sm text-gray-500">
                            {{ __('No timeline events yet.') }}
                        </div>
                        <ul v-else class="-my-3 divide-y divide-content-border">
                            <li v-for="event in events.data" :key="event.id" class="py-3">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-medium">{{ event.summary }}</span>
                                    <Text size="xs" variant="subtle">{{ event.created_at }}</Text>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ event.actor_label }} · <code>{{ event.type }}</code>
                                </div>
                                <details v-if="event.payload && Object.keys(event.payload).length > 0" class="mt-2">
                                    <summary class="text-xs text-gray-500 cursor-pointer">{{ __('Payload') }}</summary>
                                    <pre class="text-xs mt-1 p-2 rounded bg-gray-50 dark:bg-gray-800 overflow-x-auto">{{ JSON.stringify(event.payload, null, 2) }}</pre>
                                </details>
                            </li>
                        </ul>
                        <div v-if="events.meta.last_page > 1" class="mt-4 pt-4 border-t border-content-border">
                            <Pagination
                                :resource-meta="events.meta"
                                :show-totals="false"
                                :show-per-page-selector="false"
                                @page-selected="page => router.get(window.location.pathname, { page }, { preserveScroll: true, preserveState: true })"
                            />
                        </div>
                    </Card>
                </Panel>
            </div>

            <!-- Sidebar -->
            <aside class="space-y-4">
                <!-- Owner -->
                <Panel :heading="__('Owner')">
                    <Card>
                        <Select
                            v-model="assignedTo"
                            :options="ownerOptions"
                            :placeholder="__('Unassigned')"
                            class="w-full"
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

                <!-- Danger zone -->
                <Panel :heading="__('Actions')">
                    <Card>
                        <div class="space-y-2">
                            <Button
                                v-if="canArchive && !contact.archived_at"
                                :text="__('Archive contact')"
                                variant="default"
                                class="w-full"
                                @click="archive"
                            />
                            <Button
                                v-if="canArchive && contact.archived_at"
                                :text="__('Restore')"
                                variant="default"
                                class="w-full"
                                @click="restore"
                            />
                            <Button
                                v-if="canDelete"
                                :text="__('Delete')"
                                variant="danger"
                                class="w-full"
                                @click="showDeleteConfirm = true"
                            />
                        </div>
                    </Card>
                </Panel>
            </aside>
        </div>

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
