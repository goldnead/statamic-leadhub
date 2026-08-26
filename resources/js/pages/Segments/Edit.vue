<script setup>
import axios from 'axios';
import { ref, reactive, watch, computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Badge, Button, Field, Input, Select, Switch } from '@statamic/cms/ui';

const props = defineProps([
    'segment',      // null on create, else { id, name, handle, description, is_active, rules, update_url, delete_url }
    'storeUrl',     // present on create
    'previewUrl',
    'indexUrl',
    'vocabulary',   // { fields, field_operators, custom_fields, tag_operators, event_operators, statuses }
]);

const isEdit = computed(() => !! props.segment);

const form = reactive({
    name: props.segment?.name ?? '',
    handle: props.segment?.handle ?? '',
    description: props.segment?.description ?? '',
    is_active: props.segment ? !! props.segment.is_active : true,
    rules: normalizeRules(props.segment?.rules),
});

function normalizeRules(rules) {
    if (! rules || typeof rules !== 'object') {
        return { match: 'all', conditions: [] };
    }
    return {
        match: rules.match === 'any' ? 'any' : 'all',
        conditions: Array.isArray(rules.conditions) ? rules.conditions.map(normalizeCondition) : [],
    };
}

function normalizeCondition(c) {
    const type = c.type ?? 'field';
    if (type === 'tag') return { type: 'tag', operator: c.operator ?? 'has', value: c.value ?? '' };
    if (type === 'event') return { type: 'event', operator: c.operator ?? 'has', event: c.event ?? '', within_days: c.within_days ?? null };
    return { type: 'field', field: c.field ?? props.vocabulary.fields[0], operator: c.operator ?? 'eq', value: c.value ?? '' };
}

function addFieldCondition() {
    form.rules.conditions.push({ type: 'field', field: props.vocabulary.fields[0], operator: 'eq', value: '' });
}

function customFieldByHandle(handle) {
    return (props.vocabulary.custom_fields || []).find((f) => f.handle === handle);
}

function operatorsFor(handle) {
    return customFieldByHandle(handle)?.operators ?? [];
}

function optionsFor(handle) {
    return customFieldByHandle(handle)?.options ?? [];
}

// The operator list changes with the field, so an operator that made sense for
// the old one must not survive the switch — it would be saved, evaluated as
// unknown, and match nobody.
function onCustomFieldChange(condition) {
    const erlaubt = operatorsFor(condition.field);

    if (!erlaubt.includes(condition.operator)) {
        condition.operator = erlaubt[0] ?? 'eq';
    }

    condition.value = '';
}

function addCustomCondition() {
    const first = (props.vocabulary.custom_fields || [])[0];

    if (!first) {
        return;
    }

    form.rules.conditions.push({
        type: 'custom',
        field: first.handle,
        operator: first.operators[0] ?? 'eq',
        value: '',
    });
}
function addTagCondition() {
    form.rules.conditions.push({ type: 'tag', operator: 'has', value: '' });
}
function addEventCondition() {
    form.rules.conditions.push({ type: 'event', operator: 'has', event: '', within_days: null });
}
function removeCondition(index) {
    form.rules.conditions.splice(index, 1);
}

// -- Live member-count preview (debounced) --
const previewCount = ref(null);
const previewing = ref(false);
let previewTimer = null;

function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(runPreview, 400);
}

function runPreview() {
    previewing.value = true;
    // A read, so a GET. The endpoint answers JSON rather than an Inertia page,
    // which rules out router.get(); axios on a GET does not touch Inertia's
    // progress bar, toasts or dirty-state guard, so nothing is bypassed.
    axios.get(props.previewUrl, { params: { rules: form.rules } })
        .then((res) => { previewCount.value = res.data.count; })
        .catch(() => { previewCount.value = null; })
        .finally(() => { previewing.value = false; });
}

watch(() => form.rules, schedulePreview, { deep: true });

function submit() {
    if (! form.name.trim()) return;
    const payload = {
        name: form.name,
        handle: form.handle || null,
        description: form.description || null,
        is_active: form.is_active,
        rules: form.rules,
    };
    if (isEdit.value) {
        router.patch(props.segment.update_url, payload, { preserveScroll: true });
    } else {
        router.post(props.storeUrl, payload);
    }
}
</script>

