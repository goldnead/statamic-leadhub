<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, PanelHeader, Card, Heading, Stack, Listing, Badge, Button,
    Field, Input, Switch, DropdownItem, ConfirmationModal, Text,
} from '@statamic/cms/ui';

const props = defineProps([
    'rules',              // [{ id, event_type, is_catch_all, points, label, enabled, update_url, delete_url }]
    'columns',
    'canManage',          // bool
    'storeUrl',           // string
    'catchAll',           // '*'
    'configDefault',      // int
    'usingConfigFallback',// bool — no rule in this brand yet
    'configRules',        // [{ event_type, points }] — what config/leadhub.php still says
    'importCommand',      // string
    'knownEventTypes',    // [string]
]);

// Create and edit share one Stack, the way tags and custom fields do. Both
// used to be inline forms — one above the table, one inside the row — which
// the CP does nowhere, and the edit form was reachable only through the "…"
// menu.
//
// `errors` + onError + `Field :error` is the pattern this addon has used since
// v1.5.0: a rejected input must say what was wrong at the field that was
// wrong, not look like a dead button.
const open = ref(false);
const editing = ref(null);
const form = ref({ event_type: '', points: 1, label: '' });
const errors = ref({});
const saving = ref(false);

const ruleToDelete = ref(null);
const isLastRule = computed(() => props.rules.length === 1);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function startCreate(eventType = '') {
    editing.value = null;
    form.value = { event_type: eventType, points: 1, label: '' };
    errors.value = {};
    open.value = true;
}

function startEdit(row) {
    editing.value = row;
    form.value = { event_type: row.event_type, points: row.points, label: row.label ?? '' };
    errors.value = {};
    open.value = true;
}

function save() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};

    const payload = {
        event_type: form.value.event_type,
        points: form.value.points,
        label: form.value.label,
    };

    const done = {
        preserveScroll: true,
        onSuccess: () => { open.value = false; reloadPage(); },
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { saving.value = false; },
    };

    editing.value
        ? router.patch(editing.value.update_url, payload, done)
        : router.post(props.storeUrl, { ...payload, enabled: true }, done);
}

/** Which known activity types have no rule yet — those are worth offering. */
const unusedEventTypes = computed(() => {
    const taken = (props.rules || []).map((r) => r.event_type);
    return (props.knownEventTypes || []).filter((t) => ! taken.includes(t));
});

function toggleEnabled(row, enabled) {
    router.patch(row.update_url, { enabled }, { preserveScroll: true });
}

function destroy() {
    if (! ruleToDelete.value) return;
    router.delete(ruleToDelete.value.delete_url, {
        preserveScroll: true,
        onFinish: () => { ruleToDelete.value = null; },
    });
}

function pointsLabel(points) {
    return points > 0 ? `+${points}` : String(points);
}
</script>

