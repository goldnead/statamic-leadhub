<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Listing, Badge, Button, Field, Input, Switch,
    DropdownItem, ConfirmationModal, Text,
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

// Create form. `errors` + onError + `Field :error` is the pattern this addon
// has used since v1.5.0: a rejected input must say what was wrong at the field
// that was wrong, not look like a dead button.
const newRule = ref({ event_type: '', points: 1, label: '' });
const errors = ref({});

// Inline edit. One row at a time, because the edited value is the whole record —
// a separate screen for three fields would be more navigation than content.
const editingId = ref(null);
const editRule = ref({ event_type: '', points: 0, label: '' });
const editErrors = ref({});

const ruleToDelete = ref(null);
const isLastRule = computed(() => props.rules.length === 1);

function reloadPage() {
    router.reload({ preserveScroll: true });
}

function create() {
    errors.value = {};
    router.post(props.storeUrl, {
        event_type: newRule.value.event_type,
        points: newRule.value.points,
        label: newRule.value.label,
        enabled: true,
    }, {
        preserveScroll: true,
        onSuccess: () => { newRule.value = { event_type: '', points: 1, label: '' }; },
        onError: (e) => { errors.value = e; },
    });
}

function startEdit(row) {
    editingId.value = row.id;
    editErrors.value = {};
    editRule.value = { event_type: row.event_type, points: row.points, label: row.label ?? '' };
}

function cancelEdit() {
    editingId.value = null;
    editErrors.value = {};
}

function saveEdit(row) {
    editErrors.value = {};
    router.patch(row.update_url, {
        event_type: editRule.value.event_type,
        points: editRule.value.points,
        label: editRule.value.label,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
        onError: (e) => { editErrors.value = e; },
    });
}

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
        <Header :title="__('leadhub::scoring.title')" icon="chart-bar" />

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

        <Panel v-if="canManage" class="mb-4">
            <div class="p-4 flex flex-col sm:flex-row gap-3 items-start sm:items-end">
                <Field
                    :label="__('leadhub::scoring.event_type')"
                    :error="errors.event_type"
                    class="flex-1 min-w-48"
                >
                    <Input
                        v-model="newRule.event_type"
                        :placeholder="__('leadhub::scoring.event_type_placeholder')"
                        list="leadhub-known-event-types"
                    />
                </Field>
                <Field :label="__('leadhub::scoring.label')" :error="errors.label" class="flex-1 min-w-48">
                    <Input v-model="newRule.label" :placeholder="__('leadhub::scoring.label_placeholder')" />
                </Field>
                <Field :label="__('leadhub::scoring.points')" :error="errors.points">
                    <Input v-model="newRule.points" type="number" class="w-24" />
                </Field>
                <Button
                    :text="__('leadhub::scoring.create')"
                    variant="primary"
                    :disabled="!String(newRule.event_type).trim()"
                    @click="create"
                />
            </div>

            <datalist id="leadhub-known-event-types">
                <option v-for="type in knownEventTypes" :key="type" :value="type" />
            </datalist>
        </Panel>

        <Listing
            :items="rules"
            :columns="columns"
            preferences-prefix="leadhub.scoring"
            @refreshing="reloadPage"
        >
            <template #cell-event_type="{ row }">
                <div v-if="editingId === row.id">
                    <Field :error="editErrors.event_type">
                        <Input v-model="editRule.event_type" />
                    </Field>
                </div>
                <div v-else>
                    <Badge v-if="row.is_catch_all" color="purple" :text="__('leadhub::scoring.catch_all')" />
                    <code v-else class="text-xs">{{ row.event_type }}</code>
                </div>
            </template>

            <template #cell-label="{ row }">
                <Field v-if="editingId === row.id" :error="editErrors.label">
                    <Input v-model="editRule.label" />
                </Field>
                <span v-else class="text-sm text-gray-600 dark:text-gray-400">
                    {{ row.label || (row.is_catch_all ? __('leadhub::scoring.catch_all_hint') : '—') }}
                </span>
            </template>

            <template #cell-points="{ row }">
                <Field v-if="editingId === row.id" :error="editErrors.points">
                    <Input v-model="editRule.points" type="number" class="w-24" />
                </Field>
                <Badge v-else :color="row.points > 0 ? 'green' : (row.points < 0 ? 'red' : 'default')" :text="pointsLabel(row.points)" />
            </template>

            <template #cell-enabled="{ row }">
                <div class="flex items-center gap-2">
                    <Switch
                        :model-value="row.enabled"
                        :disabled="!canManage || editingId === row.id"
                        @update:model-value="(value) => toggleEnabled(row, value)"
                    />
                    <span v-if="editingId === row.id" class="flex gap-1">
                        <Button size="sm" variant="primary" :text="__('leadhub::scoring.save')" @click="saveEdit(row)" />
                        <Button size="sm" variant="ghost" :text="__('leadhub::scoring.cancel')" @click="cancelEdit" />
                    </span>
                </div>
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
                    @click="ruleToDelete = row"
                />
            </template>
        </Listing>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('leadhub::scoring.disabled_hint') }} {{ __('leadhub::scoring.no_recompute') }}
        </p>

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