<template>
    <Head :title="[isEdit ? __('Edit segment') : __('New segment'), __('LeadHub')]" />

    <div class="max-w-page mx-auto">
        <Header :title="isEdit ? __('Edit segment') : __('New segment')" icon="tags">
            <Button :href="indexUrl" :text="__('Back')" variant="ghost" />
            <Button :text="__('Save')" variant="primary" :disabled="!form.name.trim()" @click="submit" />
        </Header>

        <Panel class="mb-4" :heading="__('Details')">
            <Card>
                <div class="space-y-6 p-1">
                    <Field :label="__('Name')">
                        <Input v-model="form.name" :placeholder="__('e.g. Engaged buyers')" />
                    </Field>
                    <Field :label="__('Handle')" :instructions="__('Stable identifier used by consumers. Leave empty to derive from the name.')">
                        <Input v-model="form.handle" :placeholder="__('engaged-buyers')" />
                    </Field>
                    <Field :label="__('Description')">
                        <Input v-model="form.description" />
                    </Field>
                    <Field :label="__('Active')" :instructions="__('Only active segments are evaluated and exposed to consumers.')">
                        <Switch v-model="form.is_active" />
                    </Field>
                </div>
            </Card>
        </Panel>

        <Panel class="mb-4" :heading="__('Rules')">
            <Card>
                <div class="space-y-4 p-1">
                    <Field :label="__('Match')" :instructions="__('Whether a contact must satisfy all or any of the conditions below.')">
                        <Select
                            v-model="form.rules.match"
                            class="w-56"
                            :options="[{ value: 'all', label: __('all conditions') }, { value: 'any', label: __('any condition') }]"
                        />
                    </Field>

                    <p v-if="!form.rules.conditions.length" class="text-sm text-gray-500 dark:text-gray-400 italic">
                        {{ __('No conditions yet — every contact matches. Add a condition below to narrow the segment.') }}
                    </p>

                    <div
                        v-for="(condition, index) in form.rules.conditions"
                        :key="index"
                        class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3 space-y-3"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <Badge :text="condition.type" color="blue" />
                            <Button icon="trash" size="sm" variant="subtle" :aria-label="__('Remove condition')" @click="removeCondition(index)" />
                        </div>

                        <div class="flex flex-wrap items-end gap-3">
                            <!-- field condition -->
                            <template v-if="condition.type === 'field'">
                                <Field :label="__('Field')" class="min-w-[10rem]">
                                    <Select v-model="condition.field" class="w-full" :options="vocabulary.fields.map(f => ({ value: f, label: f }))" />
                                </Field>
                                <Field :label="__('Operator')">
                                    <Select v-model="condition.operator" :options="vocabulary.field_operators.map(o => ({ value: o, label: o }))" />
                                </Field>
                                <Field :label="__('Value')" class="flex-1 min-w-[8rem]">
                                    <Input v-model="condition.value" />
                                </Field>
                            </template>

                            <!-- custom field condition -->
                            <template v-else-if="condition.type === 'custom'">
                                <Field :label="__('Field')" class="min-w-[10rem]">
                                    <Select
                                        v-model="condition.field"
                                        class="w-full"
                                        :options="vocabulary.custom_fields.map(f => ({ value: f.handle, label: f.label }))"
                                        @update:model-value="onCustomFieldChange(condition)"
                                    />
                                </Field>
                                <Field :label="__('Operator')">
                                    <!-- Only the comparisons this type can answer. A date
                                         offering 'contains' or a yes/no offering 'greater
                                         than' lets somebody write a condition that can
                                         never be true, with nothing saying so. -->
                                    <Select
                                        v-model="condition.operator"
                                        :options="operatorsFor(condition.field).map(o => ({ value: o, label: o }))"
                                    />
                                </Field>
                                <Field
                                    v-if="!['is_set', 'is_empty', 'is_true', 'is_false'].includes(condition.operator)"
                                    :label="__('Value')"
                                    class="flex-1 min-w-[8rem]"
                                >
                                    <Select
                                        v-if="optionsFor(condition.field).length"
                                        v-model="condition.value"
                                        class="w-full"
                                        :options="optionsFor(condition.field).map(o => ({ value: o.value, label: o.label || o.value }))"
                                    />
                                    <Input v-else v-model="condition.value" />
                                </Field>
                            </template>

                            <!-- tag condition -->
                            <template v-else-if="condition.type === 'tag'">
                                <Field :label="__('Operator')">
                                    <Select v-model="condition.operator" :options="vocabulary.tag_operators.map(o => ({ value: o, label: o }))" />
                                </Field>
                                <Field :label="__('Tag (id, slug or name)')" class="flex-1 min-w-[10rem]">
                                    <Input v-model="condition.value" />
                                </Field>
                            </template>

                            <!-- event condition -->
                            <template v-else-if="condition.type === 'event'">
                                <Field :label="__('Operator')">
                                    <Select v-model="condition.operator" :options="vocabulary.event_operators.map(o => ({ value: o, label: o }))" />
                                </Field>
                                <Field :label="__('Event key')" class="flex-1 min-w-[8rem]">
                                    <Input v-model="condition.event" :placeholder="__('e.g. purchase')" />
                                </Field>
                                <Field :label="__('Within days (optional)')">
                                    <Input v-model.number="condition.within_days" type="number" min="0" />
                                </Field>
                            </template>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-1">
                        <Button :text="__('Add field condition')" icon="add" size="sm" variant="default" @click="addFieldCondition" />
                        <Button
                            v-if="vocabulary.custom_fields.length"
                            :text="__('Add custom field condition')"
                            icon="add"
                            size="sm"
                            variant="default"
                            @click="addCustomCondition"
                        />
                        <Button :text="__('Add tag condition')" icon="add" size="sm" variant="default" @click="addTagCondition" />
                        <Button :text="__('Add event condition')" icon="add" size="sm" variant="default" @click="addEventCondition" />
                    </div>
                </div>
            </Card>
        </Panel>

        <Panel :heading="__('Matching contacts')">
            <Card>
                <div class="flex items-center gap-3 p-1">
                    <Badge
                        :color="previewing ? 'default' : 'green'"
                        :text="previewing ? '…' : (previewCount === null ? '—' : String(previewCount))"
                    />
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('contacts currently match these rules') }}</span>
                </div>
            </Card>
        </Panel>
    </div>
</template>
