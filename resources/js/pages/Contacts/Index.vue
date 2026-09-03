<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Button, Listing, Badge, EmptyStateMenu, EmptyStateItem,
    DropdownItem, Panel, PanelHeader, Card, Heading, Stack, Field, Input, CommandPaletteItem,
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
const showScoreFilter = ref(false);

const scoreFilterActive = computed(() =>
    !!(props.scoreSort || props.filters?.score_min !== undefined || props.filters?.score_max !== undefined)
);

/** What the chip beside the filter button says, or null when nothing is set. */
const scoreFilterSummary = computed(() => {
    const from = props.filters?.score_min;
    const to = props.filters?.score_max;
    if (from === undefined && to === undefined) return null;
    if (from !== undefined && to !== undefined) return `${from} – ${to}`;
    return from !== undefined ? `≥ ${from}` : `≤ ${to}`;
});

// ── Active filters ──────────────────────────────────────────────────────────
//
// The dashboard links here with a filter in the query string ("new leads this
// week" is `?from=…`). Nothing on the screen said so: the table simply showed
// three of nineteen contacts and looked broken. The CP's own answer is a chip
// per active filter beside the filter control, each with an "x" that clears
// it (ui/Listing/Filters.vue).

const labelFor = (options, value) =>
    (options || []).find((o) => String(o.value) === String(value))?.label ?? value;

const activeFilters = computed(() => {
    const f = props.filters || {};
    const chips = [];

    if (f.search) chips.push({ key: 'search', label: __('Search'), value: f.search });
    if (f.status) chips.push({ key: 'status', label: __('Status'), value: props.statuses?.[f.status] ?? f.status });
    if (f.tag_id) chips.push({ key: 'tag_id', label: __('Tag'), value: labelFor(props.tagOptions, f.tag_id) });
    if (f.source_form) chips.push({ key: 'source_form', label: __('Source'), value: labelFor(props.sourceOptions, f.source_form) });
    if (f.has_followup) chips.push({ key: 'has_followup', label: __('Follow-up'), value: __('Yes') });
    if (f.archived) chips.push({ key: 'archived', label: __('Archived'), value: __('Yes') });
    if (f.from) chips.push({ key: 'from', label: __('From'), value: f.from });
    if (f.to) chips.push({ key: 'to', label: __('To'), value: f.to });

    return chips;
});

function clearFilter(key) {
    router.get(window.location.pathname, currentQuery({ [key]: null, page: null }), { preserveScroll: true });
}

function clearAllFilters() {
    router.get(window.location.pathname, {}, { preserveScroll: true });
}

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
        <Header :title="__('Contacts')" icon="users" />
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
            <CommandPaletteItem
                v-if="createUrl"
                category="Actions"
                :text="__('Create contact')"
                icon="users"
                :url="createUrl"
                v-slot="{ text, url }"
            >
                <Button
                    :text="text"
                    icon="plus"
                    variant="primary"
                    @click="router.visit(url)"
                />
            </CommandPaletteItem>
        </Header>

        <!--
            The score filter used to sit in a bare Panel above the listing —
            form controls straight onto the grey, which the CP never does.
            It now uses the CP's own filter vocabulary: a
            `sliders-horizontal` button that opens a `Stack` holding
            Panel > Card, with a "Done" primary button at the foot
            (ui/Listing/Filters.vue). It is a separate button rather than an
            entry in the listing's own Filters popover because this filter has
            to run on the server: filtering the 25 rows of the current page
            would answer "who are my hottest leads on page one".
        -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <Button
                v-if="scoringEnabled"
                icon="sliders-horizontal"
                class="[&_svg]:size-3.5"
                :text="__('leadhub::contacts.index.filter_score')"
                data-leadhub-score-filter
                @click="showScoreFilter = true"
            />
            <Badge v-if="scoreFilterSummary" pill color="blue" :text="scoreFilterSummary" />

            <!-- One chip per active filter, each clearing itself. Same shape
                 the CP's own listing filters use. -->
            <Button
                v-for="chip in activeFilters"
                :key="chip.key"
                as="div"
                variant="filled"
                size="sm"
                :data-leadhub-active-filter="chip.key"
            >
                <span class="text-gray-500">{{ chip.label }}:</span>
                <span class="font-medium">{{ chip.value }}</span>
                <Button
                    variant="ghost"
                    size="xs"
                    icon="x"
                    icon-only
                    inset
                    :aria-label="__('Remove')"
                    @click="clearFilter(chip.key)"
                />
            </Button>

            <Button
                v-if="activeFilters.length > 1"
                variant="ghost"
                size="sm"
                :text="__('Clear')"
                @click="clearAllFilters"
            />
        </div>

        <Stack
            v-if="scoringEnabled"
            v-model:open="showScoreFilter"
            size="narrow"
            :title="__('leadhub::contacts.index.filter_score')"
            icon="sliders-horizontal"
        >
            <Panel>
                <PanelHeader class="flex items-center justify-between">
                    <Heading :text="__('leadhub::contacts.index.filter_score')" />
                    <Button
                        v-if="scoreFilterActive"
                        size="sm"
                        :text="__('leadhub::contacts.index.filter_score_reset')"
                        @click="resetScoreFilter"
                    />
                </PanelHeader>
                <Card class="space-y-3">
                    <div class="flex items-end gap-2">
                        <Field :label="__('leadhub::contacts.index.filter_score_min')" class="flex-1">
                            <Input v-model="scoreMin" type="number" />
                        </Field>
                        <Field :label="__('leadhub::contacts.index.filter_score_max')" class="flex-1">
                            <Input v-model="scoreMax" type="number" />
                        </Field>
                    </div>
                    <Button
                        :text="__('leadhub::contacts.index.sort_by_score')"
                        :icon="scoreSort === 'asc' ? 'arrow-up' : 'arrow-down'"
                        size="sm"
                        @click="sortByScore"
                    />
                </Card>
            </Panel>

            <Button variant="primary" :text="__('Done')" @click="applyScoreFilter" />
        </Stack>

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
                <span class="text-gray-900 dark:text-gray-300">{{ row.email }}</span>
            </template>

            <!-- Status and score are states, so they are pills; tags are
                 chips and stay square (pages/collections/Index.vue). -->
            <template #cell-status="{ row }">
                <Badge pill :color="statusColor(row.status)" :text="row.status_label" />
            </template>

            <template #cell-engagement_score="{ row }">
                <Badge pill :color="scoreColor(row.engagement_score)" :text="String(row.engagement_score)" />
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
                    pill
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
                    icon="package-box-crate"
                    @click="archive(row)"
                />
                <DropdownItem
                    v-if="row.can_archive && row.archived_at"
                    :text="__('Restore')"
                    icon="history"
                    @click="restore(row)"
                />
            </template>
        </Listing>
    </div>
</template>
