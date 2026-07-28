<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Button, Listing, Badge, Icon, EmptyStateMenu, EmptyStateItem,
    DropdownItem, Panel, Field, Input,
} from '@statamic/cms/ui';

const props = defineProps([
    'contacts',           // [{ id, display_name, email, phone, company, status,
                          //   status_label, tags, source_form, last_activity_at,
                          //   active_followup, edit_url, archive_url, restore_url,
                          //   delete_url, can_edit, can_archive, can_delete, archived_at }]
    'columns',            // Array<Column>
    'filters',            // current filter values
    'statuses',           // { key: label }
    'tagOptions',         // [{ value, label }]
    'sourceOptions',      // [{ value, label }]
    'exportUrl',          // string
    'showArchived',       // bool
    'hasFormConnected',   // bool
    'configureFormsUrl',  // string
    'createUrl',          // string | null — manual "create contact" (feature-gated)
    'scoringEnabled',     // bool — features.scoring; hides the score column and filter
    'scoreSort',          // 'asc' | 'desc' | null — current server-side score sort
]);

// Engagement score filter. Server-side, because sorting and filtering client
// side would only ever reach the 25 rows of the current page — and "who are my
// hottest leads" is exactly the question that must not stop at page one.
const scoreMin = ref(props.filters?.score_min ?? '');
const scoreMax = ref(props.filters?.score_max ?? '');

function currentQuery(overrides) {
    const params = {};
    for (const [k, v] of Object.entries(props.filters || {})) {
        if (v !== null && v !== undefined && v !== '') params[k] = v;
    }
    Object.assign(params, overrides);
    for (const key of Object.keys(params)) {
        if (params[key] === null || params[key] === undefined || params[key] === '') delete params[key];
    }
    return params;
}

function applyScoreFilter() {
    router.get(window.location.pathname, currentQuery({
        score_min: scoreMin.value,
        score_max: scoreMax.value,
        page: null,
    }), { preserveScroll: true });
}

function resetScoreFilter() {
    scoreMin.value = '';
    scoreMax.value = '';
    router.get(window.location.pathname, currentQuery({
        score_min: null, score_max: null, sort: null, direction: null, page: null,
    }), { preserveScroll: true });
}

function sortByScore() {
    const next = props.scoreSort === 'desc' ? 'asc' : 'desc';
    router.get(window.location.pathname, currentQuery({
        sort: 'engagement_score', direction: next, page: null,
    }), { preserveScroll: true });
}

function scoreColor(score) {
    if (score >= 20) return 'green';
    if (score >= 5) return 'amber';
    return 'default';
}

function anyFilterActive() {
    const f = props.filters || {};
    return !!(f.status || f.source_form || f.tag_id || f.has_followup || f.search || f.from || f.to || f.archived
        || f.score_min !== undefined || f.score_max !== undefined);
}

const isEmpty = computed(() => props.contacts.length === 0 && ! anyFilterActive());

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function statusColor(key) {
    return {
        new: 'default',
        contacted: 'blue',
        qualified: 'amber',
        won: 'green',
        lost: 'red',
        archived: 'default',
    }[key] || 'default';
}

function exportCsv() {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(props.filters || {})) {
        if (v !== null && v !== undefined && v !== '') params.append(k, v);
    }
    const url = params.toString() ? `${props.exportUrl}?${params}` : props.exportUrl;
    router.post(url, {}, { preserveScroll: true });
}

function archive(row) {
    router.post(row.archive_url, {}, { preserveScroll: true });
}