<template>
    <Head :title="[__('leadhub::scoring.title'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="__('leadhub::scoring.title')" icon="chart-monitoring-indicator">
            <Button
                v-if="canManage"
                :text="__('leadhub::scoring.create')"
                icon="plus"
                variant="primary"
                data-leadhub-new-scoring-rule
                @click="startCreate()"
            />
        </Header>

        <p class="text-sm text-gray-500 dark:text-gray-400 -mt-4 mb-4">
            {{ __('leadhub::scoring.intro') }}
        </p>

        <!--
            No rules yet: the config file is still deciding. Saying nothing here
            would let an empty list read as "nothing is being scored", when the
            opposite is true and the numbers below are live.
        -->
        <Panel v-if="usingConfigFallback" class="mb-4">
            <Card>
                <div class="text-sm">
                    <p class="font-medium">{{ __('leadhub::scoring.fallback_notice_title') }}</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">
                        {{ __('leadhub::scoring.fallback_notice_body', { command: importCommand }) }}
                    </p>
                    <div class="mt-3">
                        <Text size="xs" variant="subtle">{{ __('leadhub::scoring.config_table') }}</Text>
                        <ul class="mt-1 space-y-0.5">
                            <li v-for="row in configRules" :key="row.event_type" class="flex justify-between max-w-sm">
                                <code class="text-xs">{{ row.event_type }}</code>
                                <span class="text-xs">{{ pointsLabel(row.points) }}</span>
                            </li>
                            <li class="flex justify-between max-w-sm border-t border-content-border mt-1 pt-1">
                                <span class="text-xs">{{ __('leadhub::scoring.catch_all') }}</span>
                                <span class="text-xs">{{ pointsLabel(configDefault) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </Card>
        </Panel>

        <!--
            Which activity types exist at all. The list was already handed down
            from the server and then hidden in a `<datalist>` — invisible until
            somebody typed the first letters of a handle they had no way of
            knowing. Clicking one starts a rule for it.
        -->
        <Panel
            v-if="canManage && unusedEventTypes.length"
            class="mb-4"
            :heading="__('leadhub::scoring.known_types')"
        >
            <Card class="flex flex-wrap gap-1.5">
                <Button
                    v-for="type in unusedEventTypes"
                    :key="type"
                    size="xs"
                    variant="filled"
                    :text="type"
                    data-leadhub-known-event-type
                    @click="startCreate(type)"
                />
            </Card>
        </Panel>

        <Listing
            :items="rules"
            :columns="columns"
            preferences-prefix="leadhub.scoring"
            @refreshing="reloadPage"
        >
            <!-- Double-click anywhere on the type or the description opens the
                 editor; the "…" menu was the only way in before. -->
            <template #cell-event_type="{ row }">
                <div
                    class="cursor-pointer"
                    data-leadhub-scoring-rule
                    @dblclick="canManage && startEdit(row)"
                >
                    <Badge v-if="row.is_catch_all" color="purple" pill :text="__('leadhub::scoring.catch_all')" />
                    <code v-else class="text-xs">{{ row.event_type }}</code>
                </div>
            </template>

            <template #cell-label="{ row }">
                <span
                    class="text-sm text-gray-600 dark:text-gray-400 cursor-pointer"
                    @dblclick="canManage && startEdit(row)"
                >
                    {{ row.label || (row.is_catch_all ? __('leadhub::scoring.catch_all_hint') : '—') }}
                </span>
            </template>

            <template #cell-points="{ row }">
                <Badge
                    pill
                    :color="row.points > 0 ? 'green' : (row.points < 0 ? 'red' : 'default')"
                    :text="pointsLabel(row.points)"
                />
            </template>

            <template #cell-enabled="{ row }">
                <Switch
                    :model-value="row.enabled"
                    :disabled="!canManage"
                    @update:model-value="(value) => toggleEnabled(row, value)"
                />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="canManage"
                    :text="__('leadhub::scoring.edit')"
                    icon="pencil"
                    @click="startEdit(row)"
                />
                <DropdownItem
                    v-if="canManage"
                    :text="__('leadhub::scoring.delete')"
                    icon="trash"
                    variant="destructive"
                    @click="ruleToDelete = row"
                />
            </template>
        </Listing>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('leadhub::scoring.disabled_hint') }} {{ __('leadhub::scoring.no_recompute') }}
        </p>

        <Stack
            v-model:open="open"
            size="narrow"
            :title="editing ? __('leadhub::scoring.edit') : __('leadhub::scoring.create')"
            icon="chart-monitoring-indicator"
        >
            <Panel>
                <PanelHeader>
                    <Heading :text="editing ? (editing.event_type || __('leadhub::scoring.catch_all')) : __('leadhub::scoring.new_rule')" />
                </PanelHeader>
                <Card class="space-y-4">
                    <Field
                        :label="__('leadhub::scoring.event_type')"
                        :instructions="__('leadhub::scoring.event_type_placeholder')"
                        :error="errors.event_type"
                        required
                    >
                        <Input v-model="form.event_type" :disabled="editing && editing.is_catch_all" />
                    </Field>
                    <Field :label="__('leadhub::scoring.label')" :error="errors.label">
                        <Input v-model="form.label" :placeholder="__('leadhub::scoring.label_placeholder')" />
                    </Field>
                    <Field :label="__('leadhub::scoring.points')" :error="errors.points">
                        <Input v-model="form.points" type="number" class="w-32" />
                    </Field>
                </Card>
            </Panel>

            <div class="flex gap-2">
                <Button
                    variant="primary"
                    :text="__('leadhub::scoring.save')"
                    :loading="saving"
                    :disabled="!String(form.event_type || '').trim()"
                    @click="save"
                />
                <Button variant="ghost" :text="__('leadhub::scoring.cancel')" @click="open = false" />
            </div>
        </Stack>

        <ConfirmationModal
            :open="ruleToDelete !== null"
            :title="__('leadhub::scoring.delete_title')"
            :body-text="isLastRule
                ? __('leadhub::scoring.delete_body_last')
                : __('leadhub::scoring.delete_body')"
            danger
            :button-text="__('leadhub::scoring.delete')"
            @cancel="ruleToDelete = null"
            @confirm="destroy"
        />
    </div>
</template>
