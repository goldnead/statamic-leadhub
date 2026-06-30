<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Button, Badge, Text, Field, Label, Select, Textarea, Input,
    DatePicker, Checkbox, ConfirmationModal, Pagination,
} from '@statamic/cms/ui';

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
    'canArchive',
    'canDelete',
]);

const noteBody = ref('');
const followupDueAt = ref('');
const followupNote = ref('');
const tagIds = ref(props.contact.tags.map(t => String(t.id)));
const status = ref(props.contact.status);
const showDeleteConfirm = ref(false);

function changeStatus() {
    router.patch(props.contact.update_url, { status: status.value }, { preserveScroll: true });
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

function setFollowup() {
    if (! followupDueAt.value) return;
    router.post(props.contact.followup_url, {
        due_at: followupDueAt.value,
        note: followupNote.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { followupDueAt.value = ''; followupNote.value = ''; },
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
</script>

<template>
    <Head :title="[contact.display_name, __('Contacts'), __('LeadHub')]" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="contact.display_name" icon="user">
            <Select v-model="status" :options="statusOptions()" @change="changeStatus" />
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
                            <div v-if="contact.company" class="flex gap-2">
                                <dt class="text-gray-500 w-20 shrink-0">{{ __('Company') }}</dt>
                                <dd>{{ contact.company }}</dd>
                            </div>
                            <div v-if="contact.source_form" class="flex gap-2">
                                <dt class="text-gray-500 w-20 shrink-0">{{ __('Source') }}</dt>
                                <dd>{{ contact.source_form }}</dd>
                            </div>
                        </dl>
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
                            <Pagination :meta="events.meta" />
                        </div>
                    </Card>
                </Panel>
            </div>

            <!-- Sidebar -->
            <aside class="space-y-4">
                <!-- Active follow-up -->
                <Panel :heading="__('Active follow-up')">
                    <Card>
                        <div v-if="activeFollowup" class="text-sm space-y-3">
                            <div class="flex items-center gap-2">
                                <Badge :color="activeFollowup.is_overdue ? 'red' : 'gray'" :text="activeFollowup.due_at" />
                                <Text v-if="activeFollowup.is_overdue" size="xs" variant="danger">{{ __('overdue') }}</Text>
                            </div>
                            <Text v-if="activeFollowup.note">{{ activeFollowup.note }}</Text>
                            <div class="flex gap-2">
                                <Button :text="__('Mark as done')" variant="default" size="sm" @click="completeFollowup" />
                                <Button :text="__('Remove')" variant="ghost" size="sm" @click="removeFollowup" />
                            </div>
                        </div>
                        <Text v-else variant="subtle">{{ __('No active follow-up.') }}</Text>

                        <div class="mt-4 pt-4 border-t border-content-border space-y-2">
                            <Field :label="__('Set follow-up')">
                                <DatePicker v-model="followupDueAt" with-time />
                            </Field>
                            <Textarea v-model="followupNote" :placeholder="__('Optional note')" rows="2" />
                            <Button :text="__('Schedule')" variant="primary" :disabled="!followupDueAt" @click="setFollowup" />
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
                                :text="__('Archive')"
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
            v-if="showDeleteConfirm"
            :title="__('Delete contact')"
            :message="__('This will permanently delete the contact and its entire timeline. This cannot be undone.')"
            variant="danger"
            :button-text="__('Delete')"
            @cancel="showDeleteConfirm = false"
            @confirm="destroy"
        />
    </div>
</template>