function restore(row) {
    router.post(row.restore_url, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="[__('Contacts'), __('LeadHub')]" />

    <!-- Empty state — no form connected yet -->
    <div v-if="isEmpty && !hasFormConnected" class="max-w-page mx-auto">
        <header class="py-8 pt-16 text-center">
            <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                <Icon name="users" class="size-5 text-gray-500" />
                {{ __('Contacts') }}
            </h1>
        </header>
        <EmptyStateMenu :heading="__('Connect a form first to start collecting contacts.')">
            <EmptyStateItem
                :href="configureFormsUrl"
                icon="forms"
                :heading="__('Configure forms')"
                :description="__('Pick a Statamic form and map its fields to a LeadHub contact.')"
            />
            <EmptyStateItem
                v-if="createUrl"
                :href="createUrl"
                icon="plus"
                :heading="__('Create contact')"
                :description="__('Add a contact by hand instead of waiting for a form submission.')"
            />
        </EmptyStateMenu>
    </div>

    <!-- Filled state -->
    <div v-else class="max-w-page mx-auto">
        <Header :title="__('Contacts')" icon="users">
            <Button :text="__('Export CSV')" icon="download" variant="default" @click="exportCsv" />
            <Button
                v-if="createUrl"
                :text="__('Create contact')"
                icon="plus"
                variant="primary"
                @click="router.visit(createUrl)"
            />
        </Header>

        <Panel v-if="scoringEnabled" class="mb-4">
            <div class="p-4 flex flex-wrap gap-2 items-end">
                <Field :label="__('leadhub::contacts.index.filter_score')">
                    <div class="flex gap-2 items-center">
                        <Input v-model="scoreMin" type="number" class="w-24" :placeholder="__('leadhub::contacts.index.filter_score_min')" />
                        <span class="text-gray-400">–</span>
                        <Input v-model="scoreMax" type="number" class="w-24" :placeholder="__('leadhub::contacts.index.filter_score_max')" />
                    </div>
                </Field>
                <Button :text="__('leadhub::contacts.index.filter_score_apply')" variant="default" @click="applyScoreFilter" />
                <Button
                    :text="__('leadhub::contacts.index.sort_by_score')"
                    :icon="scoreSort === 'asc' ? 'arrow-up' : 'arrow-down'"
                    :variant="scoreSort ? 'primary' : 'default'"
                    @click="sortByScore"
                />
                <Button
                    v-if="scoreSort || filters.score_min !== undefined || filters.score_max !== undefined"
                    :text="__('leadhub::contacts.index.filter_score_reset')"
                    variant="ghost"
                    @click="resetScoreFilter"
                />
            </div>
        </Panel>

        <Listing
            :items="contacts"
            :columns="columns"
            preferences-prefix="leadhub.contacts"
            @refreshing="reloadPage"
        >
            <template #cell-display_name="{ row }">
                <Link :href="row.edit_url" class="font-medium hover:underline">
                    {{ row.display_name }}
                </Link>
            </template>

            <template #cell-email="{ row }">
                <span class="text-gray-700 dark:text-gray-300">{{ row.email }}</span>
            </template>

            <template #cell-status="{ row }">
                <Badge :color="statusColor(row.status)" :text="row.status_label" />
            </template>

            <template #cell-engagement_score="{ row }">
                <Badge :color="scoreColor(row.engagement_score)" :text="String(row.engagement_score)" />
            </template>

            <template #cell-tags="{ row }">
                <div class="flex flex-wrap gap-1">
                    <Badge v-for="tag in row.tags" :key="tag.id" color="default" :text="tag.name" />
                </div>
            </template>

            <template #cell-source_form="{ row }">
                <span class="text-xs text-gray-500">{{ row.source_form }}</span>
            </template>

            <template #cell-owner_name="{ row }">
                <span v-if="row.owner_name">{{ row.owner_name }}</span>
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #cell-last_activity_at="{ row }">
                <span class="text-xs text-gray-500">{{ row.last_activity_at }}</span>
            </template>

            <template #cell-active_followup="{ row }">
                <Badge
                    v-if="row.active_followup"
                    :color="row.active_followup.is_overdue ? 'red' : 'default'"
                    :text="row.active_followup.due_at"
                />
                <span v-else class="text-2xs text-gray-400">—</span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="row.can_edit"
                    :text="__('Open contact')"
                    :href="row.edit_url"
                    icon="external-link"
                />
                <DropdownItem
                    v-if="row.can_archive && !row.archived_at"
                    :text="__('Archive contact')"
                    icon="archive"
                    @click="archive(row)"
                />
                <DropdownItem
                    v-if="row.can_archive && row.archived_at"
                    :text="__('Restore')"
                    icon="rotate-counterclockwise"
                    @click="restore(row)"
                />
            </template>
        </Listing>
    </div>
</template>
